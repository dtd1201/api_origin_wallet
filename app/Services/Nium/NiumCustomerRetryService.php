<?php

namespace App\Services\Nium;

use App\Models\AuditLog;
use App\Models\IntegrationProvider;
use App\Models\KycProviderSubmission;
use App\Models\User;
use App\Models\UserProviderAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Throwable;
use WeakMap;

final class NiumCustomerRetryService
{
    public const USER_ID = 6;

    public const PROVIDER_ACCOUNT_ID = 4;

    public const PROVIDER_ID = 7;

    public const SUBMISSION_ID = 2;

    private const LOCAL_PERSISTENCE_FAILURE = 'HOLD_LOCAL_PERSISTENCE_FAILED';

    private const PROVIDER_STATUSES = [
        'pending',
        'clear',
        'closed',
        'terminated',
        'suspended',
        'blocked',
        'rejected',
        'failed',
        'error',
    ];

    private const PROVIDER_SUB_STATUSES = [
        'under_review',
        'rfi_requested',
        'awaiting_kyc',
    ];

    private const COMPLIANCE_STATUSES = [
        'pending',
        'completed',
        'failed',
        'action_required',
    ];

    private const ODD_STATUSES = [
        'pending',
        'odd_due',
        'completed',
        'clear',
    ];

    /**
     * @var WeakMap<IntegrationProvider, array{
     *     security_fingerprint: string,
     *     consumed: bool
     * }>
     */
    private WeakMap $providerProvenance;

    /**
     * @var WeakMap<User, array{
     *     capability_token: string,
     *     user_id: int,
     *     user_object_id: int,
     *     provider_account_id: int,
     *     provider_account_object_id: int,
     *     user_security_fingerprint: string,
     *     account_security_fingerprint: string,
     *     external_reference_fingerprint: string,
     *     submission_security_fingerprint: string,
     *     consumed: bool
     * }>
     */
    private WeakMap $fixtureUserProvenance;

    /**
     * @var WeakMap<UserProviderAccount, array{
     *     capability_token: string,
     *     user_id: int,
     *     user_object_id: int,
     *     provider_account_id: int,
     *     provider_account_object_id: int,
     *     user_security_fingerprint: string,
     *     account_security_fingerprint: string,
     *     external_reference_fingerprint: string,
     *     submission_security_fingerprint: string,
     *     consumed: bool
     * }>
     */
    private WeakMap $fixtureAccountProvenance;

    /**
     * @var WeakMap<NiumCustomerLookupResult, array{
     *     user_id: int,
     *     user_object_id: int,
     *     user_security_fingerprint: string,
     *     provider_account_id: int,
     *     provider_account_object_id: int,
     *     account_security_fingerprint: string,
     *     provider_id: int,
     *     provider_object_id: int,
     *     provider_security_fingerprint: string,
     *     external_reference_fingerprint: string,
     *     submission_security_fingerprint: string,
     *     lookup_state: string,
     *     consumed: bool
     * }>
     */
    private WeakMap $lookupProvenance;

    /**
     * @var WeakMap<NiumCustomerLookupResult|NiumCustomerCreateResult, array{
     *     result_class: class-string,
     *     result_state: string,
     *     user_id: int,
     *     user_object_id: int,
     *     user_security_fingerprint: string,
     *     provider_account_id: int,
     *     provider_account_object_id: int,
     *     account_security_fingerprint: string,
     *     provider_id: int,
     *     provider_object_id: int,
     *     provider_security_fingerprint: string,
     *     external_reference_fingerprint: string,
     *     submission_security_fingerprint: string,
     *     authenticated_state: array,
     *     persistence_source: string,
     *     consumed: bool
     * }>
     */
    private WeakMap $successCapabilities;

    public function __construct(
        private readonly NiumService $niumService,
        private readonly NiumProviderHttpClientFactory $clientFactory,
        private readonly NiumCustomerPayloadHashVerifier $payloadHashVerifier,
        private readonly NiumCustomerErrorMapper $errorMapper,
        private readonly NiumAuthenticatedStateProjector $authenticatedStateProjector,
    ) {
        $this->providerProvenance = new WeakMap;
        $this->fixtureUserProvenance = new WeakMap;
        $this->fixtureAccountProvenance = new WeakMap;
        $this->lookupProvenance = new WeakMap;
        $this->successCapabilities = new WeakMap;
    }

