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

final class NiumHkKycPrebuiltFormSessionRecoveryGenerationTwoRunner
{
    private const ACCOUNT_ID = 7;
    private const PROTECTED_ACCOUNT_ID = 4;
    private const USER_ID = 9;
    private const HISTORICAL_LOG_ID = 117;
    private const HISTORICAL_CLAIM_KEY = 'nium_kyc_prebuilt_form_session';
    private const CLAIM_KEY = 'nium_kyc_prebuilt_form_session_generation_2';
    private const STAKEHOLDER_REFERENCE = '7609d9d1-9d37-4e08-9197-602d792f7a2e';
    private const RECOVERY_REASON = 'expired_after_ekyc_redirect_configuration_blocker';

    public function __construct(private readonly NiumService $niumService) {}

    public function audit(): array
    {
        $context = $this->preflight();
        $this->assertProtectedAccount($context['protected_fingerprint']);

        return [
            'terminal' => 'READY_FOR_SEPARATE_HUMAN_APPROVAL',
            'generation' => 2,
            'payload' => $context['payload'],
            'previous_session_log_id' => self::HISTORICAL_LOG_ID,
            'session_post_count' => 0,
            'db_write_count' => 0,
        ];
    }

    public function run(bool $separateHumanApproval = false): array
    {
        if (! $separateHumanApproval) {
            throw new RuntimeException('Separate human approval is required for Generation #2 recovery.');
        }

        $context = $this->preflight();
        $logMaxId = (int) (ApiRequestLog::query()->max('id') ?? 0);
        $this->claim($context['payload']['expiry']);

        try {
            $response = $this->niumService->post(
                path: $this->niumService->path(
                    (string) config('services.nium.kyc_form_session_endpoint'),
                    ['clientHashId' => $this->niumService->clientId()],
                ),
                payload: $context['payload'],
                user: $context['user'],
                operation: 'kyc_form_session_create',
                externalReference: self::STAKEHOLDER_REFERENCE,
            );
        } catch (ConnectionException|NiumEvidencePersistenceException) {
            $this->mark('unknown', null, 'ambiguous');

            return $this->finish('STOP_SESSION_OUTCOME_UNKNOWN_NO_RETRY', $context, $logMaxId);
        } catch (Throwable) {
            $this->mark('unknown', null, 'ambiguous');

            return $this->finish('STOP_SESSION_OUTCOME_UNKNOWN_NO_RETRY', $context, $logMaxId);
        }

        if (! $response->successful()) {
            $this->mark('rejected', $response->status(), 'response_received');

            return $this->finish('STOP_SESSION_REJECTED_NO_RETRY', $context, $logMaxId);
        }

        $sessionId = $this->sessionId($response);
        if ($sessionId === null) {
            $this->mark('response_review', $response->status(), 'response_received');

            return $this->finish('HOLD_SESSION_RESPONSE_REVIEW_NO_RETRY', $context, $logMaxId);
        }

        $fingerprint = substr(hash('sha256', $sessionId), 0, 16);
        $this->mark('created', $response->status(), 'response_received', $fingerprint);

        return [
            ...$this->finish('PASS_SESSION_CREATED', $context, $logMaxId),
            'session_id_present' => true,
            'session_id_fingerprint' => $fingerprint,
        ];
    }

    private function preflight(): array
    {
        $provider = IntegrationProvider::query()->whereRaw('LOWER(code) = ?', ['nium'])->sole();
        $account = UserProviderAccount::query()->whereKey(self::ACCOUNT_ID)
            ->where('provider_id', $provider->id)->where('user_id', self::USER_ID)->firstOrFail();
        $user = User::query()->findOrFail(self::USER_ID);
        $protected = UserProviderAccount::query()->findOrFail(self::PROTECTED_ACCOUNT_ID);

        $this->assertRecoveryPreconditions($account, (int) $provider->id);

        return [
            'account' => $account,
            'user' => $user,
            'provider_id' => (int) $provider->id,
            'payload' => $this->payload((string) $account->external_customer_id),
            'protected_fingerprint' => $this->fingerprint($protected),
        ];
    }

