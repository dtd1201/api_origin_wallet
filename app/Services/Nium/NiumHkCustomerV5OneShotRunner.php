<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\KycDocument;
use App\Models\KycProfile;
use App\Models\User;
use App\Models\UserProviderAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class NiumHkCustomerV5OneShotRunner
{
    public const EXPECTED_HEAD = 'b18c191be8303f8111adeb5737443211017282ff';

    public const LOCKED_PAYLOAD_SHA256 = 'ec95fad0d7560c528173cbaf9317bb80ea843faef39dbc6facb33c915a40c571';

    public const USER_ID = 9;

    public const PROFILE_ID = 9;

    public const PROVIDER_ACCOUNT_ID = 7;

    private const PROTECTED_ACCOUNT_ID = 4;

    private const DOCUMENT_IDS = [24, 25];

    private const HISTORICAL_DOCUMENT_IDS = [18, 19, 20, 21, 22, 23];

    private const REQUEST_LOG_BASELINE = 91;

    private const CUSTOMER_POST_BASELINE = 6;

    private const PREVIOUS_SUBMISSION_MARKER = 'nium-v5-hk-customer-create-6-factual-business-type-v2';

    private const SUBMISSION_MARKER = 'nium-v5-hk-customer-create-7-factual-declaration-timestamp-v3';

    private const PREVIOUS_PAYLOAD_SHA256 = '767d875a5fbcd468c186bf0f045349f36d019694a77b18627ec4a3a468732ad9';

    private const PREVIOUS_ERROR_FIELD_FINGERPRINT = '30f867e6e24cd4c9';

    public function __construct(
        private readonly NiumService $niumService,
        private readonly NiumCustomerPayloadFactory $payloadFactory,
        private readonly NiumCustomerDocumentResolver $documentResolver,
        private readonly NiumProviderAccountStateService $stateService,
        private readonly NiumHkCorporateV5Validator $hkValidator,
        private readonly string $lockedPayloadSha256 = self::LOCKED_PAYLOAD_SHA256,
        private readonly bool $validatePayloadWithHkValidator = true,
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

        $this->assertPreviousSubmissionState($account, (int) $provider->getKey());

        if (ApiRequestLog::query()->count() !== self::REQUEST_LOG_BASELINE) {
            throw new RuntimeException('ApiRequestLog count is not the locked value 91.');
        }

        $this->assertCustomerPostCount((int) $provider->getKey(), self::CUSTOMER_POST_BASELINE);

        $payload = $this->payloadFactory->build($user, (string) $account->external_reference);
        if ($this->validatePayloadWithHkValidator) {
            $this->hkValidator->assert($profile, $payload);
        }
        $this->assertPayload($profile, $payload);
        $this->assertDocuments($profile, $payload);

        if ($this->payloadFingerprint($payload) !== $this->lockedPayloadSha256) {
            throw new RuntimeException('The factual Customer V5 payload SHA-256 does not match the locked checkpoint.');
        }

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

        if (($payload['type'] ?? null) !== 'corporate'
            || ($payload['kycType'] ?? null) !== 'full'
            || ($payload['businessType'] ?? null) !== 'private_company'
            || ! array_key_exists('applicantDeclarationTimeStamp', $payload)
            || array_key_exists('applicantDeclarationTimestamp', $payload)) {
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

        $this->assertFactualPeople($payload);
        $this->assertDeviceDetails($profile, $payload);
    }

    private function assertDocuments(KycProfile $profile, array $payload): void
    {
        $profile->loadMissing(['documents', 'relatedPersons']);
        $documents = KycDocument::query()
            ->whereIn('id', self::DOCUMENT_IDS)
            ->where('kyc_profile_id', $profile->id)
            ->orderBy('id')
            ->get();
        $historicalDocuments = KycDocument::query()
            ->whereIn('id', self::HISTORICAL_DOCUMENT_IDS)
            ->where('kyc_profile_id', $profile->id)
            ->orderBy('id')
            ->get();
        $fileIds = $documents->map(fn (KycDocument $document): mixed => Arr::get((array) $document->metadata, 'nium_file_id'));
        $payloadDocuments = $payload['documents'] ?? null;
        $payloadFileIds = collect(is_array($payloadDocuments) ? $payloadDocuments : [])
            ->flatMap(fn ($document) => $document['fileIds'] ?? [])->values();

        if (
            $documents->count() !== 2
            || $documents->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all() !== self::DOCUMENT_IDS
            || $documents->keyBy('id')->map(fn (KycDocument $document): string => $this->factualDocumentType($document))->all() !== [
                24 => 'nar1',
                25 => 'business_registration_doc',
            ]
            || $documents->contains(fn (KycDocument $document): bool => $document->status !== 'approved')
            || $documents->contains(fn (KycDocument $document): bool => $document->kyc_related_person_id !== null)
            || $documents->contains(fn (KycDocument $document): bool => strtoupper((string) Arr::get((array) $document->metadata, 'nium_file_state')) !== 'AVAILABLE')
            || $fileIds->contains(fn ($id): bool => ! is_string($id) || ! Str::isUuid($id))
            || $fileIds->unique()->count() !== 2
            || ! is_array($payloadDocuments) || ! array_is_list($payloadDocuments) || count($payloadDocuments) !== 2
            || $payloadFileIds->count() !== 2
            || $payloadFileIds->unique()->sort()->values()->all() !== $fileIds->unique()->sort()->values()->all()
            || $historicalDocuments->count() !== 6
            || $historicalDocuments->pluck('id')->map(fn ($id): int => (int) $id)->values()->all() !== self::HISTORICAL_DOCUMENT_IDS
            || $historicalDocuments->contains(fn (KycDocument $document): bool => $document->status !== 'superseded')
        ) {
            throw new RuntimeException('Customer V5 documents must be exactly approved AVAILABLE corporate documents 24 and 25.');
        }

        $selectedIds = $this->documentResolver->forProfile($profile)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        if ($selectedIds !== self::DOCUMENT_IDS) {
            throw new RuntimeException('The production document resolver must select exactly documents 24 and 25.');
        }

        if (array_key_exists('documents', $payload['applicant'] ?? [])
            || collect(data_get($payload, 'stakeholders.individual', []))->contains(
                fn ($stakeholder): bool => is_array($stakeholder) && array_key_exists('documents', $stakeholder),
            )) {
            throw new RuntimeException('Customer Create must not include applicant or stakeholder identity documents.');
        }
    }

    private function factualDocumentType(KycDocument $document): string
    {
        return strtolower(trim((string) (Arr::get((array) $document->metadata, 'nium_document_type') ?? $document->type)));
    }

    private function assertDeviceDetails(KycProfile $profile, array $payload): void
    {
        if (! array_key_exists('deviceDetails', $payload)) {
            throw new RuntimeException('Customer V5 deviceDetails is required for HK Corporate Full KYC.');
        }

        $deviceDetails = $payload['deviceDetails'];

        if (! is_array($deviceDetails) || array_is_list($deviceDetails)) {
            throw new RuntimeException('Customer V5 deviceDetails must be an object when supplied.');
        }

        foreach (['ipCountryCode', 'deviceInfo', 'ipAddress', 'sessionId'] as $field) {
            if (! is_string($deviceDetails[$field] ?? null) || trim($deviceDetails[$field]) === '') {
                throw new RuntimeException("Customer V5 deviceDetails.{$field} must be a non-empty string.");
            }
        }

        $approvedDeviceDetails = Arr::get((array) $profile->metadata, 'nium_v5_fields.deviceDetails');

        if (! is_array($approvedDeviceDetails) || $deviceDetails !== $approvedDeviceDetails) {
            throw new RuntimeException('Customer V5 deviceDetails must exactly match approved factual metadata.');
        }

        if (filter_var($deviceDetails['ipAddress'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException('Customer V5 deviceDetails.ipAddress must be a valid IPv4 address.');
        }

        if (! Str::isUuid($deviceDetails['sessionId'])) {
            throw new RuntimeException('Customer V5 deviceDetails.sessionId must be a UUID.');
        }
    }

    private function assertFactualPeople(array $payload): void
    {
        $applicant = $payload['applicant'] ?? null;
        $stakeholders = data_get($payload, 'stakeholders.individual');

        if (! is_array($applicant)
            || ! $this->isExactFullOwnership($applicant['sharePercentage'] ?? null)
            || $this->positionTitles($applicant) !== ['director', 'representative', 'shareholder', 'ubo']) {
            throw new RuntimeException('The factual applicant ownership or positions do not match the locked checkpoint.');
        }

        if (! is_array($stakeholders) || ! array_is_list($stakeholders) || count($stakeholders) !== 1
            || ! is_array($stakeholders[0])
            || ! $this->isExactFullOwnership($stakeholders[0]['sharePercentage'] ?? null)
            || $this->positionTitles($stakeholders[0]) !== ['director', 'shareholder', 'ubo']) {
            throw new RuntimeException('The factual stakeholder ownership or positions do not match the locked checkpoint.');
        }
    }

    private function isExactFullOwnership(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && (float) $value === 100.0;
    }

    /** @return list<string> */
    private function positionTitles(array $person): array
    {
        return collect($person['positions'] ?? [])
            ->pluck('title')
            ->filter(fn ($title): bool => is_string($title))
            ->map(fn (string $title): string => NiumHkCorporateV5Validator::documentRoleKey($title))
            ->sort()
            ->values()
            ->all();
    }

    private function assertPreviousSubmissionState(UserProviderAccount $account, int $providerId): void
    {
        $metadata = (array) $account->metadata;

        if (Arr::get($metadata, 'customer_v5_submission_marker') !== self::PREVIOUS_SUBMISSION_MARKER
            || Arr::get($metadata, 'customer_v5_submission_state') !== 'customer_create_rejected'
            || Arr::get($metadata, 'customer_v5_payload_fingerprint') !== self::PREVIOUS_PAYLOAD_SHA256
            || $account->reconciliation_status !== 'failed'
            || $account->reconciliation_error !== 'customer_create_rejected'
            || Arr::get($metadata, 'is_resubmission_allowed') !== false
            || ! is_array(Arr::get($metadata, 'customer_v5_previous_submission'))
            || array_is_list(Arr::get($metadata, 'customer_v5_previous_submission'))) {
            throw new RuntimeException('Provider Account 7 does not match the locked generation #6 rejected state.');
        }

        $previousSubmission = Arr::get($metadata, 'customer_v5_previous_submission');
        $history = Arr::get($metadata, 'customer_v5_submission_history');

        if (Arr::get($previousSubmission, 'submission_marker') !== 'nium-v5-hk-customer-create-5-factual-v1'
            || ! is_array($history)
            || ! array_is_list($history)
            || collect($history)->pluck('submission_marker')->all() !== [
                'nium-v5-hk-customer-create-4',
                'nium-v5-hk-customer-create-5-factual-v1',
            ]) {
            throw new RuntimeException('Provider Account 7 does not preserve the locked generation #4 and #5 provenance.');
        }

        $this->assertCustomerPostCount($providerId, self::CUSTOMER_POST_BASELINE);
        $lastPost = ApiRequestLog::query()
            ->where('provider_id', $providerId)
            ->where('user_id', self::USER_ID)
            ->where('operation', 'customer_create')
            ->where('request_method', 'POST')
            ->latest('id')
            ->first();

        if (! $lastPost instanceof ApiRequestLog
            || (int) $lastPost->getKey() !== 91
            || (int) $lastPost->response_status !== 400
            || $lastPost->is_success !== false
            || $lastPost->transport_outcome !== 'response_received'
            || data_get($lastPost->response_body, 'error_field_fingerprint') !== self::PREVIOUS_ERROR_FIELD_FINGERPRINT) {
            throw new RuntimeException('The last generation #6 Customer Create POST does not prove the locked declaration timestamp rejection.');
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
            ) {
                throw new RuntimeException('Customer V5 atomic submission claim failed closed.');
            }

            $this->assertPreviousSubmissionState($account, $providerId);
            $previousSubmission = [
                'submission_marker' => Arr::get($metadata, 'customer_v5_submission_marker'),
                'submission_state' => Arr::get($metadata, 'customer_v5_submission_state'),
                'payload_fingerprint' => Arr::get($metadata, 'customer_v5_payload_fingerprint'),
                'reconciliation_status' => $account->reconciliation_status,
                'reconciliation_error' => $account->reconciliation_error,
                'is_resubmission_allowed' => Arr::get($metadata, 'is_resubmission_allowed'),
                'provider_response_status' => 400,
                'provider_error_field_fingerprint' => self::PREVIOUS_ERROR_FIELD_FINGERPRINT,
            ];
            $history = Arr::get($metadata, 'customer_v5_submission_history', []);

            if (! is_array($history) || ! array_is_list($history)) {
                throw new RuntimeException('Customer V5 submission history is malformed.');
            }

            $history[] = $previousSubmission;
            $metadata['customer_v5_submission_history'] = $history;
            $metadata['customer_v5_previous_submission'] = $previousSubmission;
            $metadata['customer_v5_submission_marker'] = self::SUBMISSION_MARKER;
            unset($metadata['customer_v5_submission_state']);
            $metadata['is_resubmission_allowed'] = false;
            $metadata['customer_v5_payload_fingerprint'] = $this->payloadFingerprint($payload);
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

    private function payloadFingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
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