    /**
     * @return array{0: User, 1: UserProviderAccount}
     */
    public function resolveFixtureContext(): array
    {
        $user = User::query()->find(self::USER_ID);
        $providerAccount = UserProviderAccount::query()->find(self::PROVIDER_ACCOUNT_ID);
        $submission = KycProviderSubmission::query()->find(self::SUBMISSION_ID);

        if (
            ! $user instanceof User
            || ! $providerAccount instanceof UserProviderAccount
            || ! $submission instanceof KycProviderSubmission
            || ! $this->isUsableFixtureUser($user)
            || ! $this->isSafeRetryAccount($providerAccount)
            || ! $this->isSafeRetrySubmission($submission)
        ) {
            throw new RuntimeException('Nium customer retry fixture context is unavailable.');
        }

        $externalReference = (string) $providerAccount->external_reference;
        $capabilityToken = hash('sha256', random_bytes(32));
        $capability = [
            'capability_token' => $capabilityToken,
            'user_id' => self::USER_ID,
            'user_object_id' => spl_object_id($user),
            'provider_account_id' => self::PROVIDER_ACCOUNT_ID,
            'provider_account_object_id' => spl_object_id($providerAccount),
            'user_security_fingerprint' => $this->userSecurityFingerprint($user),
            'account_security_fingerprint' => $this->accountSecurityFingerprint($providerAccount),
            'external_reference_fingerprint' => $this->externalReferenceFingerprint($externalReference),
            'submission_security_fingerprint' => $this->submissionSecurityFingerprint($submission),
            'consumed' => false,
        ];

        $this->fixtureUserProvenance[$user] = $capability;
        $this->fixtureAccountProvenance[$providerAccount] = $capability;

        return [$user, $providerAccount];
    }

    public function resolveProvider(): IntegrationProvider
    {
        $provider = IntegrationProvider::query()->find(self::PROVIDER_ID);

        if (
            ! $provider instanceof IntegrationProvider
            || strtolower(trim((string) $provider->code)) !== 'nium'
            || $provider->status !== 'active'
            || ! $provider->isConfigured()
        ) {
            throw new RuntimeException('Nium customer retry provider is unavailable.');
        }

        $securityFingerprint = $this->providerSecurityFingerprint($provider);

        if ($securityFingerprint === null) {
            throw new RuntimeException('Nium customer retry provider is unavailable.');
        }

        $this->providerProvenance[$provider] = [
            'security_fingerprint' => $securityFingerprint,
            'consumed' => false,
        ];

        return $provider;
    }

    public function lookupByExternalReference(
        User $user,
        UserProviderAccount $providerAccount,
        IntegrationProvider $provider,
    ): NiumCustomerLookupResult {
        $fixtureCapability = $this->consumeFixtureCapability($user, $providerAccount);
        $providerCapability = $this->consumeProviderCapability($provider);

        if ($providerCapability === null) {
            return NiumCustomerLookupResult::failed(null, 'lookup_provider_provenance_invalid');
        }

        $capabilityContext = [
            ...$fixtureCapability,
            'provider_id' => (int) $provider->getKey(),
            'provider_object_id' => spl_object_id($provider),
            'provider_security_fingerprint' => $providerCapability['security_fingerprint'],
        ];
        $this->assertFreshFixtureContext($capabilityContext);

        if (! $providerCapability['current_object_valid']) {
            return NiumCustomerLookupResult::failed(null, 'lookup_provider_provenance_invalid');
        }

        $externalReference = (string) $providerAccount->external_reference;

        try {
            $response = $this->clientFactory->make($provider)->get(
                path: $this->customerEndpoint('services.nium.customer_list_endpoint'),
                query: ['externalId' => $externalReference],
                user: $user,
            );
        } catch (Throwable) {
            return NiumCustomerLookupResult::failed(null, 'lookup_transport_failed');
        }

        $httpStatus = $this->safeHttpStatus($response);

        if (! $response->successful()) {
            return NiumCustomerLookupResult::failed(
                $httpStatus,
                $this->httpFailureCategory($response->status(), 'lookup'),
            );
        }

        $data = $this->decodeObject($response);

        if ($data === null) {
            return NiumCustomerLookupResult::failed($httpStatus, 'lookup_response_decode_failed');
        }

        if (! array_key_exists('customers', $data)) {
            return NiumCustomerLookupResult::ambiguous(
                $httpStatus,
                false,
                false,
                'lookup_customers_missing',
            );
        }

        $customers = $data['customers'];

        if (! is_array($customers) || ! array_is_list($customers)) {
            return NiumCustomerLookupResult::ambiguous(
                $httpStatus,
                false,
                false,
                'lookup_customers_not_list',
            );
        }

        if ($customers === []) {
            $result = NiumCustomerLookupResult::absent($httpStatus);
            $this->lookupProvenance[$result] = [
                ...$capabilityContext,
                'lookup_state' => NiumCustomerLookupState::Absent->value,
                'consumed' => false,
            ];

            return $result;
        }

        if (count($customers) !== 1 || ! is_array($customers[0]) || array_is_list($customers[0])) {
            return NiumCustomerLookupResult::ambiguous(
                $httpStatus,
                false,
                false,
                'lookup_customer_count_invalid',
            );
        }

        $customer = $customers[0];
        $incomingReference = $customer['externalId'] ?? null;

        if (! is_string($incomingReference) || ! hash_equals($externalReference, $incomingReference)) {
            return NiumCustomerLookupResult::ambiguous(
                $httpStatus,
                false,
                false,
                'lookup_external_reference_mismatch',
            );
        }

        $customerId = $this->identifier($customer['customerHashId'] ?? null);
        $walletId = $this->walletIdentifier($customer);
        $customerPresent = $customerId !== null;
        $walletPresent = $walletId !== null;

        if (! $customerPresent || ! $walletPresent) {
            return NiumCustomerLookupResult::ambiguous(
                $httpStatus,
                $customerPresent,
                $walletPresent,
                'lookup_identifiers_missing',
            );
        }

        if ($this->hasIdentifierConflict($providerAccount, $customerId, $walletId)) {
            return NiumCustomerLookupResult::ambiguous(
                $httpStatus,
                true,
                true,
                'lookup_identifier_conflict',
            );
        }

        $result = NiumCustomerLookupResult::existing($httpStatus);
        $this->successCapabilities[$result] = [
            ...$capabilityContext,
            'result_class' => NiumCustomerLookupResult::class,
            'result_state' => NiumCustomerLookupState::Existing->value,
            'authenticated_state' => $this->authenticatedStateProjection(
                $customer,
                $customerId,
                $walletId,
            ),
            'persistence_source' => 'nium_v5_customer_list_response',
            'consumed' => false,
        ];

        return $result;
    }

