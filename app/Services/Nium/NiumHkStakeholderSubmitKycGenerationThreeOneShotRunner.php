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

final class NiumHkStakeholderSubmitKycGenerationThreeOneShotRunner
{
    private const REQUIRED_PARENT_HEAD = '9d975f965f539c5d8ef2d07e37e3632f1a6b7989';

    private const ACCOUNT_ID = 7;
    private const PROTECTED_ACCOUNT_ID = 4;
    private const USER_ID = 9;
    private const PERSON_ID = 14;
    private const PROFILE_ID = 9;
    private const REFERENCE_ID = '7609d9d1-9d37-4e08-9197-602d792f7a2e';
    private const APPLICANT_LOG_ID = 104;
    private const GENERATION_ONE_LOG_ID = 106;
    private const GENERATION_TWO_LOG_ID = 113;
    private const GENERATION_TWO_CLAIM = 'nium_stakeholder_submit_kyc_retry_generation_2';
    private const CLAIM_KEY = 'nium_stakeholder_submit_kyc_retry_generation_3';
    private const ERROR_FIELD = 'proofOfAddressDocument';
    private const ERROR_FIELD_FINGERPRINT = 'a5b7a48f01932655';

    public function __construct(
        private readonly NiumService $niumService,
        private readonly NiumHkManualKycDocumentResolver $documentResolver,
        private readonly NiumHkSubmitKycPayloadFactory $payloadFactory,
    ) {}

    public function audit(): array
    {
        $context = $this->preflight();
        $this->assertProtectedAccount($context['protected_fingerprint']);

        return [
            'terminal' => 'READY_FOR_SEPARATE_HUMAN_APPROVAL',
            'generation_two_log_id' => self::GENERATION_TWO_LOG_ID,
            'generation_two_error_evidence_mode' => $context['generation_two_error_evidence_mode'],
            'entity_type' => $context['payload']['entityType'],
            'kyc_mode' => $context['payload']['kycMode'],
            'region' => $context['payload']['region'],
            'identity_container' => 'array',
            'identity_count' => count($context['payload']['proofOfIdentityDocument']),
            'poa_container' => 'object',
            'poa_file_id_count' => count($context['payload']['proofOfAddressDocument']['fileIds']),
            'identity_document_id' => $context['identity_document_id'],
            'poa_document_id' => $context['poa_document_id'],
            'stakeholder_generation_three_post_count' => 0,
            'db_write_count' => 0,
        ];
    }

