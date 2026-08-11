<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\KycRelatedPerson;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class NiumHkSubmitKycOneShotRunner
{
    public const APPLICANT = 'applicant';

    public const STAKEHOLDER = 'individual_stakeholder';

    private const ACCOUNT_ID = 7;

    private const PROTECTED_ACCOUNT_ID = 4;

    private const TARGETS = [
        self::APPLICANT => [
            'person_id' => 13,
            'external_id' => 'origin-wallet-person-13',
            'reference_id' => 'c620e0e9-ab0a-43bd-aa10-44f573db723a',
        ],
        self::STAKEHOLDER => [
            'person_id' => 14,
            'external_id' => 'origin-wallet-person-14',
            'reference_id' => '7609d9d1-9d37-4e08-9197-602d792f7a2e',
        ],
    ];

    public function __construct(
        private readonly NiumService $niumService,
        private readonly NiumHkSubmitKycPayloadFactory $payloadFactory,
    ) {}

    public function run(string $target): array
    {
        $context = $this->preflight($target);
        $protectedFingerprint = $this->fingerprint($context['protected_account']);
        $logMaxId = (int) (ApiRequestLog::query()->max('id') ?? 0);
        $this->claim($context['reference_id']);

        try {
            $response = $this->niumService->post(
                path: $this->niumService->path(
                    (string) config('services.nium.customer_submit_kyc_endpoint'),
                    [
                        'client' => $this->niumService->clientId(),
                        'customer' => $context['account']->external_customer_id,
                    ],
                ),
                payload: $context['payload'],
                user: $context['user'],
                operation: 'submit_kyc',
                externalReference: $context['reference_id'],
            );
        } catch (ConnectionException|NiumEvidencePersistenceException) {
            $this->mark($context['reference_id'], 'unknown');

            return $this->finish('STOP_KYC_OUTCOME_UNKNOWN_NO_RETRY', $context, $logMaxId, $protectedFingerprint);
        } catch (Throwable) {
            $definite = $this->newSubmitLogs($context, $logMaxId)->contains(
                fn (ApiRequestLog $log): bool => (int) $log->response_status >= 400,
            );
            $this->mark($context['reference_id'], $definite ? 'rejected' : 'unknown');

            return $this->finish(
                $definite ? 'STOP_KYC_REJECTED_NO_RETRY' : 'STOP_KYC_OUTCOME_UNKNOWN_NO_RETRY',
                $context,
                $logMaxId,
                $protectedFingerprint,
            );
        }

        if (! $response->successful()) {
            $this->mark($context['reference_id'], 'rejected', $response->status());

            return $this->finish('STOP_KYC_REJECTED_NO_RETRY', $context, $logMaxId, $protectedFingerprint);
        }

        $body = $this->responseObject($response);
        $valid = ($body['kycStatus'] ?? null) === 'initiated'
            && ($body['kycMode'] ?? null) === 'biometric_kyc'
            && ($body['entityType'] ?? null) === $target
            && ($body['referenceId'] ?? null) === $context['reference_id']
            && is_string($body['redirectUrl'] ?? null)
            && trim($body['redirectUrl']) !== '';

        $this->mark(
            $context['reference_id'],
            $valid ? 'initiated' : 'response_review',
            $response->status(),
            $body['redirectUrl'] ?? null,
        );

        return $this->finish(
            $valid ? 'PASS_KYC_INITIATED' : 'HOLD_RESPONSE_REVIEW',
            $context,
            $logMaxId,
            $protectedFingerprint,
            $valid ? (string) $body['redirectUrl'] : null,
        );
    }

    private function preflight(string $target): array
    {
        if (! array_key_exists($target, self::TARGETS)) {
            throw new RuntimeException('Submit KYC target must be applicant or individual_stakeholder.');
        }

        $binding = self::TARGETS[$target];
        $provider = IntegrationProvider::query()->whereRaw('LOWER(code) = ?', ['nium'])->sole();
        $account = UserProviderAccount::query()->whereKey(self::ACCOUNT_ID)->where('provider_id', $provider->id)->firstOrFail();
        $user = User::query()->findOrFail($account->user_id);
        $person = KycRelatedPerson::query()->whereKey($binding['person_id'])->where('kyc_profile_id', 9)->firstOrFail();
        $protectedAccount = UserProviderAccount::query()->findOrFail(self::PROTECTED_ACCOUNT_ID);

        if (! filled($account->external_customer_id)
            || ! filled($account->external_account_id)
            || $account->reconciliation_status !== 'reconciled') {
            throw new RuntimeException('Account 7 is not in the locked awaiting_kyc state.');
        }

        $this->assertWebhookEvidence((int) $provider->id, $account, $binding, $target);

        if ($person->id !== $binding['person_id']
            || 'origin-wallet-person-'.$person->id !== $binding['external_id']) {
            throw new RuntimeException('The selected Nium entity is not bound to the locked related person.');
        }

        $this->assertNoPriorAttempt((int) $provider->id, (int) $user->id, $binding['reference_id']);
        $payload = $this->payloadFactory->build($person, $target, $binding['reference_id']);

        return [
            'account' => $account,
            'user' => $user,
            'protected_account' => $protectedAccount,
            'reference_id' => $binding['reference_id'],
            'provider_id' => (int) $provider->id,
            'user_id' => (int) $user->id,
            'payload' => $payload,
        ];
    }

    private function assertWebhookEvidence(
        int $providerId,
        UserProviderAccount $account,
        array $binding,
        string $target,
    ): void {
        $customerEvent = $this->latestProcessedWebhook(
            $providerId,
            'CUSTOMER_STATUS_WEBHOOK',
            (string) $account->external_customer_id,
        );
        $customerPayload = (array) ($customerEvent?->payload ?? []);

        if (($customerPayload['status'] ?? null) !== 'pending'
            || ($customerPayload['subStatus'] ?? null) !== 'awaiting_kyc') {
            throw new RuntimeException('Latest Nium customer webhook is not awaiting_kyc.');
        }

        $entityEvent = WebhookEvent::query()
            ->where('provider_id', $providerId)
            ->where('event_type', 'CUSTOMER_ENTITY_KYC_STATUS')
            ->where('external_resource_id', $account->external_customer_id)
            ->where('processing_status', 'processed')
            ->whereNotNull('processed_at')
            ->orderByDesc('processed_at')
            ->orderByDesc('id')
            ->get()
            ->first(function (WebhookEvent $event) use ($binding): bool {
                $payload = (array) $event->payload;

                return ($payload['externalId'] ?? null) === $binding['external_id']
                    && ($payload['kycStatus'] ?? null) !== '${kycStatus}';
            });
        $entityPayload = (array) ($entityEvent?->payload ?? []);

        if (($entityPayload['kycStatus'] ?? null) !== 'kyc_required'
            || ($entityPayload['entityType'] ?? null) !== $target
            || ($entityPayload['referenceId'] ?? null) !== $binding['reference_id']) {
            throw new RuntimeException('Latest Nium entity webhook is not the locked kyc_required entity.');
        }
    }

    private function latestProcessedWebhook(int $providerId, string $eventType, string $externalResourceId): ?WebhookEvent
    {
        return WebhookEvent::query()
            ->where('provider_id', $providerId)
            ->where('event_type', $eventType)
            ->where('external_resource_id', $externalResourceId)
            ->where('processing_status', 'processed')
            ->whereNotNull('processed_at')
            ->orderByDesc('processed_at')
            ->orderByDesc('id')
            ->first();
    }

    private function assertNoPriorAttempt(int $providerId, int $userId, string $referenceId): void
    {
        if (ApiRequestLog::query()
            ->where('provider_id', $providerId)
            ->where('user_id', $userId)
            ->where('operation', 'submit_kyc')
            ->where('external_reference', $referenceId)
            ->where('request_method', 'POST')
            ->exists()) {
            throw new RuntimeException('HOLD_ALREADY_PROCESSED');
        }
    }

    private function claim(string $referenceId): void
    {
        DB::transaction(function () use ($referenceId): void {
            $account = UserProviderAccount::query()->whereKey(self::ACCOUNT_ID)->lockForUpdate()->firstOrFail();
            $metadata = (array) $account->metadata;
            $key = 'ref_'.substr(hash('sha256', $referenceId), 0, 16);

            if (Arr::has($metadata, 'nium_submit_kyc_attempts.'.$key)) {
                throw new RuntimeException('HOLD_ALREADY_PROCESSED');
            }

            data_set($metadata, 'nium_submit_kyc_attempts.'.$key, [
                'state' => 'submitting',
                'updated_at' => now()->toISOString(),
            ]);
            $account->forceFill(['metadata' => $metadata])->save();
        });
    }

    private function mark(string $referenceId, string $state, ?int $httpStatus = null, mixed $redirectUrl = null): void
    {
        DB::transaction(function () use ($referenceId, $state, $httpStatus, $redirectUrl): void {
            $account = UserProviderAccount::query()->whereKey(self::ACCOUNT_ID)->lockForUpdate()->firstOrFail();
            $metadata = (array) $account->metadata;
            $key = 'ref_'.substr(hash('sha256', $referenceId), 0, 16);
            data_set($metadata, 'nium_submit_kyc_attempts.'.$key, array_filter([
                'state' => $state,
                'kyc_mode' => 'biometric_kyc',
                'provider_http_status' => $httpStatus,
                'redirect_url_fingerprint' => is_string($redirectUrl)
                    ? substr(hash('sha256', $redirectUrl), 0, 16)
                    : null,
                'updated_at' => now()->toISOString(),
            ], static fn ($value): bool => $value !== null));
            $account->forceFill(['metadata' => $metadata])->save();
        });
    }

    private function finish(
        string $terminal,
        array $context,
        int $logMaxId,
        string $protectedFingerprint,
        ?string $redirectUrl = null,
    ): array {
        $posts = $this->newSubmitLogs($context, $logMaxId)->count();
        if ($posts > 1
            || ($terminal === 'PASS_KYC_INITIATED' && $posts !== 1)
            || $this->fingerprint(UserProviderAccount::query()->findOrFail(self::PROTECTED_ACCOUNT_ID)) !== $protectedFingerprint) {
            throw new RuntimeException('Submit KYC one-shot postcondition failed closed.');
        }

        return array_filter([
            'terminal' => $terminal,
            'submit_kyc_post_count' => $posts,
            'redirect_url' => $redirectUrl,
        ], static fn ($value): bool => $value !== null);
    }

    private function newSubmitLogs(array $context, int $logMaxId): Collection
    {
        return ApiRequestLog::query()
            ->where('id', '>', $logMaxId)
            ->where('provider_id', $context['provider_id'])
            ->where('user_id', $context['user_id'])
            ->where('operation', 'submit_kyc')
            ->where('request_method', 'POST')
            ->where('external_reference', $context['reference_id'])
            ->get();
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