    public function createCustomer(
        User $user,
        UserProviderAccount $providerAccount,
        IntegrationProvider $provider,
        NiumCustomerLookupResult $lookupResult,
        array $payload,
    ): NiumCustomerCreateResult {
        $externalReference = $this->fixtureExternalReference($user, $providerAccount);
        $capability = $this->lookupProvenance[$lookupResult] ?? null;

        if (is_array($capability)) {
            unset($this->lookupProvenance[$lookupResult]);
        }

        if (! $this->validLookupCapability(
            $lookupResult,
            $user,
            $providerAccount,
            $provider,
            $externalReference,
            $capability,
        )) {
            return NiumCustomerCreateResult::failed(null, 'create_lookup_provenance_invalid');
        }

        $this->assertFreshFixtureContext($capability);

        if (! $this->providerObjectMatchesSecurityFingerprint(
            $provider,
            $capability['provider_security_fingerprint'],
        )) {
            return NiumCustomerCreateResult::failed(null, 'create_lookup_provenance_invalid');
        }

        try {
            $payloadApproved = $this->payloadHashVerifier->matchesApproved($payload);
        } catch (Throwable) {
            unset($payload);

            return NiumCustomerCreateResult::failed(null, 'payload_verification_failed');
        }

        if (! $payloadApproved) {
            unset($payload);

            return NiumCustomerCreateResult::failed(null, 'payload_hash_mismatch');
        }

        try {
            $response = $this->clientFactory->make($provider)->post(
                path: $this->customerEndpoint('services.nium.customer_create_endpoint'),
                payload: $payload,
                user: $user,
            );
        } catch (Throwable) {
            unset($payload);

            return NiumCustomerCreateResult::failed(null, 'create_transport_failed');
        }

        unset($payload);
        $httpStatus = $this->safeHttpStatus($response);

        try {
            if ($this->errorMapper->isDuplicateExternalId($response)) {
                return NiumCustomerCreateResult::duplicate($httpStatus);
            }
        } catch (Throwable) {
            return NiumCustomerCreateResult::failed($httpStatus, 'create_mapper_failed');
        }

        if (! $response->successful()) {
            return NiumCustomerCreateResult::failed(
                $httpStatus,
                $this->httpFailureCategory($response->status(), 'create'),
            );
        }

        $data = $this->decodeObject($response);

        if ($data === null) {
            return NiumCustomerCreateResult::invalidResponse(
                $httpStatus,
                false,
                false,
                'create_response_shape_invalid',
            );
        }

        $customerId = $this->identifier($data['customerHashId'] ?? null);
        $walletId = $this->walletIdentifier($data);
        $customerPresent = $customerId !== null;
        $walletPresent = $walletId !== null;

        if (! $customerPresent || ! $walletPresent) {
            return NiumCustomerCreateResult::invalidResponse(
                $httpStatus,
                $customerPresent,
                $walletPresent,
                'create_identifiers_missing',
            );
        }

        if (
            array_key_exists('externalId', $data)
            && (! is_string($data['externalId']) || ! hash_equals($externalReference, $data['externalId']))
        ) {
            return NiumCustomerCreateResult::invalidResponse(
                $httpStatus,
                true,
                true,
                'create_external_reference_mismatch',
            );
        }

        if ($this->hasIdentifierConflict($providerAccount, $customerId, $walletId)) {
            return NiumCustomerCreateResult::invalidResponse(
                $httpStatus,
                true,
                true,
                'create_identifier_conflict',
            );
        }

        $result = NiumCustomerCreateResult::created($httpStatus);
        $this->successCapabilities[$result] = [
            ...$capability,
            'result_class' => NiumCustomerCreateResult::class,
            'result_state' => NiumCustomerCreateState::Created->value,
            'authenticated_state' => $this->authenticatedStateProjection(
                $data,
                $customerId,
                $walletId,
            ),
            'persistence_source' => 'nium_v5_customer_create_response',
            'consumed' => false,
        ];

        return $result;
    }

