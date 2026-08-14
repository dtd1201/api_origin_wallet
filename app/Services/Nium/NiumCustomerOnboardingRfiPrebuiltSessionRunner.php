<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class NiumCustomerOnboardingRfiPrebuiltSessionRunner
{
    private const ACCOUNT_ID = 7;
    private const PROTECTED_ACCOUNT_ID = 4;
    private const USER_ID = 9;
    private const CLAIM_KEY = 'nium_customer_onboarding_rfi_prebuilt_session';
    private const OPERATION = 'customer_onboarding_rfi_session_create';

    public function __construct(private readonly NiumService $niumService) {}

    public function audit(): array
    {
        $context = $this->preflight();
        $this->assertProtectedAccount($context['protected_fingerprint']);

        return [
            'terminal' => 'READY_FOR_SEPARATE_HUMAN_APPROVAL',
            'feature_type' => 'customer_onboarding_rfi',
            'payload' => $context['payload'],
            'readiness' => 'HOLD_RFI',
            'rfi_session_post_count' => 0,
            'db_write_count' => 0,
        ];
    }

    public function readiness(): string
    {
        $provider = IntegrationProvider::query()->whereRaw('LOWER(code) = ?', ['nium'])->sole();
        $account = UserProviderAccount::query()->whereKey(self::ACCOUNT_ID)
            ->where('provider_id', $provider->id)->where('user_id', self::USER_ID)->firstOrFail();
        $status = $this->latestCustomerStatus((int) $provider->id, (string) $account->external_customer_id);
        $subStatus = $status['subStatus'] ?? null;

        if (($status['status'] ?? null) === 'clear'
            && ($subStatus === null || (is_string($subStatus) && trim($subStatus) === ''))) {
            return 'RFI_CLEARED';
        }

        return ($subStatus ?? null) === 'rfi_requested' ? 'HOLD_RFI' : 'HOLD_RFI_NOT_CLEAR';
    }

    public function run(bool $separateHumanApproval = false): array
    {
        if (! $separateHumanApproval) {
            throw new RuntimeException('Separate human approval is required for Customer Onboarding RFI.');
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
                operation: self::OPERATION,
            );
        } catch (ConnectionException|NiumEvidencePersistenceException) {
            $this->mark('unknown', null, 'ambiguous');

            return $this->finish('STOP_RFI_SESSION_OUTCOME_UNKNOWN_NO_RETRY', $context, $logMaxId);
        } catch (Throwable) {
            $this->mark('unknown', null, 'ambiguous');

            return $this->finish('STOP_RFI_SESSION_OUTCOME_UNKNOWN_NO_RETRY', $context, $logMaxId);
        }

        if (! $response->successful()) {
            $this->mark('rejected', $response->status(), 'response_received');

            return $this->finish('STOP_RFI_SESSION_REJECTED_NO_RETRY', $context, $logMaxId);
        }

        $sessionId = $this->sessionId($response);
        if ($sessionId === null) {
            $this->mark('response_review', $response->status(), 'response_received');

            return $this->finish('HOLD_RFI_SESSION_RESPONSE_REVIEW_NO_RETRY', $context, $logMaxId);
        }

        $fingerprint = substr(hash('sha256', $sessionId), 0, 16);
        $this->mark('created', $response->status(), 'response_received', $fingerprint);

        return [
            ...$this->finish('PASS_RFI_SESSION_CREATED', $context, $logMaxId),
            'sessionId' => $sessionId,
            'session_id_present' => true,
            'session_id_fingerprint' => $fingerprint,
            'readiness' => 'HOLD_RFI',
        ];
    }

    private function preflight(): array
    {
        $provider = IntegrationProvider::query()->whereRaw('LOWER(code) = ?', ['nium'])->sole();
        $account = UserProviderAccount::query()->whereKey(self::ACCOUNT_ID)
            ->where('provider_id', $provider->id)->where('user_id', self::USER_ID)->firstOrFail();
        $user = User::query()->findOrFail(self::USER_ID);
        $protected = UserProviderAccount::query()->findOrFail(self::PROTECTED_ACCOUNT_ID);

        $this->assertRfiPreconditions($account, (int) $provider->id);

        return [
            'user' => $user,
            'provider_id' => (int) $provider->id,
            'payload' => $this->payload((string) $account->external_customer_id),
            'protected_fingerprint' => $this->fingerprint($protected),
        ];
    }

    private function assertRfiPreconditions(UserProviderAccount $account, int $providerId): void
    {
        if (! filled($account->external_customer_id)) {
            throw new RuntimeException('HOLD_RFI_TRIGGER_NOT_PROVEN');
        }

        $metadata = (array) $account->metadata;
        if (array_key_exists(self::CLAIM_KEY, $metadata)) {
            throw new RuntimeException('HOLD_ONBOARDING_RFI_SESSION_ALREADY_CLAIMED');
        }

        if (ApiRequestLog::query()->where('provider_id', $providerId)->where('user_id', self::USER_ID)
            ->where('operation', self::OPERATION)->where('request_method', 'POST')->exists()) {
            throw new RuntimeException('HOLD_ONBOARDING_RFI_SESSION_HISTORY_EXISTS');
        }

        $status = $this->latestCustomerStatus($providerId, (string) $account->external_customer_id);
        if (($status['subStatus'] ?? null) !== 'rfi_requested'
            || $account->provider_sub_status !== 'rfi_requested'
            || $account->rfi_status !== 'requested') {
            throw new RuntimeException('HOLD_RFI_TRIGGER_NOT_PROVEN');
        }
    }

    private function latestCustomerStatus(int $providerId, string $customerId): array
    {
        $event = WebhookEvent::query()->where('provider_id', $providerId)
            ->where('event_type', 'CUSTOMER_STATUS_WEBHOOK')->where('external_resource_id', $customerId)
            ->where('processing_status', 'processed')->whereNotNull('processed_at')
            ->orderByDesc('processed_at')->orderByDesc('id')->first();

        return (array) ($event?->payload ?? []);
    }

    private function payload(string $customerId): array
    {
        return [
            'featureType' => 'customer_onboarding_rfi',
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
            $this->assertRfiPreconditions($account, (int) $account->provider_id);

            $metadata = (array) $account->metadata;
            $metadata[self::CLAIM_KEY] = [
                'generation' => 1,
                'state' => 'submitting',
                'feature_type' => 'customer_onboarding_rfi',
                'created_at' => now()->utc()->toISOString(),
                'expiry_at' => $expiry,
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
                'updated_at' => now()->utc()->toISOString(),
            ], static fn (mixed $value): bool => $value !== null);
            $account->forceFill(['metadata' => $metadata])->save();
        });
    }

    private function finish(string $terminal, array $context, int $logMaxId): array
    {
        $posts = ApiRequestLog::query()->where('id', '>', $logMaxId)
            ->where('provider_id', $context['provider_id'])->where('user_id', self::USER_ID)
            ->where('operation', self::OPERATION)->where('request_method', 'POST')->count();
        $this->assertProtectedAccount($context['protected_fingerprint']);
        if ($posts > 1 || ($terminal === 'PASS_RFI_SESSION_CREATED' && $posts !== 1)) {
            throw new RuntimeException('HOLD_ONBOARDING_RFI_SESSION_POSTCONDITION_FAILED');
        }

        return ['terminal' => $terminal, 'rfi_session_post_count' => $posts];
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
