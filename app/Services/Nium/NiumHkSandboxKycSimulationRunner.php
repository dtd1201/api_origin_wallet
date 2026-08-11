<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class NiumHkSandboxKycSimulationRunner
{
    private const ACCOUNT_ID = 7;

    private const PROTECTED_ACCOUNT_ID = 4;

    private const APPLICANT_REFERENCE = 'c620e0e9-ab0a-43bd-aa10-44f573db723a';

    private const STAKEHOLDER_REFERENCE = '7609d9d1-9d37-4e08-9197-602d792f7a2e';

    private const OPERATION = 'onboarding_simulation_submit_kyc';

    private const ACCEPTED_STATES = [
        'provider_accepted_200_sandbox_review',
        'response_review',
        'initiated',
    ];

    public function __construct(private readonly NiumService $niumService) {}

    public function run(): array
    {
        $context = $this->preflight();
        $protectedFingerprint = $this->fingerprint($context['protected_account']);
        $logMaxId = (int) (ApiRequestLog::query()->max('id') ?? 0);
        $this->claim();

        try {
            $response = $this->niumService->postOnboardingSimulation(
                path: $this->niumService->path(
                    (string) config('services.nium.customer_onboarding_simulation_endpoint'),
                    ['customer' => $context['account']->external_customer_id],
                ),
                payload: ['nextAction' => 'submit_kyc'],
                user: $context['user'],
                externalReference: $context['account']->external_customer_id,
                clientName: $context['client_name'],
            );
        } catch (ConnectionException|NiumEvidencePersistenceException) {
            return $this->finish('STOP_SIMULATION_OUTCOME_UNKNOWN_NO_RETRY', $context, $logMaxId, $protectedFingerprint);
        } catch (Throwable) {
            return $this->finish('STOP_SIMULATION_OUTCOME_UNKNOWN_NO_RETRY', $context, $logMaxId, $protectedFingerprint);
        }

        $body = $this->responseObject($response);
        $terminal = match (true) {
            $response->status() >= 400 => 'STOP_SIMULATION_REJECTED_NO_RETRY',
            $response->successful()
                && $response->status() === 200
                && ($body['message'] ?? null) === 'KYC submitted successfully.' => 'PASS_SIMULATION_HTTP_200_WAIT_FOR_WEBHOOK',
            default => 'HOLD_RESPONSE_REVIEW',
        };

        return $this->finish(
            $terminal,
            $context,
            $logMaxId,
            $protectedFingerprint,
        );
    }

    private function preflight(): array
    {
        $this->assertSandboxBaseUrl();
        $clientName = $this->clientName();
        $provider = IntegrationProvider::query()->whereRaw('LOWER(code) = ?', ['nium'])->sole();
        $account = UserProviderAccount::query()->whereKey(self::ACCOUNT_ID)->where('provider_id', $provider->id)->firstOrFail();
        $user = User::query()->findOrFail($account->user_id);
        $protectedAccount = UserProviderAccount::query()->findOrFail(self::PROTECTED_ACCOUNT_ID);

        if (! filled($account->external_customer_id)) {
            throw new RuntimeException('Account 7 customerHashId is required for sandbox simulation.');
        }

        $customerEvent = WebhookEvent::query()
            ->where('provider_id', $provider->id)
            ->where('event_type', 'CUSTOMER_STATUS_WEBHOOK')
            ->where('external_resource_id', $account->external_customer_id)
            ->where('processing_status', 'processed')
            ->whereNotNull('processed_at')
            ->orderByDesc('processed_at')
            ->orderByDesc('id')
            ->first();
        $customerPayload = (array) ($customerEvent?->payload ?? []);
        if (($customerPayload['status'] ?? null) !== 'pending'
            || ($customerPayload['subStatus'] ?? null) !== 'awaiting_kyc') {
            throw new RuntimeException('Latest raw customer webhook is not pending awaiting_kyc.');
        }

        $this->assertEntityEvidence($account, (int) $provider->id, self::APPLICANT_REFERENCE, true);
        $this->assertEntityEvidence($account, (int) $provider->id, self::STAKEHOLDER_REFERENCE, false);

        if (ApiRequestLog::query()
            ->where('provider_id', $provider->id)
            ->where('user_id', $user->id)
            ->where('operation', self::OPERATION)
            ->where('request_method', 'POST')
            ->where('external_reference', $account->external_customer_id)
            ->exists()) {
            throw new RuntimeException('HOLD_SIMULATION_ALREADY_PROCESSED');
        }

        if (Arr::has((array) $account->metadata, 'nium_sandbox_simulation_submit_kyc_attempt')) {
            throw new RuntimeException('HOLD_SIMULATION_ALREADY_PROCESSED');
        }

        return [
            'account' => $account,
            'user' => $user,
            'protected_account' => $protectedAccount,
            'provider_id' => (int) $provider->id,
            'user_id' => (int) $user->id,
            'external_reference' => (string) $account->external_customer_id,
            'client_name' => $clientName,
        ];
    }

    private function claim(): void
    {
        DB::transaction(function (): void {
            $account = UserProviderAccount::query()->whereKey(self::ACCOUNT_ID)->lockForUpdate()->firstOrFail();
            $metadata = (array) $account->metadata;

            if (Arr::has($metadata, 'nium_sandbox_simulation_submit_kyc_attempt')) {
                throw new RuntimeException('HOLD_SIMULATION_ALREADY_PROCESSED');
            }

            $metadata['nium_sandbox_simulation_submit_kyc_attempt'] = [
                'state' => 'submitting',
                'updated_at' => now()->toISOString(),
            ];
            $account->forceFill(['metadata' => $metadata])->save();
        });
    }

    private function assertEntityEvidence(
        UserProviderAccount $account,
        int $providerId,
        string $referenceId,
        bool $applicant,
    ): void {
        $logs = ApiRequestLog::query()
            ->where('provider_id', $providerId)
            ->where('user_id', $account->user_id)
            ->where('operation', 'submit_kyc')
            ->where('request_method', 'POST')
            ->where('external_reference', $referenceId)
            ->where('response_status', 200)
            ->where('is_success', true)
            ->where('transport_outcome', 'response_received');

        if ($applicant) {
            $logs->whereKey(104);
        }

        if (! $logs->exists()) {
            throw new RuntimeException('Exact entity Submit KYC HTTP-200 evidence is required.');
        }

        $key = 'ref_'.substr(hash('sha256', $referenceId), 0, 16);
        $state = Arr::get((array) $account->metadata, 'nium_submit_kyc_attempts.'.$key.'.state');
        $acceptedStates = $applicant
            ? ['provider_accepted_200_sandbox_review', 'initiated']
            : self::ACCEPTED_STATES;
        if (! in_array($state, $acceptedStates, true)) {
            throw new RuntimeException('Entity Submit KYC attempt does not have accepted evidence.');
        }
    }

    private function assertSandboxBaseUrl(): void
    {
        $parts = parse_url(trim((string) config('services.nium.base_url')));
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || $host === ''
            || ! str_contains($host, 'sandbox')
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw new RuntimeException('Nium sandbox simulation requires an HTTPS sandbox base URL.');
        }
    }

    private function clientName(): string
    {
        $value = config('services.nium.client_name');

        if (! is_string($value)
            || trim($value) === ''
            || strlen(trim($value)) > 32
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new RuntimeException('Nium sandbox simulation client name is missing or invalid.');
        }

        return trim($value);
    }

    private function finish(string $terminal, array $context, int $logMaxId, string $protectedFingerprint): array
    {
        $logs = ApiRequestLog::query()
            ->where('id', '>', $logMaxId)
            ->where('provider_id', $context['provider_id'])
            ->where('user_id', $context['user_id'])
            ->where('operation', self::OPERATION)
            ->where('request_method', 'POST')
            ->where('external_reference', $context['external_reference'])
            ->get();
        $posts = $logs->count();
        $passEvidence = $posts === 1
            && (int) $logs->first()->response_status === 200
            && $logs->first()->is_success === true
            && $logs->first()->transport_outcome === 'response_received';

        if ($posts > 1
            || ($terminal === 'PASS_SIMULATION_HTTP_200_WAIT_FOR_WEBHOOK' && ! $passEvidence)
            || $this->fingerprint(UserProviderAccount::query()->findOrFail(self::PROTECTED_ACCOUNT_ID)) !== $protectedFingerprint) {
            throw new RuntimeException('Sandbox simulation one-shot postcondition failed closed.');
        }

        return [
            'terminal' => $terminal,
            'simulation_post_count' => $posts,
        ];
    }

    private function responseObject(Response $response): array
    {
        try {
            $body = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($body) && ! array_is_list($body) ? $body : [];
    }

    private function fingerprint(UserProviderAccount $account): string
    {
        $attributes = $account->getRawOriginal();
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
    }
}