    public function persistAuthenticatedCustomer(
        UserProviderAccount $providerAccount,
        NiumCustomerLookupResult|NiumCustomerCreateResult $result,
    ): UserProviderAccount {
        $capability = $this->successCapabilities[$result] ?? null;

        if (! $this->validSuccessCapability($providerAccount, $result, $capability)) {
            throw new RuntimeException('Nium customer retry result cannot be persisted.');
        }

        unset($this->successCapabilities[$result]);

        try {
            return DB::transaction(function () use ($capability): UserProviderAccount {
                $lockedAccount = UserProviderAccount::query()
                    ->whereKey(self::PROVIDER_ACCOUNT_ID)
                    ->lockForUpdate()
                    ->first();
                $lockedSubmission = KycProviderSubmission::query()
                    ->whereKey(self::SUBMISSION_ID)
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $lockedAccount instanceof UserProviderAccount
                    || ! $lockedSubmission instanceof KycProviderSubmission
                    || ! $this->isSafeRetryAccount($lockedAccount)
                    || ! $this->isSafeRetrySubmission($lockedSubmission)
                    || ! hash_equals(
                        $capability['external_reference_fingerprint'],
                        $this->externalReferenceFingerprint(
                            (string) $lockedAccount->external_reference,
                        ),
                    )
                    || ! hash_equals(
                        $capability['account_security_fingerprint'],
                        $this->accountSecurityFingerprint($lockedAccount),
                    )
                    || ! hash_equals(
                        $capability['submission_security_fingerprint'],
                        $this->submissionSecurityFingerprint($lockedSubmission),
                    )
                ) {
                    throw new RuntimeException(self::LOCAL_PERSISTENCE_FAILURE);
                }

                $before = $this->authenticatedStateProjector->auditState($lockedAccount);
                $lockedAccount->update(
                    $this->authenticatedStateProjector->accountUpdates(
                        $lockedAccount,
                        $capability['authenticated_state'],
                        $capability['persistence_source'],
                    ),
                );
                $lockedAccount = $lockedAccount->fresh();
                $lockedSubmission->update(
                    $this->authenticatedStateProjector->submissionUpdates($lockedAccount),
                );
                $after = $this->authenticatedStateProjector->auditState($lockedAccount);

                if ($before !== $after) {
                    AuditLog::query()->create([
                        'user_id' => $lockedAccount->user_id,
                        'action' => 'provider_account.nium_state_changed',
                        'entity_type' => 'user_provider_account',
                        'entity_id' => (string) $lockedAccount->id,
                        'old_data' => $before,
                        'new_data' => [
                            ...$after,
                            'source' => $capability['persistence_source'],
                        ],
                        'ip_address' => null,
                        'user_agent' => null,
                    ]);
                }

                return $lockedAccount;
            });
        } catch (Throwable $exception) {
            if (
                $exception instanceof RuntimeException
                && $exception->getMessage() === self::LOCAL_PERSISTENCE_FAILURE
            ) {
                throw $exception;
            }

            throw new RuntimeException(self::LOCAL_PERSISTENCE_FAILURE, 0, $exception);
        }
    }

    private function validLookupCapability(
        NiumCustomerLookupResult $result,
        User $user,
        UserProviderAccount $providerAccount,
        IntegrationProvider $provider,
        string $externalReference,
        mixed $capability,
    ): bool {
        return $result->state === NiumCustomerLookupState::Absent
            && is_array($capability)
            && $capability['consumed'] === false
            && $capability['lookup_state'] === NiumCustomerLookupState::Absent->value
            && $capability['provider_object_id'] === spl_object_id($provider)
            && $capability['user_object_id'] === spl_object_id($user)
            && $capability['provider_account_object_id'] === spl_object_id($providerAccount)
            && $this->capabilityContextMatches(
                $capability,
                $user,
                $providerAccount,
                $provider,
                $externalReference,
            );
    }

    private function validSuccessCapability(
        UserProviderAccount $providerAccount,
        NiumCustomerLookupResult|NiumCustomerCreateResult $result,
        mixed $capability,
    ): bool {
        if (
            (int) $providerAccount->getKey() !== self::PROVIDER_ACCOUNT_ID
            || (int) $providerAccount->user_id !== self::USER_ID
            || (int) $providerAccount->provider_id !== self::PROVIDER_ID
            || ! is_array($capability)
            || $capability['consumed'] !== false
            || $capability['user_id'] !== self::USER_ID
            || $capability['provider_account_object_id'] !== spl_object_id($providerAccount)
            || $capability['provider_account_id'] !== self::PROVIDER_ACCOUNT_ID
            || $capability['provider_id'] !== self::PROVIDER_ID
            || ! is_array($capability['authenticated_state'])
            || ! is_string($capability['persistence_source'])
            || ! hash_equals(
                $capability['account_security_fingerprint'],
                $this->accountSecurityFingerprint($providerAccount),
            )
            || ! hash_equals(
                $capability['external_reference_fingerprint'],
                $this->externalReferenceFingerprint((string) $providerAccount->external_reference),
            )
        ) {
            return false;
        }

        return match (true) {
            $result instanceof NiumCustomerLookupResult => $result->state === NiumCustomerLookupState::Existing
                && $capability['result_class'] === NiumCustomerLookupResult::class
                && $capability['result_state'] === NiumCustomerLookupState::Existing->value
                && $capability['persistence_source'] === 'nium_v5_customer_list_response',
            $result instanceof NiumCustomerCreateResult => $result->state === NiumCustomerCreateState::Created
                && $capability['result_class'] === NiumCustomerCreateResult::class
                && $capability['result_state'] === NiumCustomerCreateState::Created->value
                && $capability['persistence_source'] === 'nium_v5_customer_create_response',
        };
    }

    private function capabilityContextMatches(
        array $capability,
        User $user,
        UserProviderAccount $providerAccount,
        IntegrationProvider $provider,
        string $externalReference,
    ): bool {
        return $capability['user_id'] === (int) $user->getKey()
            && $capability['user_object_id'] === spl_object_id($user)
            && hash_equals(
                $capability['user_security_fingerprint'],
                $this->userSecurityFingerprint($user),
            )
            && $capability['provider_account_id'] === (int) $providerAccount->getKey()
            && $capability['provider_account_object_id'] === spl_object_id($providerAccount)
            && hash_equals(
                $capability['account_security_fingerprint'],
                $this->accountSecurityFingerprint($providerAccount),
            )
            && $capability['provider_id'] === (int) $provider->getKey()
            && hash_equals(
                $capability['external_reference_fingerprint'],
                $this->externalReferenceFingerprint($externalReference),
            );
    }

    private function externalReferenceFingerprint(string $externalReference): string
    {
        return hash('sha256', $externalReference);
    }

    private function userSecurityFingerprint(User $user): string
    {
        return hash('sha256', json_encode([
            'id' => (int) $user->getKey(),
            'status' => (string) $user->status,
            'kyc_status' => (string) $user->kyc_status,
            'updated_at' => (string) $user->updated_at,
        ], JSON_THROW_ON_ERROR));
    }

    private function accountSecurityFingerprint(UserProviderAccount $providerAccount): string
    {
        $metadata = (array) $providerAccount->metadata;

        return hash('sha256', json_encode([
            'id' => (int) $providerAccount->getKey(),
            'user_id' => (int) $providerAccount->user_id,
            'provider_id' => (int) $providerAccount->provider_id,
            'external_reference_fingerprint' => hash(
                'sha256',
                (string) $providerAccount->external_reference,
            ),
            'external_customer_id_fingerprint' => hash(
                'sha256',
                (string) $providerAccount->external_customer_id,
            ),
            'external_account_id_fingerprint' => hash(
                'sha256',
                (string) $providerAccount->external_account_id,
            ),
            'status' => (string) $providerAccount->status,
            'provider_status' => $providerAccount->provider_status,
            'provider_sub_status' => $providerAccount->provider_sub_status,
            'compliance_status' => $providerAccount->compliance_status,
            'rfi_status' => $providerAccount->rfi_status,
            'odd_status' => $providerAccount->odd_status,
            'customer_id_verified_at' => (string) $providerAccount->customer_id_verified_at,
            'wallet_id_verified_at' => (string) $providerAccount->wallet_id_verified_at,
            'provider_ids_verified_at' => (string) $providerAccount->provider_ids_verified_at,
            'security_conflict_at' => (string) $providerAccount->security_conflict_at,
            'security_conflict_reason_fingerprint' => hash(
                'sha256',
                (string) $providerAccount->security_conflict_reason,
            ),
            'reconciliation_status' => $providerAccount->reconciliation_status,
            'reconciliation_error_fingerprint' => hash(
                'sha256',
                (string) $providerAccount->reconciliation_error,
            ),
            'reconciliation_requested_at' => (string) $providerAccount->reconciliation_requested_at,
            'reconciled_at' => (string) $providerAccount->reconciled_at,
            'integration_status' => Arr::get($metadata, 'integration_status'),
            'updated_at' => (string) $providerAccount->updated_at,
        ], JSON_THROW_ON_ERROR));
    }

    private function submissionSecurityFingerprint(KycProviderSubmission $submission): string
    {
        return hash(
            'sha256',
            json_encode(
                $this->canonicalizeFingerprintValue([
                    'id' => (int) $submission->getKey(),
                    'user_id' => (int) $submission->user_id,
                    'provider_id' => (int) $submission->provider_id,
                    'provider_account_id' => (int) $submission->provider_account_id,
                    'status' => (string) $submission->status,
                    'submitted_at' => (string) $submission->submitted_at,
                    'approved_at' => (string) $submission->approved_at,
                    'rejected_at' => (string) $submission->rejected_at,
                    'failure_reason_fingerprint' => hash(
                        'sha256',
                        (string) $submission->failure_reason,
                    ),
                    'metadata' => (array) $submission->metadata,
                    'updated_at' => (string) $submission->updated_at,
                ]),
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION,
            ),
        );
    }

    /**
     * @return null|array{security_fingerprint: string, current_object_valid: bool}
     */
    private function consumeProviderCapability(IntegrationProvider $provider): ?array
    {
        $capability = $this->providerProvenance[$provider] ?? null;

        if (is_array($capability)) {
            unset($this->providerProvenance[$provider]);
        }

        if (
            ! is_array($capability)
            || $capability['consumed'] !== false
            || ! is_string($capability['security_fingerprint'])
            || preg_match('/^[a-f0-9]{64}$/', $capability['security_fingerprint']) !== 1
        ) {
            return null;
        }

        return [
            'security_fingerprint' => $capability['security_fingerprint'],
            'current_object_valid' => $this->providerObjectMatchesSecurityFingerprint(
                $provider,
                $capability['security_fingerprint'],
            ),
        ];
    }

    private function providerSecurityFingerprint(IntegrationProvider $provider): ?string
    {
        try {
            return hash(
                'sha256',
                json_encode(
                    $this->canonicalizeFingerprintValue([
                        'id' => (int) $provider->getKey(),
                        'code' => strtolower(trim((string) $provider->code)),
                        'status' => (string) $provider->status,
                        'service_config' => $provider->serviceConfig(),
                    ]),
                    JSON_THROW_ON_ERROR
                        | JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                        | JSON_PRESERVE_ZERO_FRACTION,
                ),
            );
        } catch (JsonException) {
            return null;
        }
    }

    private function providerObjectMatchesSecurityFingerprint(
        IntegrationProvider $provider,
        string $authorizedFingerprint,
    ): bool {
        $currentFingerprint = $this->providerSecurityFingerprint($provider);

        return (int) $provider->getKey() === self::PROVIDER_ID
            && $provider->exists
            && strtolower(trim((string) $provider->code)) === 'nium'
            && $provider->status === 'active'
            && $provider->isConfigured()
            && is_string($currentFingerprint)
            && hash_equals($authorizedFingerprint, $currentFingerprint);
    }

    private function canonicalizeFingerprintValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalizeFingerprintValue(...), $value);
        }

        ksort($value);

        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalizeFingerprintValue($child);
        }

        return $value;
    }

    private function authenticatedStateProjection(
        array $response,
        string $customerId,
        string $walletId,
    ): array {
        $projection = [
            'customerHashId' => $customerId,
            'walletHashId' => $walletId,
        ];

        $this->projectAllowedStateValue($projection, $response, 'status', self::PROVIDER_STATUSES);
        $this->projectAllowedStateValue(
            $projection,
            $response,
            'subStatus',
            self::PROVIDER_SUB_STATUSES,
            true,
        );
        $this->projectAllowedStateValue(
            $projection,
            $response,
            'complianceStatus',
            self::COMPLIANCE_STATUSES,
        );
        $this->projectAllowedStateValue($projection, $response, 'oddStatus', self::ODD_STATUSES);

        if (
            array_key_exists('isResubmissionAllowed', $response)
            && is_bool($response['isResubmissionAllowed'])
        ) {
            $projection['isResubmissionAllowed'] = $response['isResubmissionAllowed'];
        }

        return $projection;
    }

    private function projectAllowedStateValue(
        array &$projection,
        array $response,
        string $key,
        array $allowed,
        bool $emptyMeansNull = false,
    ): void {
        if (! array_key_exists($key, $response) || ! is_scalar($response[$key])) {
            return;
        }

        $normalized = strtolower(trim((string) $response[$key]));

        if ($normalized === '' && $emptyMeansNull) {
            $projection[$key] = null;

            return;
        }

        if (in_array($normalized, $allowed, true)) {
            $projection[$key] = $normalized;
        }
    }

    private function consumeFixtureCapability(
        User $user,
        UserProviderAccount $providerAccount,
    ): array {
        $externalReference = $this->fixtureExternalReference($user, $providerAccount);
        $userCapability = $this->fixtureUserProvenance[$user] ?? null;
        $accountCapability = $this->fixtureAccountProvenance[$providerAccount] ?? null;

        if (
            ! is_array($userCapability)
            || ! is_array($accountCapability)
            || $userCapability['consumed'] !== false
            || $accountCapability['consumed'] !== false
            || $userCapability['user_object_id'] !== spl_object_id($user)
            || $accountCapability['user_object_id'] !== spl_object_id($user)
            || $userCapability['provider_account_object_id'] !== spl_object_id($providerAccount)
            || $accountCapability['provider_account_object_id'] !== spl_object_id($providerAccount)
            || ! hash_equals(
                $userCapability['capability_token'],
                $accountCapability['capability_token'],
            )
            || ! hash_equals(
                $userCapability['user_security_fingerprint'],
                $this->userSecurityFingerprint($user),
            )
            || ! hash_equals(
                $accountCapability['account_security_fingerprint'],
                $this->accountSecurityFingerprint($providerAccount),
            )
            || ! hash_equals(
                $userCapability['external_reference_fingerprint'],
                $this->externalReferenceFingerprint($externalReference),
            )
            || ! hash_equals(
                $accountCapability['external_reference_fingerprint'],
                $this->externalReferenceFingerprint($externalReference),
            )
        ) {
            throw new RuntimeException('Nium customer retry fixture context is invalid.');
        }

        unset($this->fixtureUserProvenance[$user], $this->fixtureAccountProvenance[$providerAccount]);

        return [
            'user_id' => $userCapability['user_id'],
            'user_object_id' => $userCapability['user_object_id'],
            'user_security_fingerprint' => $userCapability['user_security_fingerprint'],
            'provider_account_id' => $accountCapability['provider_account_id'],
            'provider_account_object_id' => $accountCapability['provider_account_object_id'],
            'account_security_fingerprint' => $accountCapability['account_security_fingerprint'],
            'external_reference_fingerprint' => $accountCapability['external_reference_fingerprint'],
            'submission_security_fingerprint' => $accountCapability['submission_security_fingerprint'],
        ];
    }

    private function fixtureExternalReference(
        User $user,
        UserProviderAccount $providerAccount,
    ): string {
        $externalReference = (string) $providerAccount->external_reference;

        if (
            ! $this->isUsableFixtureUser($user)
            || ! $this->isSafeRetryAccount($providerAccount)
            || trim($externalReference) === ''
        ) {
            throw new RuntimeException('Nium customer retry fixture context is invalid.');
        }

        return $externalReference;
    }

    private function assertFreshFixtureContext(array $capability): void
    {
        try {
            $user = User::query()->whereKey(self::USER_ID)->first();
            $providerAccount = UserProviderAccount::query()
                ->whereKey(self::PROVIDER_ACCOUNT_ID)
                ->first();
            $provider = IntegrationProvider::query()->whereKey(self::PROVIDER_ID)->first();
            $submission = KycProviderSubmission::query()
                ->whereKey(self::SUBMISSION_ID)
                ->first();

            $valid = $user instanceof User
                && $providerAccount instanceof UserProviderAccount
                && $provider instanceof IntegrationProvider
                && $submission instanceof KycProviderSubmission
                && $this->isUsableFixtureUser($user)
                && $this->isSafeRetryAccount($providerAccount)
                && $this->isSafeRetryProvider($provider)
                && $this->isSafeRetrySubmission($submission)
                && hash_equals(
                    $capability['user_security_fingerprint'] ?? '',
                    $this->userSecurityFingerprint($user),
                )
                && hash_equals(
                    $capability['account_security_fingerprint'] ?? '',
                    $this->accountSecurityFingerprint($providerAccount),
                )
                && hash_equals(
                    $capability['external_reference_fingerprint'] ?? '',
                    $this->externalReferenceFingerprint(
                        (string) $providerAccount->external_reference,
                    ),
                )
                && hash_equals(
                    $capability['provider_security_fingerprint'] ?? '',
                    $this->providerSecurityFingerprint($provider) ?? '',
                )
                && hash_equals(
                    $capability['submission_security_fingerprint'] ?? '',
                    $this->submissionSecurityFingerprint($submission),
                );
        } catch (Throwable) {
            $valid = false;
        }

        if (! $valid) {
            throw new RuntimeException('Nium customer retry database context is stale.');
        }
    }

    private function isUsableFixtureUser(User $user): bool
    {
        return (int) $user->getKey() === self::USER_ID
            && $user->exists
            && $user->status === 'active'
            && $user->kyc_status === 'verified';
    }

    private function isSafeRetryAccount(UserProviderAccount $providerAccount): bool
    {
        return (int) $providerAccount->getKey() === self::PROVIDER_ACCOUNT_ID
            && $providerAccount->exists
            && (int) $providerAccount->user_id === self::USER_ID
            && (int) $providerAccount->provider_id === self::PROVIDER_ID
            && trim((string) $providerAccount->external_reference) !== ''
            && trim((string) $providerAccount->external_customer_id) === ''
            && trim((string) $providerAccount->external_account_id) === ''
            && $providerAccount->status === 'submitted'
            && $providerAccount->provider_status === 'pending'
            && $providerAccount->provider_sub_status === null
            && $providerAccount->security_conflict_at === null
            && trim((string) $providerAccount->security_conflict_reason) === ''
            && $providerAccount->reconciliation_status === 'pending'
            && trim((string) $providerAccount->reconciliation_error) === '';
    }

    private function isSafeRetryProvider(IntegrationProvider $provider): bool
    {
        return (int) $provider->getKey() === self::PROVIDER_ID
            && $provider->exists
            && strtolower(trim((string) $provider->code)) === 'nium'
            && $provider->status === 'active'
            && $provider->isConfigured()
            && $this->providerSecurityFingerprint($provider) !== null;
    }

    private function isSafeRetrySubmission(KycProviderSubmission $submission): bool
    {
        return (int) $submission->getKey() === self::SUBMISSION_ID
            && $submission->exists
            && (int) $submission->user_id === self::USER_ID
            && (int) $submission->provider_id === self::PROVIDER_ID
            && (int) $submission->provider_account_id === self::PROVIDER_ACCOUNT_ID
            && $submission->status === 'submitted'
            && $submission->submitted_at !== null
            && $submission->approved_at === null
            && $submission->rejected_at === null
            && trim((string) $submission->failure_reason) === '';
    }

    private function customerEndpoint(string $configKey): string
    {
        return $this->niumService->path(
            (string) config($configKey),
            ['client' => $this->niumService->clientId()],
        );
    }

    private function decodeObject(Response $response): ?array
    {
        try {
            $object = json_decode($response->body(), false, 512, JSON_THROW_ON_ERROR);
            $array = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_object($object) && is_array($array) ? $array : null;
    }

    private function walletIdentifier(array $payload): ?string
    {
        $wallet = $this->identifier($payload['walletHashId'] ?? null);

        if ($wallet !== null) {
            return $wallet;
        }

        $wallets = $payload['wallets'] ?? null;

        if (is_array($wallets) && array_is_list($wallets) && is_array($wallets[0] ?? null)) {
            $wallet = $this->identifier($wallets[0]['walletHashId'] ?? null);

            if ($wallet !== null) {
                return $wallet;
            }
        }

        $walletIds = $payload['walletHashIds'] ?? null;

        return is_array($walletIds) && array_is_list($walletIds)
            ? $this->identifier($walletIds[0] ?? null)
            : null;
    }

    private function identifier(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== ''
            && $trimmed === $value
            && strlen($value) <= 255
            && preg_match('/[[:cntrl:]]/', $value) !== 1
                ? $value
                : null;
    }

    private function hasIdentifierConflict(
        UserProviderAccount $providerAccount,
        string $customerId,
        string $walletId,
    ): bool {
        $currentCustomerId = $this->identifier($providerAccount->external_customer_id);
        $currentWalletId = $this->identifier($providerAccount->external_account_id);

        return ($currentCustomerId !== null && ! hash_equals($currentCustomerId, $customerId))
            || ($currentWalletId !== null && ! hash_equals($currentWalletId, $walletId));
    }

    private function safeHttpStatus(Response $response): ?int
    {
        $status = $response->status();

        return $status >= 100 && $status <= 599 ? $status : null;
    }

    private function httpFailureCategory(int $status, string $operation): string
    {
        return match (true) {
            in_array($status, [401, 403], true) => $operation.'_authentication_failed',
            $status === 429 => $operation.'_rate_limited',
            $status >= 500 => $operation.'_provider_server_error',
            default => $operation.'_provider_failed',
        };
    }
}
