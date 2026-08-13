<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class NiumHkKycPrebuiltFormSessionRunner
{
    private const ACCOUNT_ID = 7;
    private const PROTECTED_ACCOUNT_ID = 4;
    private const USER_ID = 9;
    private const CLAIM_KEY = 'nium_kyc_prebuilt_form_session';
    private const G6_CLAIM_KEY = 'nium_stakeholder_submit_kyc_retry_generation_6';
    private const STAKEHOLDER_REFERENCE = '7609d9d1-9d37-4e08-9197-602d792f7a2e';

    public function __construct(private readonly NiumService $niumService) {}

    public function audit(): array
    {
        $context = $this->preflight();
        $this->assertProtectedAccount($context['protected_fingerprint']);

        return [
            'terminal' => 'READY_FOR_SEPARATE_HUMAN_APPROVAL',
            'feature_type' => $context['payload']['featureType'],
            'integration_type' => $context['payload']['integrationType'],
            'rolling_duration_minutes' => $context['payload']['rollingDurationMinutes'],
            'on_behalf' => $context['payload']['onBehalf'],
            'expiry_future' => CarbonImmutable::parse($context['payload']['expiry'])->isFuture(),
            'customer_hash_id_present' => filled($context['payload']['customerHashId']),
            'session_id_present' => false,
            'session_id_fingerprint' => null,
            'latest_customer_status' => $context['customer_status'],
            'latest_customer_substatus' => $context['customer_substatus'],
            'latest_entity_kyc_states' => $context['entity_states'],
            'session_post_count' => 0,
            'submit_kyc_post_count' => 5,
            'db_write_count' => 0,
        ];
    }

    public function run(bool $separateHumanApproval = false): array
    {
        if (! $separateHumanApproval) {
            throw new RuntimeException('Separate human approval is required outside the pre-built form session runner.');
        }

        $context = $this->preflight();
        $logMaxId = (int) (ApiRequestLog::query()->max('id') ?? 0);
        $this->claim($context['payload']['expiry']);

        try {
            $response = $this->niumService->post(
                path: $this->niumService->path(
                    (string) config('services.nium.kyc_form_session_endpoint'),
                    ['client' => $this->niumService->clientId()],
                ),
                payload: $context['payload'],
                user: $context['user'],
                operation: 'kyc_form_session_create',
                externalReference: self::STAKEHOLDER_REFERENCE,
            );
        } catch (ConnectionException|NiumEvidencePersistenceException) {
            $this->mark('unknown');
            return $this->finish('STOP_SESSION_OUTCOME_UNKNOWN_NO_RETRY', $context, $logMaxId);
        } catch (Throwable) {
            $this->mark('unknown');
            return $this->finish('STOP_SESSION_OUTCOME_UNKNOWN_NO_RETRY', $context, $logMaxId);
        }

        if (! $response->successful()) {
            $this->mark('rejected', $response->status());
            return $this->finish('STOP_SESSION_REJECTED_NO_RETRY', $context, $logMaxId);
        }

        $sessionId = $this->sessionId($response);
        if ($sessionId === null) {
            $this->mark('response_review', $response->status());
            return $this->finish('HOLD_SESSION_RESPONSE_REVIEW', $context, $logMaxId);
        }

        $fingerprint = substr(hash('sha256', $sessionId), 0, 16);
        $this->mark('created', $response->status(), $fingerprint);
        $result = $this->finish('PASS_SESSION_CREATED', $context, $logMaxId);

        return [...$result, 'sessionId' => $sessionId, 'session_id_present' => true, 'session_id_fingerprint' => $fingerprint];
    }

    private function preflight(): array
    {
        $provider = IntegrationProvider::query()->whereRaw('LOWER(code) = ?', ['nium'])->sole();
        $account = UserProviderAccount::query()->whereKey(self::ACCOUNT_ID)
            ->where('provider_id', $provider->id)->where('user_id', self::USER_ID)->firstOrFail();
        $user = User::query()->findOrFail(self::USER_ID);
        $protected = UserProviderAccount::query()->findOrFail(self::PROTECTED_ACCOUNT_ID);
        $metadata = (array) $account->metadata;

        if (! filled($account->external_customer_id) || $account->reconciliation_status !== 'reconciled'
            || array_key_exists(self::G6_CLAIM_KEY, $metadata)) {
            throw new RuntimeException('Existing Account 7 is not eligible for the pre-built KYC form path.');
        }
        if (array_key_exists(self::CLAIM_KEY, $metadata)) {
            throw new RuntimeException('HOLD_KYC_PREBUILT_FORM_SESSION_ALREADY_CLAIMED');
        }

        $this->assertHistory((int) $provider->id);
        $status = $this->latestCustomerStatus((int) $provider->id, (string) $account->external_customer_id);
        if ($status['status'] !== 'pending' || ! in_array($status['substatus'], ['awaiting_kyc', 'under_review'], true)) {
            throw new RuntimeException('Current Nium customer status is not compatible with KYC completion.');
        }

        $expiry = now()->utc()->addMinutes(120)->startOfSecond()->toIso8601ZuluString();
        $payload = [
            'featureType' => 'kyc_form',
            'integrationType' => 'standalone',
            'expiry' => $expiry,
            'rollingDurationMinutes' => 120,
            'onBehalf' => false,
            'customerHashId' => (string) $account->external_customer_id,
        ];

        return [
            'account' => $account, 'user' => $user, 'provider_id' => (int) $provider->id, 'payload' => $payload,
            'protected_fingerprint' => $this->fingerprint($protected), 'customer_status' => $status['status'],
            'customer_substatus' => $status['substatus'], 'entity_states' => $this->latestEntityStates((int) $provider->id),
        ];
    }

    private function assertHistory(int $providerId): void
    {
        $applicant = ApiRequestLog::query()->findOrFail(104);
        if ((int) $applicant->provider_id !== $providerId || (int) $applicant->response_status !== 200
            || ! $applicant->is_success || $applicant->operation !== 'submit_kyc') {
            throw new RuntimeException('Applicant onboarding evidence #104 is invalid.');
        }
        $ids = ApiRequestLog::query()->where('provider_id', $providerId)->where('user_id', self::USER_ID)
            ->where('external_reference', self::STAKEHOLDER_REFERENCE)->where('operation', 'submit_kyc')
            ->where('request_method', 'POST')->orderBy('id')->pluck('id')->all();
        if ($ids !== [106, 113, 114, 115, 116]) {
            throw new RuntimeException('Historical stakeholder Submit KYC evidence is not locked.');
        }
    }

    private function latestCustomerStatus(int $providerId, string $customerId): array
    {
        $event = WebhookEvent::query()->where('provider_id', $providerId)->where('event_type', 'CUSTOMER_STATUS_WEBHOOK')
            ->where('external_resource_id', $customerId)->where('processing_status', 'processed')
            ->whereNotNull('processed_at')->orderByDesc('processed_at')->orderByDesc('id')->first();
        $payload = (array) ($event?->payload ?? []);

        return ['status' => $payload['status'] ?? null, 'substatus' => $payload['subStatus'] ?? null];
    }

    private function latestEntityStates(int $providerId): array
    {
        return WebhookEvent::query()->where('provider_id', $providerId)->where('event_type', 'CUSTOMER_ENTITY_KYC_STATUS')
            ->where('processing_status', 'processed')->whereNotNull('processed_at')->orderBy('id')->get()
            ->map(fn (WebhookEvent $event): array => array_filter([
                'entity_type' => $event->payload['entityType'] ?? null,
                'kyc_status' => $event->payload['kycStatus'] ?? null,
            ], static fn (mixed $value): bool => is_string($value) && $value !== ''))->all();
    }

    private function claim(string $expiry): void
    {
        DB::transaction(function () use ($expiry): void {
            $account = UserProviderAccount::query()->whereKey(self::ACCOUNT_ID)->lockForUpdate()->firstOrFail();
            $metadata = (array) $account->metadata;
            if (array_key_exists(self::G6_CLAIM_KEY, $metadata) || array_key_exists(self::CLAIM_KEY, $metadata)) {
                throw new RuntimeException('Pre-built KYC form session claim precondition failed.');
            }
            $metadata[self::CLAIM_KEY] = ['state' => 'submitting', 'created_at' => now()->toISOString(), 'expiry_at' => $expiry];
            $account->forceFill(['metadata' => $metadata])->save();
        });
    }

    private function mark(string $state, ?int $status = null, ?string $fingerprint = null): void
    {
        DB::transaction(function () use ($state, $status, $fingerprint): void {
            $account = UserProviderAccount::query()->whereKey(self::ACCOUNT_ID)->lockForUpdate()->firstOrFail();
            $metadata = (array) $account->metadata;
            $metadata[self::CLAIM_KEY] = array_filter([
                ...($metadata[self::CLAIM_KEY] ?? []), 'state' => $state,
                'provider_http_status' => $status, 'session_id_fingerprint' => $fingerprint,
            ], static fn (mixed $value): bool => $value !== null);
            $account->forceFill(['metadata' => $metadata])->save();
        });
    }

    private function finish(string $terminal, array $context, int $logMaxId): array
    {
        $posts = ApiRequestLog::query()->where('id', '>', $logMaxId)->where('provider_id', $context['provider_id'])
            ->where('user_id', self::USER_ID)->where('operation', 'kyc_form_session_create')
            ->where('request_method', 'POST')->count();
        $this->assertProtectedAccount($context['protected_fingerprint']);
        if ($posts > 1 || ($terminal === 'PASS_SESSION_CREATED' && $posts !== 1)) {
            throw new RuntimeException('Pre-built KYC form session postcondition failed closed.');
        }

        return ['terminal' => $terminal, 'session_post_count' => $posts];
    }

    private function sessionId(Response $response): ?string
    {
        try {
            $body = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        $sessionId = is_array($body) ? ($body['sessionId'] ?? null) : null;

        return is_string($sessionId) && trim($sessionId) !== '' ? $sessionId : null;
    }

    private function assertProtectedAccount(string $fingerprint): void
    {
        if ($this->fingerprint(UserProviderAccount::query()->findOrFail(self::PROTECTED_ACCOUNT_ID)) !== $fingerprint) {
            throw new RuntimeException('Protected Account 4 changed.');
        }
    }

    private function fingerprint(UserProviderAccount $account): string
    {
        $attributes = $account->getRawOriginal();
        ksort($attributes);
        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
    }
}