    public function run(bool $separateHumanApproval = false): array
    {
        if (! $separateHumanApproval) {
            throw new RuntimeException('Separate human approval is required outside the generation #3 runner.');
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

        $valid = $this->validResponse($response, $context);
        $this->mark($valid ? 'initiated' : 'response_review');

        return $this->finish($valid ? 'PASS_KYC_INITIATED' : 'HOLD_RESPONSE_REVIEW', $context, $logMaxId);
    }

    private function preflight(): array
    {
        if (! $this->currentHeadIsCompatible()) {
            throw new RuntimeException('The deployed Git HEAD does not include the locked generation #2 lineage.');
        }
        if (substr(hash('sha256', self::ERROR_FIELD), 0, 16) !== self::ERROR_FIELD_FINGERPRINT) {
            throw new RuntimeException('Generation #3 error-field fingerprint invariant failed.');
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
        if (($metadata[self::GENERATION_TWO_CLAIM]['state'] ?? null) !== 'rejected') {
            throw new RuntimeException('Generation #2 rejected claim is required.');
        }
        if (array_key_exists(self::CLAIM_KEY, $metadata)) {
            throw new RuntimeException('HOLD_GENERATION_3_ALREADY_CLAIMED');
        }

        $generationTwoErrorEvidenceMode = $this->assertHistoricalLogs((int) $provider->id);
        $documents = $this->documentResolver->resolve($person);
        if ((int) ($documents['identity']?->id ?? 0) !== 27 || (int) ($documents['proof_of_address']?->id ?? 0) !== 28) {
            throw new RuntimeException('Factual documents 27 and 28 must be approved and NIUM AVAILABLE.');
        }
        $payload = $this->payloadFactory->buildManual(
            $person,
            self::REFERENCE_ID,
            $documents['identity'],
            $documents['proof_of_address'],
        );

        return [
            'account' => $account,
            'user' => $user,
            'provider_id' => (int) $provider->id,
            'payload' => $payload,
            'protected_fingerprint' => $this->fingerprint($protected),
            'identity_document_id' => 27,
            'poa_document_id' => 28,
            'generation_two_error_evidence_mode' => $generationTwoErrorEvidenceMode,
        ];
    }

    private function assertAwaitingKyc(int $providerId, string $customerId): void
    {
        $event = WebhookEvent::query()->where('provider_id', $providerId)
            ->where('event_type', 'CUSTOMER_STATUS_WEBHOOK')->where('external_resource_id', $customerId)
            ->where('processing_status', 'processed')->whereNotNull('processed_at')
            ->orderByDesc('processed_at')->orderByDesc('id')->first();
        $payload = (array) ($event?->payload ?? []);
        if (($payload['status'] ?? null) !== 'pending' || ($payload['subStatus'] ?? null) !== 'awaiting_kyc') {
            throw new RuntimeException('Latest Nium customer webhook is not awaiting_kyc.');
        }
    }

    private function assertHistoricalLogs(int $providerId): string
    {
        $applicant = ApiRequestLog::query()->findOrFail(self::APPLICANT_LOG_ID);
        $g1 = ApiRequestLog::query()->findOrFail(self::GENERATION_ONE_LOG_ID);
        $g2 = ApiRequestLog::query()->findOrFail(self::GENERATION_TWO_LOG_ID);
        if ((int) $applicant->provider_id !== $providerId || (int) $applicant->user_id !== self::USER_ID
            || $applicant->external_reference !== 'c620e0e9-ab0a-43bd-aa10-44f573db723a'
            || $applicant->operation !== 'submit_kyc' || $applicant->request_method !== 'POST'
            || (int) $applicant->response_status !== 200 || ! $applicant->is_success
            || $applicant->transport_outcome !== 'response_received') {
            throw new RuntimeException('Applicant evidence #104 is invalid.');
        }
        $this->assertGenerationOneRejectedLog($g1);
        $generationTwoErrorEvidenceMode = $this->assertGenerationTwoRejectedLog($g2, $providerId);

        $stakeholderPosts = ApiRequestLog::query()->where('provider_id', $providerId)->where('user_id', self::USER_ID)
            ->where('operation', 'submit_kyc')->where('request_method', 'POST')
            ->where('external_reference', self::REFERENCE_ID)->orderBy('id')->pluck('id')->all();
        if ($stakeholderPosts !== [self::GENERATION_ONE_LOG_ID, self::GENERATION_TWO_LOG_ID]) {
            throw new RuntimeException('Logs #106 and #113 must be the sole stakeholder Submit KYC POSTs.');
        }

        return $generationTwoErrorEvidenceMode;
    }

    private function assertGenerationOneRejectedLog(ApiRequestLog $log): void
    {
        $body = (array) $log->response_body;
        $itemsPresent = array_key_exists('error_items', $body) && ! empty($body['error_items']);
        $items = $body['error_items'] ?? [];
        $structured = is_array($items) && collect($items)->contains(fn (mixed $item): bool => is_array($item)
            && ($item['error_code'] ?? null) === 'invalid_input'
            && ($item['error_field'] ?? null) === 'entityType'
            && ($item['error_field_fingerprint'] ?? null) === 'b4753588f3f6ef2b');
        $legacy = ! $itemsPresent
            && ($body['error_field_fingerprint'] ?? null) === 'b4753588f3f6ef2b'
            && substr(hash('sha256', 'entityType'), 0, 16) === 'b4753588f3f6ef2b';
        if (! $this->baseRejectedLogIsValid($log, $body, self::GENERATION_ONE_LOG_ID)
            || ($itemsPresent ? ! $structured : ! $legacy)) {
            throw new RuntimeException('Locked rejection evidence #106 is invalid.');
        }
    }

    private function assertGenerationTwoRejectedLog(ApiRequestLog $log, int $providerId): string
    {
        $body = (array) $log->response_body;
        $items = $body['error_items'] ?? null;
        $structuredExact = is_array($items) && ! empty($items)
            && collect($items)->contains(fn (mixed $item): bool => is_array($item)
                && ($item['error_code'] ?? null) === 'invalid_input'
                && ($item['error_field'] ?? null) === self::ERROR_FIELD
                && ($item['error_field_fingerprint'] ?? null) === self::ERROR_FIELD_FINGERPRINT);
        $sanitizedStructured = is_array($items)
            && array_is_list($items)
            && count($items) === 1
            && is_array($items[0])
            && ($items[0]['error_code'] ?? null) === 'invalid_input'
            && $this->fieldIsSanitizedAway($items[0])
            && ($items[0]['error_field_fingerprint'] ?? null) === self::ERROR_FIELD_FINGERPRINT
            && $this->fieldIsSanitizedAway($body)
            && ($body['error_field_fingerprint'] ?? null) === self::ERROR_FIELD_FINGERPRINT
            && substr(hash('sha256', 'proofOfAddressDocument'), 0, 16) === self::ERROR_FIELD_FINGERPRINT;
        $scopeValid = (int) $log->provider_id === $providerId
            && (int) $log->user_id === self::USER_ID
            && $log->external_reference === self::REFERENCE_ID
            && $log->operation === 'submit_kyc'
            && $log->request_method === 'POST';
        if (! $scopeValid || ! $this->baseRejectedLogIsValid($log, $body, self::GENERATION_TWO_LOG_ID)
            || (! $structuredExact && ! $sanitizedStructured)) {
            throw new RuntimeException('Locked rejection evidence #113 is invalid.');
        }

        return $structuredExact ? 'structured_exact' : 'sanitized_structured_fingerprint_113';
    }

    private function fieldIsSanitizedAway(array $value): bool
    {
        return ! array_key_exists('error_field', $value) || $value['error_field'] === null;
    }

    private function baseRejectedLogIsValid(ApiRequestLog $log, array $body, int $id): bool
    {
        return (int) $log->id === $id
            && (int) $log->response_status === 400
            && $log->is_success === false
            && $log->transport_outcome === 'response_received'
            && ($body['error_code'] ?? null) === 'invalid_input';
    }

    private function claim(): void
    {
        DB::transaction(function (): void {
            $account = UserProviderAccount::query()->whereKey(self::ACCOUNT_ID)->lockForUpdate()->firstOrFail();
            $metadata = (array) $account->metadata;
            if (($metadata[self::GENERATION_TWO_CLAIM]['state'] ?? null) !== 'rejected'
                || array_key_exists(self::CLAIM_KEY, $metadata)) {
                throw new RuntimeException('Generation #3 claim precondition failed.');
            }
            $metadata[self::CLAIM_KEY] = [
                'state' => 'submitting',
                'previous_log_id' => self::GENERATION_TWO_LOG_ID,
                'previous_http_status' => 400,
                'previous_error_code' => 'invalid_input',
                'previous_error_field' => self::ERROR_FIELD,
                'previous_error_field_fingerprint' => self::ERROR_FIELD_FINGERPRINT,
                'updated_at' => now()->toISOString(),
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
            throw new RuntimeException('Stakeholder generation #3 postcondition failed closed.');
        }

        return ['terminal' => $terminal, 'stakeholder_generation_three_post_count' => $posts];
    }

    private function newLogs(array $context, int $logMaxId)
    {
        return ApiRequestLog::query()->where('id', '>', $logMaxId)->where('provider_id', $context['provider_id'])
            ->where('user_id', self::USER_ID)->where('operation', 'submit_kyc')->where('request_method', 'POST')
            ->where('external_reference', self::REFERENCE_ID)->get();
    }

    private function validResponse(Response $response, array $context): bool
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
        $headProcess = new Process(['git', '-C', $repository, 'rev-parse', 'HEAD']);
        $headProcess->run();
        $head = $headProcess->isSuccessful() ? trim($headProcess->getOutput()) : '';
        if ($head === self::REQUIRED_PARENT_HEAD) {
            return true;
        }

        $ancestorProcess = new Process([
            'git', '-C', $repository, 'merge-base', '--is-ancestor', self::REQUIRED_PARENT_HEAD, 'HEAD',
        ]);
        $ancestorProcess->run();

        return self::lineageIsCompatible($head, $ancestorProcess->isSuccessful());
    }

    private static function lineageIsCompatible(string $head, bool $requiredParentIsAncestor): bool
    {
        return $head === self::REQUIRED_PARENT_HEAD || $requiredParentIsAncestor;
    }
}