    private function assertRecoveryPreconditions(UserProviderAccount $account, int $providerId): void
    {
        if (! filled($account->external_customer_id)) {
            throw new RuntimeException('HOLD_CUSTOMER_HASH_ID_MISSING');
        }

        $metadata = (array) $account->metadata;
        if (array_key_exists(self::CLAIM_KEY, $metadata)) {
            throw new RuntimeException('HOLD_PREBUILT_RECOVERY_G2_ALREADY_CLAIMED');
        }

        $historicalClaim = $metadata[self::HISTORICAL_CLAIM_KEY] ?? null;
        if (! is_array($historicalClaim)) {
            throw new RuntimeException('HOLD_HISTORICAL_SESSION_CLAIM_MISSING');
        }

        try {
            $expiry = $historicalClaim['expiry_at'] ?? null;
            $expired = is_string($expiry) && CarbonImmutable::parse($expiry)->isPast();
        } catch (Throwable) {
            $expired = false;
        }
        if (! $expired) {
            throw new RuntimeException('HOLD_HISTORICAL_SESSION_NOT_EXPIRED');
        }

        $log = ApiRequestLog::query()->find(self::HISTORICAL_LOG_ID);
        if ($log === null || (int) $log->provider_id !== $providerId || (int) $log->user_id !== self::USER_ID
            || $log->operation !== 'kyc_form_session_create' || $log->request_method !== 'POST'
            || (int) $log->response_status !== 200 || ! $log->is_success
            || $log->transport_outcome !== 'response_received') {
            throw new RuntimeException('HOLD_HISTORICAL_SESSION_LOG_INVALID');
        }

        $newerSessionExists = ApiRequestLog::query()->where('id', '>', self::HISTORICAL_LOG_ID)
            ->where('provider_id', $providerId)->where('user_id', self::USER_ID)
            ->where('operation', 'kyc_form_session_create')->where('request_method', 'POST')->exists();
        if ($newerSessionExists) {
            throw new RuntimeException('HOLD_NEWER_PREBUILT_SESSION_EXISTS');
        }

        $events = WebhookEvent::query()->where('provider_id', $providerId)
            ->where('event_type', 'CUSTOMER_STATUS_WEBHOOK')
            ->where('external_resource_id', $account->external_customer_id)
            ->where('processing_status', 'processed')->whereNotNull('processed_at')
            ->orderByDesc('processed_at')->orderByDesc('id')->get();
        foreach ($events as $event) {
            $status = (array) $event->payload;
            $subStatus = $status['subStatus'] ?? null;
            if (($status['status'] ?? null) === 'clear'
                && ($subStatus === null || (is_string($subStatus) && trim($subStatus) === ''))) {
                throw new RuntimeException('HOLD_KYC_COMPLETION_EVIDENCE_PRESENT');
            }
        }

        $latest = (array) ($events->first()?->payload ?? []);
        if (($latest['status'] ?? null) !== 'pending' || ($latest['subStatus'] ?? null) !== 'awaiting_kyc') {
            throw new RuntimeException('HOLD_CUSTOMER_NOT_PENDING_AWAITING_KYC');
        }
    }

    private function payload(string $customerId): array
    {
        return [
            'featureType' => 'kyc_form',
            'integrationType' => 'standalone',
            'customerHashId' => $customerId,
            'onBehalf' => false,
            'expiry' => now()->utc()->addMinutes(120)->startOfSecond()->toIso8601ZuluString(),
            'rollingDurationMinutes' => 120,
        ];
    }

    private function claim(string $expiry): void
    {
        DB::transaction(function () use ($expiry): void {
            $account = UserProviderAccount::query()->whereKey(self::ACCOUNT_ID)
                ->where('user_id', self::USER_ID)->lockForUpdate()->firstOrFail();
            $providerId = (int) $account->provider_id;
            $this->assertRecoveryPreconditions($account, $providerId);

            $metadata = (array) $account->metadata;
            $metadata[self::CLAIM_KEY] = [
                'generation' => 2,
                'state' => 'submitting',
                'created_at' => now()->utc()->toISOString(),
                'expiry_at' => $expiry,
                'previous_session_log_id' => self::HISTORICAL_LOG_ID,
                'recovery_reason' => self::RECOVERY_REASON,
            ];
            $account->forceFill(['metadata' => $metadata])->save();
        });
    }

    private function mark(string $state, ?int $status, string $transport, ?string $fingerprint = null): void
    {
        DB::transaction(function () use ($state, $status, $transport, $fingerprint): void {
            $account = UserProviderAccount::query()->whereKey(self::ACCOUNT_ID)->lockForUpdate()->firstOrFail();
            $metadata = (array) $account->metadata;
            $metadata[self::CLAIM_KEY] = array_filter([
                ...($metadata[self::CLAIM_KEY] ?? []),
                'state' => $state,
                'provider_http_status' => $status,
                'transport_outcome' => $transport,
                'session_id_fingerprint' => $fingerprint,
            ], static fn (mixed $value): bool => $value !== null);
            $account->forceFill(['metadata' => $metadata])->save();
        });
    }

    private function finish(string $terminal, array $context, int $logMaxId): array
    {
        $posts = ApiRequestLog::query()->where('id', '>', $logMaxId)
            ->where('provider_id', $context['provider_id'])->where('user_id', self::USER_ID)
            ->where('operation', 'kyc_form_session_create')->where('request_method', 'POST')->count();
        $this->assertProtectedAccount($context['protected_fingerprint']);
        if ($posts > 1 || ($terminal === 'PASS_SESSION_CREATED' && $posts !== 1)) {
            throw new RuntimeException('HOLD_PREBUILT_RECOVERY_G2_POSTCONDITION_FAILED');
        }

        return ['terminal' => $terminal, 'generation' => 2, 'session_post_count' => $posts];
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
