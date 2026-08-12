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
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class NiumHkStakeholderSubmitKycGenerationFourOneShotRunner
{
    private const REQUIRED_PARENT_HEAD = 'e6378325b52ef9dfa108be86964177a26bff57f0';
    private const ACCOUNT_ID = 7;
    private const PROTECTED_ACCOUNT_ID = 4;
    private const USER_ID = 9;
    private const PERSON_ID = 14;
    private const PROFILE_ID = 9;
    private const REFERENCE_ID = '7609d9d1-9d37-4e08-9197-602d792f7a2e';
    private const G3_LOG_ID = 114;
    private const G3_CLAIM = 'nium_stakeholder_submit_kyc_retry_generation_3';
    private const CLAIM_KEY = 'nium_stakeholder_submit_kyc_retry_generation_4';
    private const ERROR_FIELDS = [
        'proofOfIdentityDocument[0].identificationNumber' => 'ac1d1f08d0faba5d',
        'proofOfIdentityDocument[0].expiryDate' => 'fb88615850deb9e8',
        'proofOfIdentityDocument[0].issuanceCountry' => '9c46af0c3435d750',
    ];

    public function __construct(
        private readonly NiumService $niumService,
        private readonly NiumHkManualKycDocumentResolver $documentResolver,
        private readonly NiumHkSubmitKycPayloadFactory $payloadFactory,
    ) {}

    public function audit(): array
    {
        $context = $this->preflight();
        $this->assertProtectedAccount($context['protected_fingerprint']);
        $identity = $context['payload']['proofOfIdentityDocument'][0];

        return [
            'terminal' => 'READY_FOR_SEPARATE_HUMAN_APPROVAL',
            'generation_three_log_id' => self::G3_LOG_ID,
            'generation_three_error_field_count' => count(self::ERROR_FIELDS),
            'entity_type' => $context['payload']['entityType'],
            'kyc_mode' => $context['payload']['kycMode'],
            'region' => $context['payload']['region'],
            'identity_container' => 'array',
            'identity_count' => 1,
            'identity_type' => $identity['type'],
            'identity_file_id_count' => count($identity['fileIds']),
            'identification_number_present' => filled($identity['identificationNumber']),
            'expiry_date_present' => filled($identity['expiryDate']),
            'issuance_country' => $identity['issuanceCountry'],
            'poa_container' => 'object',
            'poa_file_id_count' => count($context['payload']['proofOfAddressDocument']['fileIds']),
            'identity_document_id' => 27,
            'poa_document_id' => 28,
            'g4_post_count' => 0,
            'db_write_count' => 0,
        ];
    }

    public function run(bool $separateHumanApproval = false): array
    {
        if (! $separateHumanApproval) {
            throw new RuntimeException('Separate human approval is required outside the generation #4 runner.');
        }
        $context = $this->preflight();
        $logMaxId = (int) (ApiRequestLog::query()->max('id') ?? 0);
        $this->claim();

        try {
            $response = $this->niumService->post(
                path: $this->niumService->path(
                    (string) config('services.nium.customer_submit_kyc_endpoint'),
                    ['client' => $this->niumService->clientId(), 'customer' => $context['account']->external_customer_id],
                ),
                payload: $context['payload'],
                user: $context['user'],
                operation: 'submit_kyc',
                externalReference: self::REFERENCE_ID,
            );
        } catch (ConnectionException|NiumEvidencePersistenceException) {
            $this->mark('unknown');

            return $this->finish('STOP_KYC_OUTCOME_UNKNOWN_NO_RETRY', $context, $logMaxId);
        } catch (Throwable) {
            $rejected = $this->newLogs($context, $logMaxId)->contains(
                fn (ApiRequestLog $log): bool => (int) $log->response_status >= 400,
            );
            $this->mark($rejected ? 'rejected' : 'unknown');

            return $this->finish($rejected ? 'STOP_KYC_REJECTED_NO_RETRY' : 'STOP_KYC_OUTCOME_UNKNOWN_NO_RETRY', $context, $logMaxId);
        }

        if (! $response->successful()) {
            $this->mark('rejected');

            return $this->finish('STOP_KYC_REJECTED_NO_RETRY', $context, $logMaxId);
        }
        $valid = $this->validResponse($response);
        $this->mark($valid ? 'initiated' : 'response_review');

        return $this->finish($valid ? 'PASS_KYC_INITIATED' : 'HOLD_RESPONSE_REVIEW', $context, $logMaxId);
    }

    private function preflight(): array
    {
        if (! $this->currentHeadIsCompatible()) {
            throw new RuntimeException('The deployed Git HEAD does not include the locked pre-G4 lineage.');
        }
        foreach (self::ERROR_FIELDS as $field => $fingerprint) {
            if (substr(hash('sha256', $field), 0, 16) !== $fingerprint) {
                throw new RuntimeException('Generation #4 error-field fingerprint invariant failed.');
            }
        }
        $provider = IntegrationProvider::query()->whereRaw('LOWER(code) = ?', ['nium'])->sole();
        $account = UserProviderAccount::query()->whereKey(self::ACCOUNT_ID)
            ->where('provider_id', $provider->id)->where('user_id', self::USER_ID)->firstOrFail();
        $user = User::query()->findOrFail(self::USER_ID);
        $person = KycRelatedPerson::query()->whereKey(self::PERSON_ID)
            ->where('kyc_profile_id', self::PROFILE_ID)->firstOrFail();
        $protected = UserProviderAccount::query()->findOrFail(self::PROTECTED_ACCOUNT_ID);
        if (! filled($account->external_customer_id) || ! filled($account->external_account_id)
            || $account->reconciliation_status !== 'reconciled') {
            throw new RuntimeException('Account 7 provider identifiers are not locked.');
        }
        $this->assertAwaitingKyc((int) $provider->id, (string) $account->external_customer_id);
        $metadata = (array) $account->metadata;
        if (($metadata[self::G3_CLAIM]['state'] ?? null) !== 'rejected') {
            throw new RuntimeException('Generation #3 rejected claim is required.');
        }
        if (array_key_exists(self::CLAIM_KEY, $metadata)) {
            throw new RuntimeException('HOLD_GENERATION_4_ALREADY_CLAIMED');
        }
        $this->assertHistory((int) $provider->id);
        $documents = $this->documentResolver->resolve($person);
        if ((int) ($documents['identity']?->id ?? 0) !== 27 || (int) ($documents['proof_of_address']?->id ?? 0) !== 28) {
            throw new RuntimeException('Factual documents 27 and 28 must be approved and NIUM AVAILABLE.');
        }
        $payload = $this->payloadFactory->buildManual(
            $person, self::REFERENCE_ID, $documents['identity'], $documents['proof_of_address'],
        );

        return [
            'account' => $account,
            'user' => $user,
            'provider_id' => (int) $provider->id,
            'payload' => $payload,
            'protected_fingerprint' => $this->fingerprint($protected),
        ];
    }

    private function assertHistory(int $providerId): void
    {
        $applicant = ApiRequestLog::query()->findOrFail(104);
        if ((int) $applicant->provider_id !== $providerId || (int) $applicant->user_id !== self::USER_ID
            || $applicant->operation !== 'submit_kyc' || $applicant->request_method !== 'POST'
            || (int) $applicant->response_status !== 200 || ! $applicant->is_success
            || $applicant->transport_outcome !== 'response_received') {
            throw new RuntimeException('Applicant evidence #104 is invalid.');
        }
        $this->assertGenerationOneRejection(ApiRequestLog::query()->findOrFail(106), $providerId);
        $this->assertGenerationTwoRejection(ApiRequestLog::query()->findOrFail(113), $providerId);
        $this->assertGenerationThreeRejection(ApiRequestLog::query()->findOrFail(self::G3_LOG_ID), $providerId);
        $ids = ApiRequestLog::query()->where('provider_id', $providerId)->where('user_id', self::USER_ID)
            ->where('external_reference', self::REFERENCE_ID)->where('operation', 'submit_kyc')
            ->where('request_method', 'POST')->orderBy('id')->pluck('id')->all();
        if ($ids !== [106, 113, 114]) {
            throw new RuntimeException('Logs #106, #113, and #114 must be the sole stakeholder Submit KYC POSTs.');
        }
    }

    private function assertGenerationOneRejection(ApiRequestLog $log, int $providerId): void
    {
        $body = (array) $log->response_body;
        $items = $body['error_items'] ?? [];
        $itemsPresent = array_key_exists('error_items', $body) && ! empty($items);
        $structured = is_array($items) && collect($items)->contains(fn (mixed $item): bool => is_array($item)
            && ($item['error_code'] ?? null) === 'invalid_input'
            && ($item['error_field'] ?? null) === 'entityType'
            && ($item['error_field_fingerprint'] ?? null) === 'b4753588f3f6ef2b');
        $legacy = ! $itemsPresent && ($body['error_field_fingerprint'] ?? null) === 'b4753588f3f6ef2b'
            && substr(hash('sha256', 'entityType'), 0, 16) === 'b4753588f3f6ef2b';
        if (! $this->historicalScopeIsValid($log, $providerId, 106)
            || ($itemsPresent ? ! $structured : ! $legacy)) {
            throw new RuntimeException('Historical rejection evidence #106 is invalid.');
        }
    }

    private function assertGenerationTwoRejection(ApiRequestLog $log, int $providerId): void
    {
        $body = (array) $log->response_body;
        $items = $body['error_items'] ?? null;
        $structured = is_array($items) && ! empty($items) && collect($items)->contains(
            fn (mixed $item): bool => is_array($item)
                && ($item['error_code'] ?? null) === 'invalid_input'
                && ($item['error_field'] ?? null) === 'proofOfAddressDocument'
                && ($item['error_field_fingerprint'] ?? null) === 'a5b7a48f01932655',
        );
        $sanitized = is_array($items) && array_is_list($items) && count($items) === 1 && is_array($items[0])
            && ($items[0]['error_code'] ?? null) === 'invalid_input'
            && $this->fieldIsSanitizedAway($items[0])
            && ($items[0]['error_field_fingerprint'] ?? null) === 'a5b7a48f01932655'
            && $this->fieldIsSanitizedAway($body)
            && ($body['error_field_fingerprint'] ?? null) === 'a5b7a48f01932655'
            && substr(hash('sha256', 'proofOfAddressDocument'), 0, 16) === 'a5b7a48f01932655';
        if (! $this->historicalScopeIsValid($log, $providerId, 113) || (! $structured && ! $sanitized)) {
            throw new RuntimeException('Historical rejection evidence #113 is invalid.');
        }
    }

    private function historicalScopeIsValid(ApiRequestLog $log, int $providerId, int $id): bool
    {
        $body = (array) $log->response_body;

        return (int) $log->id === $id && (int) $log->provider_id === $providerId
            && (int) $log->user_id === self::USER_ID && $log->external_reference === self::REFERENCE_ID
            && $log->operation === 'submit_kyc' && $log->request_method === 'POST'
            && (int) $log->response_status === 400 && $log->is_success === false
            && $log->transport_outcome === 'response_received' && ($body['error_code'] ?? null) === 'invalid_input';
    }

    private function fieldIsSanitizedAway(array $value): bool
    {
        return ! array_key_exists('error_field', $value) || $value['error_field'] === null;
    }

    private function assertGenerationThreeRejection(ApiRequestLog $log, int $providerId): void
    {
        $body = (array) $log->response_body;
        $items = $body['error_items'] ?? null;
        $fingerprints = is_array($items) && array_is_list($items)
            ? array_map(fn (mixed $item): mixed => is_array($item) ? ($item['error_field_fingerprint'] ?? null) : null, $items)
            : [];
        sort($fingerprints);
        $expected = array_values(self::ERROR_FIELDS);
        sort($expected);
        if ((int) $log->id !== self::G3_LOG_ID || (int) $log->provider_id !== $providerId
            || (int) $log->user_id !== self::USER_ID || $log->external_reference !== self::REFERENCE_ID
            || $log->operation !== 'submit_kyc' || $log->request_method !== 'POST'
            || (int) $log->response_status !== 400 || $log->is_success !== false
            || $log->transport_outcome !== 'response_received' || count($items ?? []) !== 3
            || $fingerprints !== $expected) {
            throw new RuntimeException('Locked generation #3 rejection evidence #114 is invalid.');
        }
    }

    private function assertAwaitingKyc(int $providerId, string $customerId): void
    {
        $event = WebhookEvent::query()->where('provider_id', $providerId)->where('event_type', 'CUSTOMER_STATUS_WEBHOOK')
            ->where('external_resource_id', $customerId)->where('processing_status', 'processed')
            ->whereNotNull('processed_at')->orderByDesc('processed_at')->orderByDesc('id')->first();
        $payload = (array) ($event?->payload ?? []);
        if (($payload['status'] ?? null) !== 'pending' || ($payload['subStatus'] ?? null) !== 'awaiting_kyc') {
            throw new RuntimeException('Latest Nium customer webhook is not awaiting_kyc.');
        }
    }

    private function claim(): void
    {
        DB::transaction(function (): void {
            $account = UserProviderAccount::query()->whereKey(self::ACCOUNT_ID)->lockForUpdate()->firstOrFail();
            $metadata = (array) $account->metadata;
            if (($metadata[self::G3_CLAIM]['state'] ?? null) !== 'rejected' || array_key_exists(self::CLAIM_KEY, $metadata)) {
                throw new RuntimeException('Generation #4 claim precondition failed.');
            }
            $metadata[self::CLAIM_KEY] = [
                'state' => 'submitting', 'previous_log_id' => self::G3_LOG_ID, 'previous_http_status' => 400,
                'previous_error_field_count' => count(self::ERROR_FIELDS), 'updated_at' => now()->toISOString(),
            ];
            $account->forceFill(['metadata' => $metadata])->save();
        });
    }

    private function mark(string $state): void
    {
        DB::transaction(function () use ($state): void {
            $account = UserProviderAccount::query()->whereKey(self::ACCOUNT_ID)->lockForUpdate()->firstOrFail();
            $metadata = (array) $account->metadata;
            $metadata[self::CLAIM_KEY]['state'] = $state;
            $metadata[self::CLAIM_KEY]['updated_at'] = now()->toISOString();
            $account->forceFill(['metadata' => $metadata])->save();
        });
    }

    private function finish(string $terminal, array $context, int $logMaxId): array
    {
        $posts = $this->newLogs($context, $logMaxId)->count();
        $this->assertProtectedAccount($context['protected_fingerprint']);
        if ($posts > 1 || ($terminal === 'PASS_KYC_INITIATED' && $posts !== 1)) {
            throw new RuntimeException('Stakeholder generation #4 postcondition failed closed.');
        }

        return ['terminal' => $terminal, 'g4_post_count' => $posts];
    }

    private function newLogs(array $context, int $logMaxId)
    {
        return ApiRequestLog::query()->where('id', '>', $logMaxId)->where('provider_id', $context['provider_id'])
            ->where('user_id', self::USER_ID)->where('operation', 'submit_kyc')->where('request_method', 'POST')
            ->where('external_reference', self::REFERENCE_ID)->get();
    }

    private function validResponse(Response $response): bool
    {
        try {
            $body = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }

        return is_array($body) && ($body['kycStatus'] ?? null) === 'initiated'
            && ($body['kycMode'] ?? null) === 'MANUAL_KYC'
            && ($body['entityType'] ?? null) === 'INDIVIDUAL_STAKEHOLDER'
            && ($body['referenceId'] ?? null) === self::REFERENCE_ID;
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

    private function currentHeadIsCompatible(): bool
    {
        $repository = dirname(__DIR__, 3);
        $process = new Process(['git', '-C', $repository, 'merge-base', '--is-ancestor', self::REQUIRED_PARENT_HEAD, 'HEAD']);
        $process->run();

        return $process->isSuccessful();
    }
}
