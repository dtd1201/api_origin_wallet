<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\KycDocument;
use App\Models\KycProfile;
use App\Models\KycRelatedPerson;
use App\Models\User;
use App\Models\UserProviderAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class NiumHkCustomerV5OneShotRunner
{
    public const EXPECTED_HEAD = '7fd4625b93c25c8562d29b1c1047b6d0c799f993';

    public const USER_ID = 9;

    public const PROFILE_ID = 9;

    public const PROVIDER_ACCOUNT_ID = 7;

    private const PROTECTED_ACCOUNT_ID = 4;

    private const DOCUMENT_IDS = [21, 22, 23];

    private const HISTORICAL_DOCUMENT_IDS = [18, 19, 20];

    private const REQUEST_LOG_BASELINE = 65;

    private const CUSTOMER_POST_BASELINE = 3;

    private const SUBMISSION_MARKER = 'nium-v5-hk-customer-create-4';

    public function __construct(
        private readonly NiumService $niumService,
        private readonly NiumCustomerPayloadFactory $payloadFactory,
        private readonly NiumCustomerDocumentResolver $documentResolver,
        private readonly NiumProviderAccountStateService $stateService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(string $executionRoot): array
    {
        $this->claimExecutionRoot($executionRoot);
        $context = $this->preflight();
        $protectedFingerprint = $this->fingerprint($context['protected_account']);
        $logMaxId = (int) (ApiRequestLog::query()->max('id') ?? 0);

        try {
            $lookupResponse = $this->niumService->get(
                path: $this->niumService->path(
                    (string) config('services.nium.customer_list_endpoint'),
                    ['client' => $this->niumService->clientId()],
                ),
                query: ['externalId' => (string) $context['account']->external_reference],
                user: $context['user'],
            );
        } catch (Throwable) {
            return $this->finish('HOLD_LOOKUP_OUTCOME_UNKNOWN', $logMaxId, $protectedFingerprint);
        }

        $lookup = $this->classifyLookup($lookupResponse, (string) $context['account']->external_reference);

        if ($lookup['terminal'] !== null) {
            if ($lookup['customer'] !== null) {
                $this->stateService->applyAuthenticatedState(
                    $context['account'],
                    $lookup['customer'],
                    'nium_v5_customer_list_response',
                );
            }

            return $this->finish($lookup['terminal'], $logMaxId, $protectedFingerprint, $lookupResponse->status());
        }

        $account = $this->claimCreate($context['payload']);
        $createLogMaxId = (int) (ApiRequestLog::query()->max('id') ?? 0);

        try {
            $createResponse = $this->niumService->post(
                path: $this->niumService->path(
                    (string) config('services.nium.customer_create_endpoint'),
                    ['client' => $this->niumService->clientId()],
                ),
                payload: $context['payload'],
                user: $context['user'],
                operation: 'customer_create',
                externalReference: (string) $account->external_reference,
            );
        } catch (ConnectionException|NiumEvidencePersistenceException) {
            $this->markCreateTerminal('customer_create_unknown', false);

            return $this->finish('STOP_CREATE_OUTCOME_UNKNOWN_NO_RETRY', $logMaxId, $protectedFingerprint);
        } catch (Throwable) {
            $definite = ApiRequestLog::query()
                ->where('id', '>', $createLogMaxId)
                ->where('operation', 'customer_create')
                ->where('request_method', 'POST')
                ->whereBetween('response_status', [400, 599])
                ->exists();
            $this->markCreateTerminal($definite ? 'customer_create_rejected' : 'customer_create_unknown', false);

            return $this->finish(
                $definite ? 'STOP_CREATE_REJECTED_NO_RETRY' : 'STOP_CREATE_OUTCOME_UNKNOWN_NO_RETRY',
                $logMaxId,
                $protectedFingerprint,
            );
        }

        if (! $createResponse->successful()) {
            $this->markCreateTerminal('customer_create_rejected', false);

            return $this->finish(
                'STOP_CREATE_REJECTED_NO_RETRY',
                $logMaxId,
                $protectedFingerprint,
                $createResponse->status(),
            );
        }

        $created = $this->responseObject($createResponse);

        if (! $this->hasCustomerIdentifier($created)) {
            $this->markCreateTerminal('customer_create_response_review', false);

            return $this->finish('HOLD_RESPONSE_REVIEW', $logMaxId, $protectedFingerprint, $createResponse->status());
        }

        $this->stateService->applyAuthenticatedState($account, $created, 'nium_v5_customer_create_response');

        return $this->finish('PASS_CUSTOMER_CREATED', $logMaxId, $protectedFingerprint, $createResponse->status());
    }

    /**
     * @return array{user: User, profile: KycProfile, account: UserProviderAccount, protected_account: UserProviderAccount, payload: array}
     */
    private function preflight(): array
    {
        if (! app()->environment('staging')) {
            throw new RuntimeException('The HK Customer V5 one-shot runner is staging-only.');
        }

        if (! $this->currentHeadIsCompatible()) {
            throw new RuntimeException('The deployed Git HEAD is not the locked Customer V5 checkpoint.');
        }

        $provider = IntegrationProvider::query()->whereRaw('LOWER(code) = ?', ['nium'])->sole();
        $user = User::query()->findOrFail(self::USER_ID);
        $profile = KycProfile::query()->whereKey(self::PROFILE_ID)->where('user_id', self::USER_ID)->firstOrFail();
        $account = UserProviderAccount::query()
            ->whereKey(self::PROVIDER_ACCOUNT_ID)
            ->where('user_id', self::USER_ID)
            ->where('provider_id', $provider->getKey())
            ->firstOrFail();
        $protectedAccount = UserProviderAccount::query()->findOrFail(self::PROTECTED_ACCOUNT_ID);

        if (filled($account->external_customer_id) || filled($account->external_account_id)) {
            throw new RuntimeException('Provider Account 7 already has a provider identifier.');
        }

        if (
            $account->reconciliation_error === NiumProviderAccountStateService::CUSTOMER_CREATE_SUBMITTING
            || Arr::get((array) $account->metadata, 'customer_v5_submission_marker') !== null
        ) {
            throw new RuntimeException('Provider Account 7 has already been claimed for Customer Create.');
        }

        if (ApiRequestLog::query()->count() !== self::REQUEST_LOG_BASELINE) {
            throw new RuntimeException('ApiRequestLog count is not the locked value 65.');
        }

        $this->assertCustomerPostCount((int) $provider->getKey(), self::CUSTOMER_POST_BASELINE);

        $payload = $this->payloadFactory->build($user, (string) $account->external_reference);
        $this->assertPayload($profile, $payload);
        $this->assertDocuments($profile, $payload);

        return compact('user', 'profile', 'account', 'protectedAccount', 'payload') + [
            'protected_account' => $protectedAccount,
        ];
    }

    private function assertPayload(KycProfile $profile, array $payload): void
    {
        NiumHkCustomerPayloadGate::assertRegions(
            config('services.nium.regulatory_region'),
            $profile->registered_country_code,
            $profile->country_code,
            Arr::get((array) $profile->metadata, 'nium_region'),
            $payload['region'] ?? null,
            $payload['registeredCountry'] ?? null,
        );

        if (($payload['type'] ?? null) !== 'corporate' || ($payload['kycType'] ?? null) !== 'full') {
            throw new RuntimeException('The locked Customer V5 payload must be corporate HK full KYC.');
        }

        if (! filter_var(data_get($payload, 'applicant.email'), FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('The Customer V5 applicant email is invalid.');
        }

        foreach ((array) data_get($payload, 'stakeholders.individual', []) as $stakeholder) {
            if (array_key_exists('email', $stakeholder) && ! filter_var($stakeholder['email'], FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('A Customer V5 stakeholder optional email is invalid.');
            }
        }

        $this->assertDeviceDetails($payload);
    }

    private function assertDocuments(KycProfile $profile, array $payload): void
    {
        $profile->loadMissing(['documents', 'relatedPersons.documents']);
        $documents = KycDocument::query()->whereIn('id', self::DOCUMENT_IDS)->where('kyc_profile_id', $profile->id)->get();
        $fileIds = $documents->map(fn (KycDocument $document): mixed => Arr::get((array) $document->metadata, 'nium_file_id'));
        $payloadFileIds = collect($payload['documents'] ?? [])
            ->merge(collect(data_get($payload, 'applicant.documents', [])))
            ->merge(collect(data_get($payload, 'stakeholders.individual', []))->flatMap(fn ($person) => $person['documents'] ?? []))
            ->flatMap(fn ($document) => $document['fileIds'] ?? [])->values();

        if (
            $documents->count() !== 3
            || $documents->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all() !== self::DOCUMENT_IDS
            || $documents->contains(fn (KycDocument $document): bool => $document->status !== 'approved')
            || $documents->contains(fn (KycDocument $document): bool => strtoupper((string) Arr::get((array) $document->metadata, 'nium_file_state')) !== 'AVAILABLE')
            || $fileIds->contains(fn ($id): bool => ! is_string($id) || ! Str::isUuid($id))
            || $fileIds->unique()->count() !== 3
            || $payloadFileIds->count() !== 3
            || $payloadFileIds->unique()->sort()->values()->all() !== $fileIds->unique()->sort()->values()->all()
            || KycDocument::query()->whereIn('id', self::HISTORICAL_DOCUMENT_IDS)->where('kyc_profile_id', $profile->id)->where('status', 'approved')->exists()
        ) {
            throw new RuntimeException('Customer V5 documents must be exactly approved AVAILABLE documents 21, 22, and 23.');
        }

        $selectedIds = $this->documentResolver->forProfile($profile)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        if ($selectedIds !== self::DOCUMENT_IDS) {
            throw new RuntimeException('The production document resolver must select exactly documents 21, 22, and 23.');
        }

        $this->assertDocumentRolePlacement($profile, $documents->keyBy('id'), $payload);
    }

    /** @param Collection<int, KycDocument> $documents */
    private function assertDocumentRolePlacement(KycProfile $profile, Collection $documents, array $payload): void
    {
        $applicants = $profile->relatedPersons->filter(fn (KycRelatedPerson $person): bool => in_array(
            strtolower((string) $person->relationship_type),
            ['applicant', 'authorized_representative', 'authorised_representative'],
            true,
        ));

        if ($applicants->count() !== 1 || (int) $applicants->first()->getKey() !== 13) {
            throw new RuntimeException('The fixture must contain exactly the production-selected applicant related person 13.');
        }

        $applicant = $applicants->first();
        $stakeholders = $profile->relatedPersons->reject(fn (KycRelatedPerson $person): bool => $person->is($applicant));

        if ($stakeholders->count() !== 1 || (int) $stakeholders->first()->getKey() !== 14) {
            throw new RuntimeException('The fixture must contain exactly the intended stakeholder related person 14.');
        }

        $stakeholder = $stakeholders->first();
        $stakeholderRelationship = str_replace(['-', ' '], '_', strtolower(trim((string) $stakeholder->relationship_type)));

        if (! str_contains($stakeholderRelationship, 'beneficial') && ! str_contains($stakeholderRelationship, 'ubo')) {
            throw new RuntimeException('Related person 14 must be the beneficial-owner stakeholder.');
        }

        $expected = [
            21 => [null, 'corporate_registration'],
            22 => [13, 'applicant_authorized_person_identity'],
            23 => [14, 'beneficial_owner_stakeholder_identity'],
        ];

        foreach ($expected as $documentId => [$relatedPersonId, $logicalRole]) {
            $document = $documents->get($documentId);

            if (
                ! $document instanceof KycDocument
                || $document->kyc_related_person_id !== $relatedPersonId
                || Arr::get((array) $document->metadata, 'logical_role') !== $logicalRole
            ) {
                throw new RuntimeException('Customer V5 document relationship or logical role is invalid.');
            }
        }

        $companyDocuments = $payload['documents'] ?? null;
        $applicantDocuments = data_get($payload, 'applicant.documents');
        $individualStakeholders = data_get($payload, 'stakeholders.individual');

        if (
            ! is_array($companyDocuments) || ! array_is_list($companyDocuments) || count($companyDocuments) !== 1
            || ! is_array($applicantDocuments) || ! array_is_list($applicantDocuments) || count($applicantDocuments) !== 1
            || ! is_array($individualStakeholders) || ! array_is_list($individualStakeholders) || count($individualStakeholders) !== 1
        ) {
            throw new RuntimeException('Customer V5 company, applicant, and stakeholder payload shapes must each contain exactly one entry.');
        }

        $stakeholderDocuments = $individualStakeholders[0]['documents'] ?? null;

        if (! is_array($stakeholderDocuments) || ! array_is_list($stakeholderDocuments) || count($stakeholderDocuments) !== 1) {
            throw new RuntimeException('The intended stakeholder payload must contain exactly one document.');
        }

        $placements = [
            21 => $companyDocuments[0]['fileIds'] ?? null,
            22 => $applicantDocuments[0]['fileIds'] ?? null,
            23 => $stakeholderDocuments[0]['fileIds'] ?? null,
        ];

        foreach ($placements as $documentId => $placedFileIds) {
            $expectedFileId = Arr::get((array) $documents->get($documentId)->metadata, 'nium_file_id');

            if (! is_array($placedFileIds) || $placedFileIds !== [$expectedFileId]) {
                throw new RuntimeException('Customer V5 payload document File ID is assigned to the wrong role.');
            }
        }
    }

    private function assertDeviceDetails(array $payload): void
    {
        if (! array_key_exists('deviceDetails', $payload)) {
            return;
        }

        $deviceDetails = $payload['deviceDetails'];

        if (! is_array($deviceDetails) || array_is_list($deviceDetails)) {
            throw new RuntimeException('Customer V5 deviceDetails must be an object when supplied.');
        }

        $sessionId = $deviceDetails['sessionId'] ?? null;

        if (! is_string($sessionId) || trim($sessionId) === '' || ! Str::isUuid($sessionId)) {
            throw new RuntimeException('Customer V5 deviceDetails.sessionId must be a valid UUID when supplied.');
        }

        if (array_key_exists('ipCountryCode', $deviceDetails) && $deviceDetails['ipCountryCode'] !== 'HK') {
            throw new RuntimeException('Customer V5 deviceDetails.ipCountryCode must match the locked HK fixture.');
        }
    }

    /** @return array{terminal: ?string, customer: ?array} */
    private function classifyLookup(Response $response, string $externalReference): array
    {
        if (! $response->successful()) {
            return ['terminal' => 'HOLD_LOOKUP_OUTCOME_UNKNOWN', 'customer' => null];
        }

        $body = $this->responseObject($response);
        $customers = $body['customers'] ?? null;

        if (! is_array($customers) || ! array_is_list($customers)) {
            return ['terminal' => 'HOLD_LOOKUP_OUTCOME_UNKNOWN', 'customer' => null];
        }

        if ($customers === []) {
            return ['terminal' => null, 'customer' => null];
        }

        if (count($customers) !== 1 || ! is_array($customers[0]) || ($customers[0]['externalId'] ?? null) !== $externalReference || ! $this->hasCustomerIdentifier($customers[0])) {
            return ['terminal' => 'HOLD_LOOKUP_OUTCOME_UNKNOWN', 'customer' => null];
        }

        return ['terminal' => 'PASS_EXISTING_CUSTOMER_FOUND', 'customer' => $customers[0]];
    }

    private function claimCreate(array $payload): UserProviderAccount
    {
        return DB::transaction(function () use ($payload): UserProviderAccount {
            $account = UserProviderAccount::query()->whereKey(self::PROVIDER_ACCOUNT_ID)->lockForUpdate()->firstOrFail();
            $providerId = (int) $account->provider_id;
            $metadata = (array) $account->metadata;

            if (
                ApiRequestLog::query()->count() !== self::REQUEST_LOG_BASELINE + 1
                || filled($account->external_customer_id)
                || filled($account->external_account_id)
                || isset($metadata['customer_v5_submission_marker'])
            ) {
                throw new RuntimeException('Customer V5 atomic submission claim failed closed.');
            }

            $this->assertCustomerPostCount($providerId, self::CUSTOMER_POST_BASELINE);
            $metadata['customer_v5_submission_marker'] = self::SUBMISSION_MARKER;
            $metadata['is_resubmission_allowed'] = false;
            $metadata['customer_v5_payload_fingerprint'] = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            $account->forceFill([
                'reconciliation_status' => 'pending',
                'reconciliation_error' => NiumProviderAccountStateService::CUSTOMER_CREATE_SUBMITTING,
                'reconciliation_requested_at' => now(),
                'metadata' => $metadata,
            ])->save();

            return $account->fresh();
        }, 3);
    }

    private function markCreateTerminal(string $state, bool $resubmissionAllowed): void
    {
        DB::transaction(function () use ($state, $resubmissionAllowed): void {
            $account = UserProviderAccount::query()->whereKey(self::PROVIDER_ACCOUNT_ID)->lockForUpdate()->firstOrFail();
            $metadata = (array) $account->metadata;
            $metadata['customer_v5_submission_state'] = $state;
            $metadata['is_resubmission_allowed'] = $resubmissionAllowed;
            $account->forceFill([
                'reconciliation_status' => 'failed',
                'reconciliation_error' => $state,
                'reconciliation_requested_at' => now(),
                'metadata' => $metadata,
            ])->save();
        });
    }

    private function finish(string $terminal, int $logMaxId, string $protectedFingerprint, ?int $httpStatus = null): array
    {
        $logs = ApiRequestLog::query()->where('id', '>', $logMaxId)->get();
        $getCount = $logs->where('request_method', 'GET')->count();
        $postCount = $logs->where('request_method', 'POST')->where('operation', 'customer_create')->count();
        $requiresCreate = in_array($terminal, [
            'PASS_CUSTOMER_CREATED',
            'STOP_CREATE_REJECTED_NO_RETRY',
            'HOLD_RESPONSE_REVIEW',
        ], true);

        if (
            $getCount > 1
            || $postCount > 1
            || ($terminal === 'PASS_EXISTING_CUSTOMER_FOUND' && ($getCount !== 1 || $postCount !== 0))
            || ($requiresCreate && ($getCount !== 1 || $postCount !== 1))
            || $this->fixtureCustomerPostCount() > self::CUSTOMER_POST_BASELINE + 1
            || $this->fingerprint(UserProviderAccount::query()->findOrFail(self::PROTECTED_ACCOUNT_ID)) !== $protectedFingerprint
        ) {
            throw new RuntimeException('Customer V5 one-shot postcondition failed closed.');
        }

        return array_filter([
            'terminal' => $terminal,
            'lookup_get_count' => $getCount,
            'customer_post_count_this_run' => $postCount,
            'api_request_log_count' => ApiRequestLog::query()->count(),
            'fixture_customer_post_count' => $this->fixtureCustomerPostCount(),
            'http_status' => $httpStatus,
            'customer_id_present' => filled(UserProviderAccount::query()->findOrFail(self::PROVIDER_ACCOUNT_ID)->external_customer_id),
            'wallet_id_present' => filled(UserProviderAccount::query()->findOrFail(self::PROVIDER_ACCOUNT_ID)->external_account_id),
        ], static fn ($value): bool => $value !== null);
    }

    private function claimExecutionRoot(string $executionRoot): void
    {
        $root = realpath($executionRoot);
        $repository = realpath(base_path());

        if ($root === false || ! is_dir($root) || $repository === false || $root === $repository || str_starts_with($root, $repository.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('An existing execution root outside the repository is required.');
        }

        $marker = $root.DIRECTORY_SEPARATOR.'EXECUTION_STARTED';
        $handle = @fopen($marker, 'x');

        if ($handle === false) {
            throw new RuntimeException('EXECUTION_STARTED already exists; the one-shot runner is closed.');
        }

        fwrite($handle, self::SUBMISSION_MARKER.PHP_EOL);
        fclose($handle);
    }

    private function responseObject(Response $response): array
    {
        try {
            $decoded = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : [];
    }

    private function hasCustomerIdentifier(array $data): bool
    {
        $customer = $data['customerHashId'] ?? null;

        return is_string($customer) && trim($customer) !== '';
    }

    private function assertCustomerPostCount(int $providerId, int $expected): void
    {
        if (ApiRequestLog::query()->where('provider_id', $providerId)->where('user_id', self::USER_ID)->where('operation', 'customer_create')->where('request_method', 'POST')->count() !== $expected) {
            throw new RuntimeException("Fixture Customer Create POST count is not the locked value {$expected}.");
        }
    }

    private function fixtureCustomerPostCount(): int
    {
        $providerId = (int) IntegrationProvider::query()->whereRaw('LOWER(code) = ?', ['nium'])->sole()->getKey();

        return ApiRequestLog::query()->where('provider_id', $providerId)->where('user_id', self::USER_ID)->where('operation', 'customer_create')->where('request_method', 'POST')->count();
    }

    private function fingerprint(UserProviderAccount $account): string
    {
        $attributes = $account->getRawOriginal();
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
    }

    private function currentHeadIsCompatible(): bool
    {
        $repository = escapeshellarg(base_path());
        $expected = escapeshellarg(self::EXPECTED_HEAD);
        $head = trim((string) shell_exec("git -C {$repository} rev-parse HEAD 2>/dev/null"));

        if ($head === self::EXPECTED_HEAD) {
            return true;
        }

        exec("git -C {$repository} merge-base --is-ancestor {$expected} HEAD 2>/dev/null", $output, $exitCode);

        return $exitCode === 0;
    }
}
