<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\AuditLog;
use App\Models\IntegrationProvider;
use App\Models\KycProviderSubmission;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumAuthenticatedStateProjector;
use App\Services\Nium\NiumCustomerCreateResult;
use App\Services\Nium\NiumCustomerCreateState;
use App\Services\Nium\NiumCustomerLookupResult;
use App\Services\Nium\NiumCustomerLookupState;
use App\Services\Nium\NiumCustomerPayloadHashVerifier;
use App\Services\Nium\NiumCustomerErrorMapper;
use App\Services\Nium\NiumProviderAccountStateService;
use App\Services\Nium\NiumProviderHttpClientFactory;
use App\Services\Nium\NiumSafeValueProjector;
use App\Services\Nium\NiumCustomerRetryService;
use App\Services\Nium\NiumService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class NiumCustomerRetryServiceTest extends TestCase
{
    use RefreshDatabase;

    private const EXTERNAL_REFERENCE = 'fixture-v2-synthetic-external-reference';

    private const CUSTOMER_ID = 'synthetic-customer-identifier';

    private const WALLET_ID = 'synthetic-wallet-identifier';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('kyc_private');
        Http::preventStrayRequests();

        config()->set('services.nium', [
            'base_url' => 'https://gateway.nium.test',
            'timeout' => 5,
            'client_id' => 'synthetic-client',
            'auth' => [
                'mode' => 'header',
                'header_name' => 'x-api-key',
                'header_value' => 'synthetic-api-key',
            ],
            'webhook' => [
                'static_header_name' => 'x-partner-key',
                'static_header_value' => 'synthetic-partner-key',
            ],
            'health_endpoint' => '/api/v1/client/{clientHashId}',
            'customer_create_endpoint' => '/api/v5/client/{clientHashId}/customers',
            'customer_list_endpoint' => '/api/v5/client/{clientHashId}/customers',
            'customer_get_endpoint' => '/api/v5/client/{clientHashId}/customer/{customerHashId}',
        ]);
    }

    public function test_lookup_explicit_empty_customers_is_absent_with_one_get_and_one_safe_log(): void
    {
        [$provider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake(['*' => Http::response(['customers' => []], 200)]);

        $service = app(NiumCustomerRetryService::class);
        [$user, $account] = $service->resolveFixtureContext();
        $provider = $service->resolveProvider();
        $result = $service->lookupByExternalReference($user, $account, $provider);

        $this->assertSame(NiumCustomerLookupState::Absent, $result->state);
        $this->assertSame(200, $result->httpStatus);
        $this->assertFalse($result->customerIdentifierPresent);
        $this->assertFalse($result->walletIdentifierPresent);
        $this->assertNull($result->failureCategory);
        $this->assertSingleRequest('GET');
        $this->assertDatabaseCount('api_request_logs', 1);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
        $this->assertFakeStorageEmpty();
    }

    public function test_lookup_object_without_customers_is_ambiguous(): void
    {
        [$provider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake(['*' => Http::response('{}', 200, ['Content-Type' => 'application/json'])]);

        $service = app(NiumCustomerRetryService::class);
        [$user, $account] = $service->resolveFixtureContext();
        $provider = $service->resolveProvider();
        $result = $service->lookupByExternalReference($user, $account, $provider);

        $this->assertSame(NiumCustomerLookupState::Ambiguous, $result->state);
        $this->assertSame('lookup_customers_missing', $result->failureCategory);
        $this->assertFalse($result->customerIdentifierPresent);
        $this->assertFalse($result->walletIdentifierPresent);
        $this->assertSingleRequest('GET');
        $this->assertDatabaseCount('api_request_logs', 1);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
    }

    public function test_lookup_top_level_json_list_is_failed(): void
    {
        [$provider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake(['*' => Http::response('[]', 200, ['Content-Type' => 'application/json'])]);

        $service = app(NiumCustomerRetryService::class);
        [$user, $account] = $service->resolveFixtureContext();
        $provider = $service->resolveProvider();
        $result = $service->lookupByExternalReference($user, $account, $provider);

        $this->assertSame(NiumCustomerLookupState::Failed, $result->state);
        $this->assertSame('lookup_response_decode_failed', $result->failureCategory);
        $this->assertFalse($result->customerIdentifierPresent);
        $this->assertFalse($result->walletIdentifierPresent);
        $this->assertSingleRequest('GET');
        $this->assertDatabaseCount('api_request_logs', 1);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
    }

    #[DataProvider('ambiguousLookupResponses')]
    public function test_lookup_unsupported_success_shapes_are_ambiguous(
        array $response,
        string $failureCategory,
        bool $customerIdentifierPresent,
        bool $walletIdentifierPresent,
    ): void {
        [$provider, $user, $account, $submission] = $this->fixture();
        $response = $this->replaceExactReferenceMarker($response);
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake(['*' => Http::response($response, 200)]);

        $service = app(NiumCustomerRetryService::class);
        [$user, $account] = $service->resolveFixtureContext();
        $provider = $service->resolveProvider();
        $result = $service->lookupByExternalReference($user, $account, $provider);

        $this->assertSame(NiumCustomerLookupState::Ambiguous, $result->state);
        $this->assertSame($failureCategory, $result->failureCategory);
        $this->assertSame($customerIdentifierPresent, $result->customerIdentifierPresent);
        $this->assertSame($walletIdentifierPresent, $result->walletIdentifierPresent);
        $this->assertSingleRequest('GET');
        $this->assertDatabaseCount('api_request_logs', 1);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
    }

    public static function ambiguousLookupResponses(): array
    {
        return [
            'customers is not a list' => [
                ['customers' => ['record' => ['externalId' => '@exact']]],
                'lookup_customers_not_list',
                false,
                false,
            ],
            'two records including exact match' => [
                ['customers' => [
                    [
                        'externalId' => '@exact',
                        'customerHashId' => self::CUSTOMER_ID,
                        'walletHashId' => self::WALLET_ID,
                    ],
                    [
                        'externalId' => 'different-reference',
                        'customerHashId' => 'different-customer',
                        'walletHashId' => 'different-wallet',
                    ],
                ]],
                'lookup_customer_count_invalid',
                false,
                false,
            ],
            'one nonmatching record' => [
                ['customers' => [[
                    'externalId' => 'different-reference',
                    'customerHashId' => self::CUSTOMER_ID,
                    'walletHashId' => self::WALLET_ID,
                ]]],
                'lookup_external_reference_mismatch',
                false,
                false,
            ],
            'missing customer identifier' => [
                ['customers' => [[
                    'externalId' => '@exact',
                    'walletHashId' => self::WALLET_ID,
                ]]],
                'lookup_identifiers_missing',
                false,
                true,
            ],
            'missing wallet identifier' => [
                ['customers' => [[
                    'externalId' => '@exact',
                    'customerHashId' => self::CUSTOMER_ID,
                ]]],
                'lookup_identifiers_missing',
                true,
                false,
            ],
        ];
    }

    public function test_lookup_exact_match_is_existing_and_logs_no_raw_identifiers(): void
    {
        [$provider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake(['*' => Http::response([
            'customers' => [$this->validCustomer()],
        ], 200)]);

        $service = app(NiumCustomerRetryService::class);
        [$user, $account] = $service->resolveFixtureContext();
        $provider = $service->resolveProvider();
        $result = $service->lookupByExternalReference($user, $account, $provider);

        $this->assertSame(NiumCustomerLookupState::Existing, $result->state);
        $this->assertTrue($result->isPersistable());
        $this->assertTrue($result->customerIdentifierPresent);
        $this->assertTrue($result->walletIdentifierPresent);
        $this->assertNull($result->failureCategory);
        $this->assertSingleRequest('GET');
        $this->assertSafeCompletedResponseLog([
            self::EXTERNAL_REFERENCE,
            self::CUSTOMER_ID,
            self::WALLET_ID,
        ]);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
    }

    public function test_account_identifier_populated_after_resolution_is_rejected_before_http(): void
    {
        [$provider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake();
        $service = app(NiumCustomerRetryService::class);
        [$user, $account] = $service->resolveFixtureContext();
        $provider = $service->resolveProvider();
        $account->external_customer_id = 'different-local-customer';

        try {
            $service->lookupByExternalReference($user, $account, $provider);
            $this->fail('A populated identifier must invalidate fixture provenance.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Nium customer retry fixture context is invalid.',
                $exception->getMessage(),
            );
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('api_request_logs', 0);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
    }

    #[DataProvider('lookupHttpFailures')]
    public function test_lookup_http_failures_are_failed_with_one_completed_log(
        int $status,
        string $failureCategory,
    ): void {
        [$provider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake(['*' => Http::response(['errors' => [['code' => 'synthetic_error']]], $status)]);

        $service = app(NiumCustomerRetryService::class);
        [$user, $account] = $service->resolveFixtureContext();
        $provider = $service->resolveProvider();
        $result = $service->lookupByExternalReference($user, $account, $provider);

        $this->assertSame(NiumCustomerLookupState::Failed, $result->state);
        $this->assertSame($status, $result->httpStatus);
        $this->assertSame($failureCategory, $result->failureCategory);
        $this->assertSingleRequest('GET');
        $this->assertDatabaseCount('api_request_logs', 1);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
    }

    public static function lookupHttpFailures(): array
    {
        return [
            'authentication' => [401, 'lookup_authentication_failed'],
            'rate limit' => [429, 'lookup_rate_limited'],
            'server error' => [503, 'lookup_provider_server_error'],
        ];
    }

    public function test_lookup_malformed_json_is_failed_after_one_completed_log(): void
    {
        [$provider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake(['*' => Http::response('{"customers":', 200)]);

        $service = app(NiumCustomerRetryService::class);
        [$user, $account] = $service->resolveFixtureContext();
        $provider = $service->resolveProvider();
        $result = $service->lookupByExternalReference($user, $account, $provider);

        $this->assertSame(NiumCustomerLookupState::Failed, $result->state);
        $this->assertSame('lookup_response_decode_failed', $result->failureCategory);
        $this->assertSingleRequest('GET');
        $this->assertDatabaseCount('api_request_logs', 1);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
    }

    public function test_lookup_network_exception_is_failed_without_completed_response_log(): void
    {
        [$provider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake(['*' => Http::failedConnection('synthetic connection failure')]);

        $service = app(NiumCustomerRetryService::class);
        [$user, $account] = $service->resolveFixtureContext();
        $provider = $service->resolveProvider();
        $result = $service->lookupByExternalReference($user, $account, $provider);

        $this->assertSame(NiumCustomerLookupState::Failed, $result->state);
        $this->assertNull($result->httpStatus);
        $this->assertSame('lookup_transport_failed', $result->failureCategory);
        $this->assertSingleRequest('GET');
        $this->assertDatabaseCount('api_request_logs', 0);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
    }

    public function test_fabricated_user_id_six_is_rejected_before_http(): void
    {
        $this->fixture();
        Http::fake();
        $service = app(NiumCustomerRetryService::class);
        [$databaseUser, $account] = $service->resolveFixtureContext();
        $provider = $service->resolveProvider();
        $fabricated = $this->fabricatedUser($databaseUser);

        $this->assertFixtureLookupRejected($service, $fabricated, $account, $provider);
    }

    public function test_separately_queried_user_six_is_rejected_before_http(): void
    {
        $this->fixture();
        Http::fake();
        $service = app(NiumCustomerRetryService::class);
        [, $account] = $service->resolveFixtureContext();
        $provider = $service->resolveProvider();
        $substituted = User::query()->findOrFail(6);

        $this->assertFixtureLookupRejected($service, $substituted, $account, $provider);
    }

    public function test_fabricated_account_id_four_is_rejected_before_http(): void
    {
        $this->fixture();
        Http::fake();
        $service = app(NiumCustomerRetryService::class);
        [$user, $databaseAccount] = $service->resolveFixtureContext();
        $provider = $service->resolveProvider();
        $fabricated = $this->fabricatedAccount($databaseAccount);

        $this->assertFixtureLookupRejected($service, $user, $fabricated, $provider);
    }

    public function test_separately_queried_account_four_is_rejected_before_http(): void
    {
        $this->fixture();
        Http::fake();
        $service = app(NiumCustomerRetryService::class);
        [$user] = $service->resolveFixtureContext();
        $provider = $service->resolveProvider();
        $substituted = UserProviderAccount::query()->findOrFail(4);

        $this->assertFixtureLookupRejected($service, $user, $substituted, $provider);
    }

    public function test_fabricated_account_with_different_reference_is_rejected_before_http(): void
    {
        $this->fixture();
        Http::fake();
        $service = app(NiumCustomerRetryService::class);
        [$user, $databaseAccount] = $service->resolveFixtureContext();
        $provider = $service->resolveProvider();
        $fabricated = $this->fabricatedAccount($databaseAccount);
        $fabricated->external_reference = 'different-synthetic-reference';

        $this->assertFixtureLookupRejected($service, $user, $fabricated, $provider);
    }

    public function test_account_state_mutated_after_resolution_is_rejected_before_http(): void
    {
        $this->fixture();
        Http::fake();
        $service = app(NiumCustomerRetryService::class);
        [$user, $account] = $service->resolveFixtureContext();
        $provider = $service->resolveProvider();
        $account->reconciliation_status = 'failed';

        $this->assertFixtureLookupRejected($service, $user, $account, $provider);
    }

    #[DataProvider('safeFixtureReconciliationStatuses')]
    public function test_fixture_context_accepts_only_safe_reconciliation_statuses(
        ?string $reconciliationStatus,
    ): void {
        [, , $expectedAccount] = $this->fixture($reconciliationStatus);
        Http::fake();

        [, $resolvedAccount] = app(NiumCustomerRetryService::class)->resolveFixtureContext();

        $this->assertSame($expectedAccount->getKey(), $resolvedAccount->getKey());
        $this->assertSame($reconciliationStatus, $resolvedAccount->reconciliation_status);
        Http::assertNothingSent();
    }

    public static function safeFixtureReconciliationStatuses(): array
    {
        return [
            'legacy uninitialized null' => [null],
            'explicit pending' => ['pending'],
        ];
    }

    #[DataProvider('unsafeFixtureReconciliationStatuses')]
    public function test_fixture_context_rejects_unsafe_reconciliation_statuses(
        string $reconciliationStatus,
    ): void {
        $this->fixture($reconciliationStatus);
        Http::fake();
        $this->assertFixtureContextRejected();
    }

    public static function unsafeFixtureReconciliationStatuses(): array
    {
        return [
            'failed' => ['failed'],
            'conflicted' => ['quarantined'],
            'completed' => ['reconciled'],
            'unknown arbitrary value' => ['unknown-arbitrary-state'],
        ];
    }

    public function test_fixture_context_rejects_reconciliation_error_for_legacy_null_state(): void
    {
        $this->fixture(null, 'reconciliation_failed');
        Http::fake();
        $this->assertFixtureContextRejected();
    }

    #[DataProvider('unsafeFixtureExternalIdentifiers')]
    public function test_fixture_context_rejects_existing_external_identifiers(
        string $field,
    ): void {
        $this->fixture(null, null, [$field => 'existing-provider-identifier']);
        Http::fake();
        $this->assertFixtureContextRejected();
    }

    public static function unsafeFixtureExternalIdentifiers(): array
    {
        return [
            'customer identifier' => ['external_customer_id'],
            'account identifier' => ['external_account_id'],
        ];
    }

    #[DataProvider('unsafeFixtureProviderStates')]
    public function test_fixture_context_rejects_unsafe_provider_states(
        string $providerStatus,
    ): void {
        $this->fixture(null, null, ['provider_status' => $providerStatus]);
        Http::fake();
        $this->assertFixtureContextRejected();
    }

    public static function unsafeFixtureProviderStates(): array
    {
        return [
            'failed' => ['failed'],
            'clear' => ['clear'],
        ];
    }

    #[DataProvider('mutatedFixtureRelationships')]
    public function test_account_relationship_mutated_after_resolution_is_rejected_before_http(
        string $field,
        int $value,
    ): void {
        $this->fixture();
        Http::fake();
        $service = app(NiumCustomerRetryService::class);
        [$user, $account] = $service->resolveFixtureContext();
        $provider = $service->resolveProvider();
        $account->{$field} = $value;

        $this->assertFixtureLookupRejected($service, $user, $account, $provider);
    }

    public static function mutatedFixtureRelationships(): array
    {
        return [
            'changed user' => ['user_id', 7],
            'changed provider' => ['provider_id', 8],
        ];
    }

    #[DataProvider('providerObjectMutations')]
    public function test_authorized_provider_object_mutation_before_lookup_is_consumed_and_rejected(
        string $field,
        string $value,
    ): void {
        [, , $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake();
        $service = app(NiumCustomerRetryService::class);
        [$user, $account] = $service->resolveFixtureContext();
        $provider = $service->resolveProvider();
        $provider->{$field} = $value;

        $result = $service->lookupByExternalReference($user, $account, $provider);

        $this->assertSame(NiumCustomerLookupState::Failed, $result->state);
        $this->assertSame('lookup_provider_provenance_invalid', $result->failureCategory);
        $this->assertFixtureLookupRejected($service, $user, $account, $provider);
        $this->assertRequestCounts(0, 0);
        $this->assertDatabaseCount('api_request_logs', 0);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
    }

    public static function providerObjectMutations(): array
    {
        return [
            'code changed only on authorized object' => ['code', 'changed_provider'],
            'status changed only on authorized object' => ['status', 'inactive'],
        ];
    }

    public function test_cross_service_fixture_context_is_rejected_before_http(): void
    {
        $this->fixture();
        Http::fake();
        $serviceA = app(NiumCustomerRetryService::class);
        $serviceB = app(NiumCustomerRetryService::class);
        [$user, $account] = $serviceA->resolveFixtureContext();
        $provider = $serviceB->resolveProvider();

        $this->assertFixtureLookupRejected($serviceB, $user, $account, $provider);
    }

    public function test_database_resolved_fixture_context_performs_exactly_one_get(): void
    {
        $this->fixture();
        Http::fake(['*' => Http::response(['customers' => []], 200)]);
        $service = app(NiumCustomerRetryService::class);
        [$user, $account] = $service->resolveFixtureContext();
        $provider = $service->resolveProvider();

        $result = $service->lookupByExternalReference($user, $account, $provider);

        $this->assertSame(NiumCustomerLookupState::Absent, $result->state);
        $this->assertSingleRequest('GET');
        $this->assertDatabaseCount('api_request_logs', 1);
    }

    public function test_consumed_fixture_context_performs_zero_additional_gets(): void
    {
        $this->fixture();
        Http::fake(['*' => Http::response(['customers' => []], 200)]);
        $service = app(NiumCustomerRetryService::class);
        [$user, $account] = $service->resolveFixtureContext();
        $provider = $service->resolveProvider();
        $first = $service->lookupByExternalReference($user, $account, $provider);

        $this->assertSame(NiumCustomerLookupState::Absent, $first->state);
        $this->assertFixtureLookupRejected($service, $user, $account, $provider, 1);
        $this->assertSingleRequest('GET');
        $this->assertDatabaseCount('api_request_logs', 1);
    }

    #[DataProvider('invalidSubmissionPreflightMutations')]
    public function test_fixture_resolution_requires_exact_submission_two_preflight(
        string $mutation,
    ): void {
        $this->fixture();
        Http::fake();
        $this->mutateFreshnessContext($mutation);
        $before = $this->freshnessSnapshot();

        try {
            app(NiumCustomerRetryService::class)->resolveFixtureContext();
            $this->fail('Invalid Submission 2 must prevent fixture authority from being minted.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Nium customer retry fixture context is unavailable.',
                $exception->getMessage(),
            );
        }

        $this->assertRequestCounts(0, 0);
        $this->assertDatabaseCount('api_request_logs', 0);
        $this->assertSame($before, $this->freshnessSnapshot());
    }

    public static function invalidSubmissionPreflightMutations(): array
    {
        return [
            'Submission 2 missing at resolution' => ['submission_missing'],
            'Submission 2 wrong relationship at resolution' => ['submission_relationship'],
            'Submission 2 wrong state at resolution' => ['submission_state'],
            'Submission 2 approved at resolution' => ['submission_approved'],
            'Submission 2 rejected at resolution' => ['submission_rejected'],
        ];
    }

    #[DataProvider('preLookupDatabaseFreshnessMutations')]
    public function test_database_context_change_after_resolution_is_rejected_before_lookup_dispatch(
        string $mutation,
    ): void {
        $this->fixture();
        Http::fake(['*' => Http::response(['customers' => []], 200)]);
        $service = app(NiumCustomerRetryService::class);
        [$user, $account] = $service->resolveFixtureContext();
        $provider = $service->resolveProvider();
        $this->mutateFreshnessContext($mutation);
        $before = $this->freshnessSnapshot();

        try {
            $service->lookupByExternalReference($user, $account, $provider);
            $this->fail('Stale database context must stop Lookup before dispatch.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Nium customer retry database context is stale.',
                $exception->getMessage(),
            );
        }

        $this->assertFixtureLookupRejected($service, $user, $account, $provider);
        $this->assertRequestCounts(0, 0);
        $this->assertDatabaseCount('api_request_logs', 0);
        $this->assertSame($before, $this->freshnessSnapshot());
        $this->assertFakeStorageEmpty();
    }

    public static function preLookupDatabaseFreshnessMutations(): array
    {
        return [
            'database User status changed' => ['user_status'],
            'database User KYC changed' => ['user_kyc'],
            'database Account external reference changed' => ['account_reference'],
            'database Account identifier populated' => ['account_identifier'],
            'database Account state changed' => ['account_state'],
            'database Account user relationship changed' => ['account_user_relationship'],
            'database Account provider relationship changed' => ['account_provider_relationship'],
            'database Provider code changed' => ['provider_code'],
            'database Provider status changed' => ['provider_status'],
            'Provider configuration changed' => ['provider_config'],
            'Submission 2 missing' => ['submission_missing'],
            'Submission 2 wrong relationship' => ['submission_relationship'],
            'Submission 2 wrong state' => ['submission_state'],
            'Submission 2 prior approval' => ['submission_approved'],
            'Submission 2 prior rejection' => ['submission_rejected'],
        ];
    }

    #[DataProvider('preCreateDatabaseFreshnessMutations')]
    public function test_database_context_change_after_absent_lookup_is_rejected_before_create_dependencies(
        string $mutation,
    ): void {
        [$provider, $user, $account] = $this->fixture();
        Http::fake(['*' => Http::sequence()->push(['customers' => []], 200)]);
        $service = $this->serviceWithVerifierNeverCalled();
        $absent = $this->genuineAbsent($service, $user, $account, $provider);
        $this->mutateFreshnessContext($mutation);
        $before = $this->freshnessSnapshot();

        try {
            $service->createCustomer(
                $user,
                $account,
                $provider,
                $absent,
                ['synthetic' => 'must-not-be-verified'],
            );
            $this->fail('Stale database context must stop Create before dependencies.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Nium customer retry database context is stale.',
                $exception->getMessage(),
            );
        }

        $second = $service->createCustomer(
            $user,
            $account,
            $provider,
            $absent,
            ['synthetic' => 'must-not-be-verified'],
        );

        $this->assertSame(NiumCustomerCreateState::Failed, $second->state);
        $this->assertSame('create_lookup_provenance_invalid', $second->failureCategory);
        $this->assertRequestCounts(1, 0);
        $this->assertDatabaseCount('api_request_logs', 1);
        $this->assertSame($before, $this->freshnessSnapshot());
        $this->assertFakeStorageEmpty();
    }

    public static function preCreateDatabaseFreshnessMutations(): array
    {
        return [
            'database User changed after Lookup' => ['user_status'],
            'database User KYC changed after Lookup' => ['user_kyc'],
            'database Account reference changed after Lookup' => ['account_reference'],
            'database Account identifier changed after Lookup' => ['account_identifier'],
            'database Account state changed after Lookup' => ['account_state'],
            'database Account relationship changed after Lookup' => ['account_user_relationship'],
            'database Provider code changed after Lookup' => ['provider_code'],
            'database Provider changed after Lookup' => ['provider_status'],
            'Provider configuration changed after Lookup' => ['provider_config'],
            'Submission 2 changed after Lookup' => ['submission_state'],
            'Submission 2 relationship changed after Lookup' => ['submission_relationship'],
            'Submission 2 disappeared after Lookup' => ['submission_missing'],
            'Submission 2 prior approval after Lookup' => ['submission_approved'],
            'Submission 2 prior rejection after Lookup' => ['submission_rejected'],
        ];
    }

    public function test_pre_lookup_select_exception_consumes_fixture_authority_before_http(): void
    {
        $this->fixture();
        Http::fake();
        $service = app(NiumCustomerRetryService::class);
        [$user, $account] = $service->resolveFixtureContext();
        $provider = $service->resolveProvider();
        User::retrieved(static fn () => throw new RuntimeException('synthetic select failure'));
        $before = $this->freshnessSnapshot();

        try {
            $service->lookupByExternalReference($user, $account, $provider);
            $this->fail('A fixture SELECT exception must stop Lookup.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Nium customer retry database context is stale.',
                $exception->getMessage(),
            );
        }

        $this->assertFixtureLookupRejected($service, $user, $account, $provider);
        $this->assertRequestCounts(0, 0);
        $this->assertSame($before, $this->freshnessSnapshot());
    }

    public function test_pre_create_select_exception_consumes_absent_before_verifier_and_post(): void
    {
        [$provider, $user, $account] = $this->fixture();
        Http::fake(['*' => Http::response(['customers' => []], 200)]);
        $service = $this->serviceWithVerifierNeverCalled();
        $absent = $this->genuineAbsent($service, $user, $account, $provider);
        User::retrieved(static fn () => throw new RuntimeException('synthetic select failure'));
        $before = $this->freshnessSnapshot();

        try {
            $service->createCustomer(
                $user,
                $account,
                $provider,
                $absent,
                ['synthetic' => 'must-not-be-verified'],
            );
            $this->fail('A fixture SELECT exception must stop Create.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Nium customer retry database context is stale.',
                $exception->getMessage(),
            );
        }

        $second = $service->createCustomer(
            $user,
            $account,
            $provider,
            $absent,
            ['synthetic' => 'must-not-be-verified'],
        );
        $this->assertSame('create_lookup_provenance_invalid', $second->failureCategory);
        $this->assertRequestCounts(1, 0);
        $this->assertSame($before, $this->freshnessSnapshot());
    }

    public function test_create_valid_success_requires_absent_lookup_and_makes_one_post(): void
    {
        [$provider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake(['*' => Http::sequence()
            ->push(['customers' => []], 200)
            ->push($this->validCustomer(), 201)]);
        $service = $this->serviceWithHashDecision(true);
        $absent = $this->genuineAbsent($service, $user, $account, $provider);

        $result = $service->createCustomer(
            $user,
            $account,
            $provider,
            $absent,
            ['synthetic' => 'approved-payload'],
        );

        $this->assertSame(NiumCustomerCreateState::Created, $result->state);
        $this->assertSame(201, $result->httpStatus);
        $this->assertTrue($result->isPersistable());
        $this->assertRequestCounts(1, 1);
        $this->assertSafeCompletedResponseLog([
            self::EXTERNAL_REFERENCE,
            self::CUSTOMER_ID,
            self::WALLET_ID,
            'approved-payload',
        ], 2);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
        $this->assertFakeStorageEmpty();
    }

    #[DataProvider('invalidCreateResponses')]
    public function test_create_unsupported_success_shapes_are_invalid_response(
        mixed $response,
        bool $customerIdentifierPresent,
        bool $walletIdentifierPresent,
    ): void {
        [$provider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake(['*' => Http::sequence()
            ->push(['customers' => []], 200)
            ->push($response, 200)]);
        $service = $this->serviceWithHashDecision(true);
        $absent = $this->genuineAbsent($service, $user, $account, $provider);

        $result = $service->createCustomer(
            $user,
            $account,
            $provider,
            $absent,
            ['synthetic' => 'approved-payload'],
        );

        $this->assertSame(NiumCustomerCreateState::InvalidResponse, $result->state);
        $this->assertSame($customerIdentifierPresent, $result->customerIdentifierPresent);
        $this->assertSame($walletIdentifierPresent, $result->walletIdentifierPresent);
        $this->assertRequestCounts(1, 1);
        $this->assertDatabaseCount('api_request_logs', 2);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
    }

    public static function invalidCreateResponses(): array
    {
        return [
            'missing customer identifier' => [
                ['walletHashId' => self::WALLET_ID],
                false,
                true,
            ],
            'missing wallet identifier' => [
                ['customerHashId' => self::CUSTOMER_ID],
                true,
                false,
            ],
            'top-level list' => [
                [['customerHashId' => self::CUSTOMER_ID, 'walletHashId' => self::WALLET_ID]],
                false,
                false,
            ],
        ];
    }

    public function test_create_exact_duplicate_is_duplicate_with_zero_second_lookup(): void
    {
        [$provider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake(['*' => Http::sequence()
            ->push(['customers' => []], 200)
            ->push([
                'errors' => [['code' => 'duplicate_external_id']],
            ], 409)]);
        $service = $this->serviceWithHashDecision(true);
        $absent = $this->genuineAbsent($service, $user, $account, $provider);

        $result = $service->createCustomer(
            $user,
            $account,
            $provider,
            $absent,
            ['synthetic' => 'approved-payload'],
        );

        $this->assertSame(NiumCustomerCreateState::Duplicate, $result->state);
        $this->assertSame('duplicate_external_id', $result->failureCategory);
        $this->assertRequestCounts(1, 1);
        $this->assertDatabaseCount('api_request_logs', 2);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
    }

    #[DataProvider('createHttpFailures')]
    public function test_create_nonduplicate_http_failures_are_failed(
        int $status,
        string $failureCategory,
    ): void {
        [$provider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake(['*' => Http::sequence()
            ->push(['customers' => []], 200)
            ->push(['errors' => [['code' => 'synthetic_error']]], $status)]);
        $service = $this->serviceWithHashDecision(true);
        $absent = $this->genuineAbsent($service, $user, $account, $provider);

        $result = $service->createCustomer(
            $user,
            $account,
            $provider,
            $absent,
            ['synthetic' => 'approved-payload'],
        );

        $this->assertSame(NiumCustomerCreateState::Failed, $result->state);
        $this->assertSame($failureCategory, $result->failureCategory);
        $this->assertRequestCounts(1, 1);
        $this->assertDatabaseCount('api_request_logs', 2);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
    }

    public static function createHttpFailures(): array
    {
        return [
            'other client error' => [422, 'create_provider_failed'],
            'server error' => [503, 'create_provider_server_error'],
        ];
    }

    public function test_create_network_exception_is_failed_without_completed_response_log(): void
    {
        [$provider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake(['*' => Http::sequence()
            ->push(['customers' => []], 200)
            ->pushFailedConnection('synthetic connection failure')]);
        $service = $this->serviceWithHashDecision(true);
        $absent = $this->genuineAbsent($service, $user, $account, $provider);

        $result = $service->createCustomer(
            $user,
            $account,
            $provider,
            $absent,
            ['synthetic' => 'approved-payload'],
        );

        $this->assertSame(NiumCustomerCreateState::Failed, $result->state);
        $this->assertSame('create_transport_failed', $result->failureCategory);
        $this->assertRequestCounts(1, 1);
        $this->assertDatabaseCount('api_request_logs', 1);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
    }

    public function test_create_hash_mismatch_stops_before_http(): void
    {
        [$provider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake(['*' => Http::response(['customers' => []], 200)]);
        $service = $this->serviceWithHashDecision(false);
        $absent = $this->genuineAbsent($service, $user, $account, $provider);

        $first = $service->createCustomer(
            $user,
            $account,
            $provider,
            $absent,
            ['synthetic' => 'unapproved-payload'],
        );
        $second = $service->createCustomer(
            $user,
            $account,
            $provider,
            $absent,
            ['synthetic' => 'unapproved-payload'],
        );

        $this->assertSame(NiumCustomerCreateState::Failed, $first->state);
        $this->assertSame('payload_hash_mismatch', $first->failureCategory);
        $this->assertSame('create_lookup_provenance_invalid', $second->failureCategory);
        $this->assertRequestCounts(1, 0);
        $this->assertDatabaseCount('api_request_logs', 1);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
    }

    public function test_create_nonabsent_lookup_result_stops_before_hash_and_http(): void
    {
        [$provider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake();
        $verifier = $this->mock(NiumCustomerPayloadHashVerifier::class);
        $verifier->shouldNotReceive('matchesApproved');

        $result = app(NiumCustomerRetryService::class)->createCustomer(
            $user,
            $account,
            $provider,
            NiumCustomerLookupResult::ambiguous(200, false, false, 'lookup_customers_missing'),
            ['synthetic' => 'payload'],
        );

        $this->assertSame(NiumCustomerCreateState::Failed, $result->state);
        $this->assertSame('create_lookup_provenance_invalid', $result->failureCategory);
        Http::assertNothingSent();
        $this->assertDatabaseCount('api_request_logs', 0);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
    }

    public function test_fabricated_absent_result_cannot_authorize_create(): void
    {
        [$provider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake();
        $verifier = $this->mock(NiumCustomerPayloadHashVerifier::class);
        $verifier->shouldNotReceive('matchesApproved');

        $result = app(NiumCustomerRetryService::class)->createCustomer(
            $user,
            $account,
            $provider,
            NiumCustomerLookupResult::absent(200),
            ['synthetic' => 'payload'],
        );

        $this->assertSame(NiumCustomerCreateState::Failed, $result->state);
        $this->assertSame('create_lookup_provenance_invalid', $result->failureCategory);
        Http::assertNothingSent();
        $this->assertDatabaseCount('api_request_logs', 0);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
    }

    public function test_absent_result_from_another_service_cannot_authorize_create(): void
    {
        [$provider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake(['*' => Http::response(['customers' => []], 200)]);
        $serviceA = app(NiumCustomerRetryService::class);
        $serviceB = app(NiumCustomerRetryService::class);
        $this->assertNotSame($serviceA, $serviceB);
        $absent = $this->genuineAbsent($serviceA, $user, $account, $provider);

        $result = $serviceB->createCustomer(
            $user,
            $account,
            $provider,
            $absent,
            ['synthetic' => 'payload'],
        );

        $this->assertSame(NiumCustomerCreateState::Failed, $result->state);
        $this->assertSame('create_lookup_provenance_invalid', $result->failureCategory);
        $this->assertRequestCounts(1, 0);
        $this->assertDatabaseCount('api_request_logs', 1);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
    }

    public function test_absent_result_authorizes_only_one_create_post(): void
    {
        [$provider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake(['*' => Http::sequence()
            ->push(['customers' => []], 200)
            ->push($this->validCustomer(), 201)]);
        $service = $this->serviceWithHashDecision(true);
        $absent = $this->genuineAbsent($service, $user, $account, $provider);

        $first = $service->createCustomer(
            $user,
            $account,
            $provider,
            $absent,
            ['synthetic' => 'approved-payload'],
        );
        $second = $service->createCustomer(
            $user,
            $account,
            $provider,
            $absent,
            ['synthetic' => 'approved-payload'],
        );

        $this->assertSame(NiumCustomerCreateState::Created, $first->state);
        $this->assertSame(NiumCustomerCreateState::Failed, $second->state);
        $this->assertSame('create_lookup_provenance_invalid', $second->failureCategory);
        $this->assertRequestCounts(1, 1);
        $this->assertDatabaseCount('api_request_logs', 2);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
    }

    public function test_transport_failure_consumes_absent_result_before_post_attempt(): void
    {
        [$provider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake(['*' => Http::sequence()
            ->push(['customers' => []], 200)
            ->pushFailedConnection('synthetic connection failure')]);
        $service = $this->serviceWithHashDecision(true);
        $absent = $this->genuineAbsent($service, $user, $account, $provider);

        $first = $service->createCustomer(
            $user,
            $account,
            $provider,
            $absent,
            ['synthetic' => 'approved-payload'],
        );
        $second = $service->createCustomer(
            $user,
            $account,
            $provider,
            $absent,
            ['synthetic' => 'approved-payload'],
        );

        $this->assertSame('create_transport_failed', $first->failureCategory);
        $this->assertSame('create_lookup_provenance_invalid', $second->failureCategory);
        $this->assertRequestCounts(1, 1);
        $this->assertDatabaseCount('api_request_logs', 1);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
    }

    public function test_fabricated_provider_id_seven_cannot_authorize_lookup(): void
    {
        [$databaseProvider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        $fabricated = $this->fabricatedProvider($databaseProvider);
        Http::fake();
        $service = app(NiumCustomerRetryService::class);
        [$user, $account] = $service->resolveFixtureContext();

        $result = $service->lookupByExternalReference($user, $account, $fabricated);

        $this->assertSame(NiumCustomerLookupState::Failed, $result->state);
        $this->assertSame('lookup_provider_provenance_invalid', $result->failureCategory);
        Http::assertNothingSent();
        $this->assertDatabaseCount('api_request_logs', 0);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
    }

    public function test_fabricated_provider_cannot_authorize_create(): void
    {
        [$databaseProvider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake(['*' => Http::response(['customers' => []], 200)]);
        $service = $this->serviceWithVerifierNeverCalled();
        $absent = $this->genuineAbsent($service, $user, $account, $databaseProvider);
        $fabricated = $this->fabricatedProvider($databaseProvider);

        $result = $service->createCustomer(
            $user,
            $account,
            $fabricated,
            $absent,
            ['synthetic' => 'payload'],
        );

        $this->assertSame('create_lookup_provenance_invalid', $result->failureCategory);
        $this->assertRequestCounts(1, 0);
        $this->assertDatabaseCount('api_request_logs', 1);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
    }

    public function test_substituted_database_provider_object_between_lookup_and_create_is_rejected(): void
    {
        [$provider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake(['*' => Http::response(['customers' => []], 200)]);
        $service = $this->serviceWithVerifierNeverCalled();
        $absent = $this->genuineAbsent($service, $user, $account, $provider);
        $substituted = IntegrationProvider::query()->findOrFail(7);
        $this->assertNotSame($provider, $substituted);

        $result = $service->createCustomer(
            $user,
            $account,
            $substituted,
            $absent,
            ['synthetic' => 'payload'],
        );

        $this->assertSame('create_lookup_provenance_invalid', $result->failureCategory);
        $this->assertRequestCounts(1, 0);
        $this->assertDatabaseCount('api_request_logs', 1);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
    }

    public function test_substituted_database_user_object_between_lookup_and_create_is_rejected(): void
    {
        [$provider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake(['*' => Http::response(['customers' => []], 200)]);
        $service = $this->serviceWithVerifierNeverCalled();
        $absent = $this->genuineAbsent($service, $user, $account, $provider);
        $substituted = User::query()->findOrFail(6);

        $result = $service->createCustomer(
            $substituted,
            $account,
            $provider,
            $absent,
            ['synthetic' => 'payload'],
        );

        $this->assertSame('create_lookup_provenance_invalid', $result->failureCategory);
        $this->assertRequestCounts(1, 0);
        $this->assertDatabaseCount('api_request_logs', 1);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
    }

    public function test_substituted_database_account_object_between_lookup_and_create_is_rejected(): void
    {
        [$provider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake(['*' => Http::response(['customers' => []], 200)]);
        $service = $this->serviceWithVerifierNeverCalled();
        $absent = $this->genuineAbsent($service, $user, $account, $provider);
        $substituted = UserProviderAccount::query()->findOrFail(4);

        $result = $service->createCustomer(
            $user,
            $substituted,
            $provider,
            $absent,
            ['synthetic' => 'payload'],
        );

        $this->assertSame('create_lookup_provenance_invalid', $result->failureCategory);
        $this->assertRequestCounts(1, 0);
        $this->assertDatabaseCount('api_request_logs', 1);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
    }

    public function test_account_reference_mutation_between_lookup_and_create_is_rejected(): void
    {
        [$provider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake(['*' => Http::response(['customers' => []], 200)]);
        $service = $this->serviceWithVerifierNeverCalled();
        $absent = $this->genuineAbsent($service, $user, $account, $provider);
        $account->external_reference = 'changed-synthetic-reference';

        $result = $service->createCustomer(
            $user,
            $account,
            $provider,
            $absent,
            ['synthetic' => 'payload'],
        );

        $this->assertSame('create_lookup_provenance_invalid', $result->failureCategory);
        $this->assertRequestCounts(1, 0);
        $this->assertDatabaseCount('api_request_logs', 1);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
    }

    public function test_mutated_provider_configuration_between_lookup_and_create_is_rejected(): void
    {
        [$provider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake(['*' => Http::response(['customers' => []], 200)]);
        $service = $this->serviceWithVerifierNeverCalled();
        $absent = $this->genuineAbsent($service, $user, $account, $provider);
        config()->set('services.nium.timeout', 6);

        try {
            $service->createCustomer(
                $user,
                $account,
                $provider,
                $absent,
                ['synthetic' => 'payload'],
            );
            $this->fail('Changed provider configuration must fail fresh database validation.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Nium customer retry database context is stale.',
                $exception->getMessage(),
            );
        }

        $second = $service->createCustomer(
            $user,
            $account,
            $provider,
            $absent,
            ['synthetic' => 'payload'],
        );

        $this->assertSame('create_lookup_provenance_invalid', $second->failureCategory);
        $this->assertRequestCounts(1, 0);
        $this->assertDatabaseCount('api_request_logs', 1);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
    }

    #[DataProvider('providerObjectMutations')]
    public function test_authorized_provider_object_mutation_between_lookup_and_create_is_consumed_before_dependencies(
        string $field,
        string $value,
    ): void {
        [$provider, $user, $account] = $this->fixture();
        Http::fake(['*' => Http::response(['customers' => []], 200)]);
        $service = $this->serviceWithVerifierNeverCalled();
        $absent = $this->genuineAbsent($service, $user, $account, $provider);
        $before = $this->freshnessSnapshot();
        $provider->{$field} = $value;

        $first = $service->createCustomer(
            $user,
            $account,
            $provider,
            $absent,
            ['synthetic' => 'must-not-be-verified'],
        );
        $second = $service->createCustomer(
            $user,
            $account,
            $provider,
            $absent,
            ['synthetic' => 'must-not-be-verified'],
        );

        $this->assertSame(NiumCustomerCreateState::Failed, $first->state);
        $this->assertSame('create_lookup_provenance_invalid', $first->failureCategory);
        $this->assertSame(NiumCustomerCreateState::Failed, $second->state);
        $this->assertSame('create_lookup_provenance_invalid', $second->failureCategory);
        $this->assertRequestCounts(1, 0);
        $this->assertDatabaseCount('api_request_logs', 1);
        $this->assertSame($before, $this->freshnessSnapshot());
    }

    public function test_verifier_exception_consumes_absent_before_dependency_invocation(): void
    {
        [$provider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake(['*' => Http::response(['customers' => []], 200)]);
        $verifier = $this->mock(NiumCustomerPayloadHashVerifier::class);
        $verifier->shouldReceive('matchesApproved')
            ->once()
            ->andThrow(new RuntimeException('synthetic verifier failure'));
        $service = $this->serviceWithVerifier($verifier);
        $absent = $this->genuineAbsent($service, $user, $account, $provider);

        $first = $service->createCustomer(
            $user,
            $account,
            $provider,
            $absent,
            ['synthetic' => 'payload'],
        );
        $second = $service->createCustomer(
            $user,
            $account,
            $provider,
            $absent,
            ['synthetic' => 'payload'],
        );

        $this->assertSame('payload_verification_failed', $first->failureCategory);
        $this->assertSame('create_lookup_provenance_invalid', $second->failureCategory);
        $this->assertRequestCounts(1, 0);
        $this->assertDatabaseCount('api_request_logs', 1);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
    }

    public function test_reentrant_verifier_cannot_reuse_absent_or_issue_two_posts(): void
    {
        [$provider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake(['*' => Http::sequence()
            ->push(['customers' => []], 200)
            ->push($this->validCustomer(), 201)]);
        $verifier = $this->mock(NiumCustomerPayloadHashVerifier::class);
        $service = $this->serviceWithVerifier($verifier);
        $absent = $this->genuineAbsent($service, $user, $account, $provider);
        $reentrantResult = null;
        $verifier->shouldReceive('matchesApproved')
            ->once()
            ->andReturnUsing(function (array $payload) use (
                $service,
                $user,
                $account,
                $provider,
                $absent,
                &$reentrantResult,
            ): bool {
                $reentrantResult = $service->createCustomer(
                    $user,
                    $account,
                    $provider,
                    $absent,
                    $payload,
                );

                return true;
            });

        $outer = $service->createCustomer(
            $user,
            $account,
            $provider,
            $absent,
            ['synthetic' => 'approved-payload'],
        );

        $this->assertSame(NiumCustomerCreateState::Created, $outer->state);
        $this->assertInstanceOf(NiumCustomerCreateResult::class, $reentrantResult);
        $this->assertSame('create_lookup_provenance_invalid', $reentrantResult->failureCategory);
        $this->assertRequestCounts(1, 1);
        $this->assertDatabaseCount('api_request_logs', 2);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
    }

    public function test_provider_resolution_is_read_only_for_exact_active_provider_seven(): void
    {
        $provider = $this->provider();
        $providerBefore = IntegrationProvider::query()
            ->findOrFail($provider->id)
            ->getAttributes();
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $resolved = app(NiumCustomerRetryService::class)->resolveProvider();
        $providerAfter = IntegrationProvider::query()
            ->findOrFail($provider->id)
            ->getAttributes();

        $this->assertSame(7, $resolved->id);
        $this->assertSame('nium', $resolved->code);
        $this->assertSame(1, IntegrationProvider::query()->count());
        $this->assertSame([], $this->integrationProviderDml($queries));
        $this->assertSame($providerBefore, $providerAfter);
    }

    public function test_provider_resolution_missing_returns_fixed_failure_without_insert(): void
    {
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        try {
            app(NiumCustomerRetryService::class)->resolveProvider();
            $this->fail('Missing provider must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Nium customer retry provider is unavailable.', $exception->getMessage());
        }

        $this->assertDatabaseCount('integration_providers', 0);
        $this->assertSame([], $this->integrationProviderDml($queries));
    }

    #[DataProvider('mismatchedProviders')]
    public function test_provider_resolution_mismatch_returns_fixed_failure_without_insert(
        string $code,
        string $status,
    ): void {
        IntegrationProvider::query()->forceCreate([
            'id' => 7,
            'code' => $code,
            'name' => 'Synthetic Provider',
            'status' => $status,
        ]);
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        try {
            app(NiumCustomerRetryService::class)->resolveProvider();
            $this->fail('Mismatched provider must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Nium customer retry provider is unavailable.', $exception->getMessage());
        }

        $this->assertDatabaseCount('integration_providers', 1);
        $this->assertSame([], $this->integrationProviderDml($queries));
    }

    public static function mismatchedProviders(): array
    {
        return [
            'wrong code' => ['different_provider', 'active'],
            'inactive' => ['nium', 'inactive'],
        ];
    }

    public function test_existing_result_persists_once_through_reviewed_state_service_and_row_four(): void
    {
        [$provider, $user, $account, $submission] = $this->fixture();
        $unrelated = $this->unrelatedAccount($provider);
        $unrelatedBefore = UserProviderAccount::query()
            ->findOrFail($unrelated->id)
            ->getAttributes();
        Http::fake(['*' => Http::response([
            'customers' => [$this->validCustomer()],
        ], 200)]);
        $service = app(NiumCustomerRetryService::class);
        [$user, $account] = $service->resolveFixtureContext();
        $provider = $service->resolveProvider();
        $result = $service->lookupByExternalReference($user, $account, $provider);

        $persisted = $service->persistAuthenticatedCustomer($account, $result);

        $this->assertSame(4, $persisted->id);
        $this->assertSame(self::CUSTOMER_ID, $persisted->external_customer_id);
        $this->assertSame(self::WALLET_ID, $persisted->external_account_id);
        $this->assertSame('active', $persisted->status);
        $this->assertSame('approved', $submission->fresh()->status);
        $this->assertSame(4, $submission->fresh()->provider_account_id);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertSame(
            $unrelatedBefore,
            UserProviderAccount::query()->findOrFail($unrelated->id)->getAttributes(),
        );
        $afterFirstPersistence = $this->persistenceSnapshot($account, $submission);

        $this->assertPersistenceRejectedWithoutDatabaseWork($service, $account, $result);

        $this->assertSame($afterFirstPersistence, $this->persistenceSnapshot($account, $submission));
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_created_result_persists_once_through_reviewed_state_service_and_row_four(): void
    {
        [$provider, $user, $account, $submission] = $this->fixture();
        $unrelated = $this->unrelatedAccount($provider);
        $unrelatedBefore = UserProviderAccount::query()
            ->findOrFail($unrelated->id)
            ->getAttributes();
        Http::fake(['*' => Http::sequence()
            ->push(['customers' => []], 200)
            ->push($this->validCustomer(), 201)]);
        $service = $this->serviceWithHashDecision(true);
        $absent = $this->genuineAbsent($service, $user, $account, $provider);
        $result = $service->createCustomer(
            $user,
            $account,
            $provider,
            $absent,
            ['synthetic' => 'approved-payload'],
        );

        $persisted = $service->persistAuthenticatedCustomer($account, $result);

        $this->assertSame(4, $persisted->id);
        $this->assertSame(self::CUSTOMER_ID, $persisted->external_customer_id);
        $this->assertSame(self::WALLET_ID, $persisted->external_account_id);
        $this->assertSame('active', $persisted->status);
        $this->assertSame('approved', $submission->fresh()->status);
        $this->assertSame(4, $submission->fresh()->provider_account_id);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertSame(
            $unrelatedBefore,
            UserProviderAccount::query()->findOrFail($unrelated->id)->getAttributes(),
        );
        $afterFirstPersistence = $this->persistenceSnapshot($account, $submission);

        $this->assertPersistenceRejectedWithoutDatabaseWork($service, $account, $result);

        $this->assertSame($afterFirstPersistence, $this->persistenceSnapshot($account, $submission));
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_locked_row_four_and_exact_submission_two_success_dml_contract(): void
    {
        $this->fixture();
        Http::fake(['*' => Http::response([
            'customers' => [$this->validCustomer()],
        ], 200)]);
        $service = app(NiumCustomerRetryService::class);
        $result = $this->genuineExisting($service, $user, $account, $provider);
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $persisted = $service->persistAuthenticatedCustomer($account, $result);

        $dml = array_values(array_filter(
            $queries,
            static fn (string $query): bool => preg_match('/^\\s*(insert|update|delete|replace)\\b/i', $query) === 1,
        ));
        $this->assertSame(4, $persisted->id);
        $this->assertCount(3, $dml);
        $this->assertCount(1, array_filter($dml, static fn (string $query): bool => str_contains($query, 'user_provider_accounts')));
        $this->assertCount(1, array_filter($dml, static fn (string $query): bool => str_contains($query, 'kyc_provider_submissions')));
        $this->assertCount(1, array_filter($dml, static fn (string $query): bool => str_contains($query, 'audit_logs')));
        $this->assertSame('approved', KycProviderSubmission::query()->findOrFail(2)->status);
    }

    public function test_database_external_reference_change_before_persistence_has_zero_success_dml(): void
    {
        $this->fixture();
        Http::fake(['*' => Http::response([
            'customers' => [$this->validCustomer()],
        ], 200)]);
        $service = app(NiumCustomerRetryService::class);
        $result = $this->genuineExisting($service, $user, $account, $provider);
        DB::table('user_provider_accounts')->where('id', 4)->update([
            'external_reference' => 'changed-synthetic-reference',
        ]);
        $before = $this->strictPersistenceSnapshot();

        $this->assertStrictPersistenceRejectedWithoutDml($service, $account, $result);

        $this->assertSame($before, $this->strictPersistenceSnapshot());
    }

    public function test_database_user_change_before_persistence_has_zero_success_dml(): void
    {
        $this->fixture();
        User::factory()->create(['id' => 7]);
        Http::fake(['*' => Http::response([
            'customers' => [$this->validCustomer()],
        ], 200)]);
        $service = app(NiumCustomerRetryService::class);
        $result = $this->genuineExisting($service, $user, $account, $provider);
        DB::table('user_provider_accounts')->where('id', 4)->update(['user_id' => 7]);
        $before = $this->strictPersistenceSnapshot();

        $this->assertStrictPersistenceRejectedWithoutDml($service, $account, $result);

        $this->assertSame($before, $this->strictPersistenceSnapshot());
    }

    public function test_database_provider_change_before_persistence_has_zero_success_dml(): void
    {
        $this->fixture();
        IntegrationProvider::query()->forceCreate([
            'id' => 8,
            'code' => 'synthetic_other',
            'name' => 'Synthetic Other',
            'status' => 'active',
        ]);
        Http::fake(['*' => Http::response([
            'customers' => [$this->validCustomer()],
        ], 200)]);
        $service = app(NiumCustomerRetryService::class);
        $result = $this->genuineExisting($service, $user, $account, $provider);
        DB::table('user_provider_accounts')->where('id', 4)->update(['provider_id' => 8]);
        $before = $this->strictPersistenceSnapshot();

        $this->assertStrictPersistenceRejectedWithoutDml($service, $account, $result);

        $this->assertSame($before, $this->strictPersistenceSnapshot());
    }

    public function test_database_identifier_conflict_before_persistence_has_zero_quarantine_or_other_dml(): void
    {
        $this->fixture();
        Http::fake(['*' => Http::response([
            'customers' => [$this->validCustomer()],
        ], 200)]);
        $service = app(NiumCustomerRetryService::class);
        $result = $this->genuineExisting($service, $user, $account, $provider);
        DB::table('user_provider_accounts')->where('id', 4)->update([
            'external_customer_id' => 'conflicting-synthetic-identifier',
        ]);
        $before = $this->strictPersistenceSnapshot();

        $this->assertStrictPersistenceRejectedWithoutDml($service, $account, $result);

        $this->assertSame($before, $this->strictPersistenceSnapshot());
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'provider_account.nium_security_conflict',
        ]);
    }

    public function test_missing_submission_two_rolls_back_with_zero_success_dml(): void
    {
        $this->fixture();
        Http::fake(['*' => Http::response([
            'customers' => [$this->validCustomer()],
        ], 200)]);
        $service = app(NiumCustomerRetryService::class);
        $result = $this->genuineExisting($service, $user, $account, $provider);
        DB::table('kyc_provider_submissions')->where('id', 2)->delete();
        $before = $this->strictPersistenceSnapshot();

        $this->assertStrictPersistenceRejectedWithoutDml($service, $account, $result);

        $this->assertSame($before, $this->strictPersistenceSnapshot());
    }

    #[DataProvider('invalidSubmissionTwoContexts')]
    public function test_wrong_submission_two_context_rolls_back_with_zero_success_dml(
        string $field,
        int|string|null $value,
    ): void {
        [$fixtureProvider] = $this->fixture();

        if ($field === 'user_id') {
            User::factory()->create(['id' => 7]);
        }

        if ($field === 'provider_id') {
            IntegrationProvider::query()->forceCreate([
                'id' => 8,
                'code' => 'synthetic_other',
                'name' => 'Synthetic Other',
                'status' => 'active',
            ]);
        }

        if ($field === 'provider_account_id') {
            $unrelated = $this->unrelatedAccount($fixtureProvider);
            $value = $unrelated->id;
        }

        Http::fake(['*' => Http::response([
            'customers' => [$this->validCustomer()],
        ], 200)]);
        $service = app(NiumCustomerRetryService::class);
        $result = $this->genuineExisting($service, $user, $account, $provider);
        DB::table('kyc_provider_submissions')->where('id', 2)->update([$field => $value]);
        $before = $this->strictPersistenceSnapshot();

        $this->assertStrictPersistenceRejectedWithoutDml($service, $account, $result);

        $this->assertSame($before, $this->strictPersistenceSnapshot());
    }

    public static function invalidSubmissionTwoContexts(): array
    {
        return [
            'wrong user' => ['user_id', 7],
            'wrong provider' => ['provider_id', 8],
            'wrong account' => ['provider_account_id', null],
        ];
    }

    public function test_broadly_matching_submission_cannot_replace_exact_submission_two(): void
    {
        $this->fixture();
        User::factory()->create(['id' => 7]);
        Http::fake(['*' => Http::response([
            'customers' => [$this->validCustomer()],
        ], 200)]);
        $service = app(NiumCustomerRetryService::class);
        $result = $this->genuineExisting($service, $user, $account, $provider);
        DB::table('kyc_provider_submissions')->where('id', 2)->update(['user_id' => 7]);
        KycProviderSubmission::query()->forceCreate([
            'id' => 3,
            'user_id' => 6,
            'provider_id' => 7,
            'provider_account_id' => 4,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $before = $this->strictPersistenceSnapshot();

        $this->assertStrictPersistenceRejectedWithoutDml($service, $account, $result);

        $this->assertSame($before, $this->strictPersistenceSnapshot());
        $this->assertSame('submitted', KycProviderSubmission::query()->findOrFail(3)->status);
    }

    public function test_customer_retry_persistence_cannot_reach_generic_first_match_submission_selection(): void
    {
        $retrySource = (string) file_get_contents(
            app_path('Services/Nium/NiumCustomerRetryService.php'),
        );
        $stateSource = (string) file_get_contents(
            app_path('Services/Nium/NiumProviderAccountStateService.php'),
        );

        $this->assertStringContainsString('->whereKey(self::SUBMISSION_ID)', $retrySource);
        $this->assertStringNotContainsString('applyCustomerRetryAuthenticatedState', $stateSource);
        $this->assertStringNotContainsString('->applyAuthenticatedState(', $retrySource);
    }

    public function test_state_service_exposes_no_public_customer_retry_persistence_authority(): void
    {
        $reflection = new \ReflectionClass(NiumProviderAccountStateService::class);
        $publicMethods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        $this->assertFalse($reflection->hasMethod('applyCustomerRetryAuthenticatedState'));
        $this->assertFalse($reflection->hasMethod('customerRetryContextFingerprint'));
        $this->assertNotContains('applyCustomerRetryAuthenticatedState', $publicMethods);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $parameterNames = array_map(
                static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
                $method->getParameters(),
            );
            $this->assertNotContains('externalReferenceFingerprint', $parameterNames);
            $this->assertNotContains('accountSecurityFingerprint', $parameterNames);
            $this->assertNotContains('submissionSecurityFingerprint', $parameterNames);
        }
    }

    public function test_arbitrary_fingerprints_cannot_directly_invoke_strict_persistence(): void
    {
        [, , $account] = $this->fixture();
        $before = $this->strictPersistenceSnapshot();
        $stateService = app(NiumProviderAccountStateService::class);
        $reflection = new \ReflectionClass($stateService);
        $fabricatedFingerprints = [
            hash('sha256', self::EXTERNAL_REFERENCE),
            hash('sha256', json_encode($account->getAttributes(), JSON_THROW_ON_ERROR)),
            hash('sha256', 'fabricated-submission-context'),
        ];

        $this->assertCount(3, array_filter(
            $fabricatedFingerprints,
            static fn (string $fingerprint): bool => preg_match('/^[a-f0-9]{64}$/', $fingerprint) === 1,
        ));
        $this->assertFalse($reflection->hasMethod('applyCustomerRetryAuthenticatedState'));
        $this->assertSame($before, $this->strictPersistenceSnapshot());
    }

    #[DataProvider('reservedFixtureBroadBypassAttempts')]
    public function test_reserved_incomplete_fixture_rejects_actual_broad_authenticated_state_bypass(
        array $accountState,
        array $payload,
        string $source,
    ): void {
        [, , $account, $submission] = $this->fixture();

        if ($accountState !== []) {
            DB::table('user_provider_accounts')->where('id', 4)->update($accountState);
            $account = UserProviderAccount::query()->findOrFail(4);
        }

        $accountRowBefore = DB::table('user_provider_accounts')->where('id', 4)->first();
        $submissionRowBefore = DB::table('kyc_provider_submissions')->where('id', 2)->first();
        $this->assertNotNull($accountRowBefore);
        $this->assertNotNull($submissionRowBefore);
        $accountBefore = $this->normalizeSnapshotValue((array) $accountRowBefore);
        $submissionBefore = $this->normalizeSnapshotValue((array) $submissionRowBefore);
        $auditsBefore = $this->normalizedDatabaseRows('audit_logs');
        $matchingSubmissionsBefore = DB::table('kyc_provider_submissions')
            ->where('user_id', 6)
            ->where('provider_id', 7)
            ->count();
        $before = $this->freshnessSnapshot();
        $this->assertSame(2, $submission->id);
        $this->assertSame(1, $matchingSubmissionsBefore);
        $queries = [];
        $capturing = true;
        DB::listen(static function (QueryExecuted $query) use (&$queries, &$capturing): void {
            if ($capturing) {
                $queries[] = $query->sql;
            }
        });

        try {
            app(NiumProviderAccountStateService::class)->applyAuthenticatedState(
                $account,
                $payload,
                $source,
            );
            $this->fail('Reserved incomplete fixture broad persistence must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Reserved Nium customer retry fixture requires capability-owned persistence.',
                $exception->getMessage(),
            );
        } finally {
            $capturing = false;
        }

        $dml = array_values(array_filter(
            $queries,
            static fn (string $query): bool => preg_match(
                '/^\\s*(insert|update|delete|replace)\\b/i',
                $query,
            ) === 1,
        ));
        $this->assertSame([], $dml);
        $this->assertSame([], array_values(array_filter(
            $queries,
            static fn (string $query): bool => str_contains(
                strtolower($query),
                'kyc_provider_submissions',
            ),
        )));
        $this->assertSame(
            $accountBefore,
            $this->normalizeSnapshotValue(
                (array) DB::table('user_provider_accounts')->where('id', 4)->first(),
            ),
        );
        $this->assertSame(
            $submissionBefore,
            $this->normalizeSnapshotValue(
                (array) DB::table('kyc_provider_submissions')->where('id', 2)->first(),
            ),
        );
        $this->assertSame(
            $matchingSubmissionsBefore,
            DB::table('kyc_provider_submissions')
                ->where('user_id', 6)
                ->where('provider_id', 7)
                ->count(),
        );
        $this->assertSame($auditsBefore, $this->normalizedDatabaseRows('audit_logs'));
        $this->assertSame($before, $this->freshnessSnapshot());
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'provider_account.nium_security_conflict',
        ]);
        $this->assertRequestCounts(0, 0);
    }

    public static function reservedFixtureBroadBypassAttempts(): array
    {
        $validState = [
            'customerHashId' => self::CUSTOMER_ID,
            'walletHashId' => self::WALLET_ID,
            'status' => 'clear',
            'subStatus' => '',
            'complianceStatus' => 'completed',
        ];

        return [
            'valid-looking customer and wallet state' => [
                [],
                $validState,
                'nium_v5_customer_get_response',
            ],
            'caller-controlled source cannot bypass' => [
                [],
                $validState,
                'caller_claims_capability_owned_strict_persistence',
            ],
            'status fields cannot bypass' => [
                [],
                [
                    ...$validState,
                    'status' => 'suspended',
                    'subStatus' => 'rfi_requested',
                    'complianceStatus' => 'failed',
                ],
                'different_lifecycle_source',
            ],
            'customer identifier already populated remains protected' => [
                ['external_customer_id' => self::CUSTOMER_ID],
                [
                    'walletHashId' => self::WALLET_ID,
                    'status' => 'clear',
                    'subStatus' => '',
                ],
                'verified_wallet_response',
            ],
            'wallet identifier already populated remains protected' => [
                ['external_account_id' => self::WALLET_ID],
                [
                    'customerHashId' => self::CUSTOMER_ID,
                    'status' => 'clear',
                    'subStatus' => '',
                ],
                'verified_customer_response',
            ],
        ];
    }

    public function test_unrelated_account_retains_general_purpose_broad_authenticated_state_behavior(): void
    {
        [$provider] = $this->fixture();
        $account = $this->unrelatedAccount($provider);
        $submission = KycProviderSubmission::query()->forceCreate([
            'id' => 3,
            'user_id' => $account->user_id,
            'provider_id' => 7,
            'provider_account_id' => 5,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $result = app(NiumProviderAccountStateService::class)->applyAuthenticatedState(
            $account,
            [
                'customerHashId' => 'unrelated-customer-id',
                'walletHashId' => 'unrelated-wallet-id',
                'status' => 'clear',
                'subStatus' => '',
            ],
            'general_purpose_customer_get',
        );

        $this->assertSame(5, $result->id);
        $this->assertSame('unrelated-customer-id', $result->external_customer_id);
        $this->assertSame('unrelated-wallet-id', $result->external_account_id);
        $this->assertSame('active', $result->status);
        $this->assertSame('approved', $submission->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_strict_success_enables_later_reserved_fixture_broad_lifecycle_handling(): void
    {
        $this->fixture();
        Http::fake(['*' => Http::response([
            'customers' => [$this->validCustomer()],
        ], 200)]);
        $retry = app(NiumCustomerRetryService::class);
        $result = $this->genuineExisting($retry, $user, $account, $provider);
        $persisted = $retry->persistAuthenticatedCustomer($account, $result);

        $updated = app(NiumProviderAccountStateService::class)->applyAuthenticatedState(
            $persisted,
            [
                'customerHashId' => self::CUSTOMER_ID,
                'walletHashId' => self::WALLET_ID,
                'status' => 'suspended',
            ],
            'general_purpose_lifecycle_response',
        );

        $this->assertSame(4, $updated->id);
        $this->assertSame(self::CUSTOMER_ID, $updated->external_customer_id);
        $this->assertSame(self::WALLET_ID, $updated->external_account_id);
        $this->assertSame('blocked', $updated->status);
        $this->assertSame('rejected', KycProviderSubmission::query()->findOrFail(2)->status);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_v6_guard_and_dual_provider_fingerprint_checks_are_source_enforced(): void
    {
        $stateSource = (string) file_get_contents(
            app_path('Services/Nium/NiumProviderAccountStateService.php'),
        );
        $retrySource = (string) file_get_contents(
            app_path('Services/Nium/NiumCustomerRetryService.php'),
        );
        $providerSource = (string) file_get_contents(app_path('Models/IntegrationProvider.php'));
        $lock = strpos(
            $stateSource,
            'UserProviderAccount::query()->lockForUpdate()->findOrFail($providerAccount->id)',
        );
        $guard = strpos(
            $stateSource,
            '$this->assertNotReservedIncompleteCustomerRetryFixture($providerAccount)',
        );
        $projection = strpos($stateSource, 'return $this->applyAuthenticatedStateToLockedAccount(');

        $this->assertIsInt($lock);
        $this->assertIsInt($guard);
        $this->assertIsInt($projection);
        $this->assertLessThan($guard, $lock);
        $this->assertLessThan($projection, $guard);
        $this->assertStringContainsString(
            'providerObjectMatchesSecurityFingerprint(',
            $retrySource,
        );
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($retrySource, '$this->providerSecurityFingerprint($provider)'),
        );
        $this->assertStringContainsString(
            '$this->providerSecurityFingerprint($provider)',
            $retrySource,
        );
        $this->assertStringContainsString(
            '$this->providerSecurityFingerprint($provider) ??',
            $retrySource,
        );
        $this->assertStringContainsString(
            "config('services.'.strtolower(\$this->code), [])",
            $providerSource,
        );
        $this->assertStringNotContainsString("'service_config'", $providerSource);
    }

    public function test_shared_authenticated_state_projector_is_pure_and_has_no_dml_authority(): void
    {
        $source = (string) file_get_contents(
            app_path('Services/Nium/NiumAuthenticatedStateProjector.php'),
        );

        foreach ([
            'DB::',
            'DB;',
            '->update(',
            '->save(',
            '::create(',
            '::query(',
            'lockForUpdate',
            'UserProviderAccount::',
            'KycProviderSubmission::',
            'AuditLog::',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    public function test_fabricated_success_results_cannot_authorize_persistence(): void
    {
        [, , $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        $service = app(NiumCustomerRetryService::class);
        $fabricated = [
            NiumCustomerLookupResult::existing(200),
            NiumCustomerCreateResult::created(201),
        ];

        foreach ($fabricated as $result) {
            $this->assertTrue($result->isPersistable());
            $this->assertPersistenceRejectedWithoutDatabaseWork($service, $account, $result);
        }

        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_success_result_from_another_service_cannot_authorize_persistence(): void
    {
        [$provider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake(['*' => Http::response([
            'customers' => [$this->validCustomer()],
        ], 200)]);
        $serviceA = app(NiumCustomerRetryService::class);
        $serviceB = app(NiumCustomerRetryService::class);
        $this->assertNotSame($serviceA, $serviceB);
        [$user, $account] = $serviceA->resolveFixtureContext();
        $provider = $serviceA->resolveProvider();
        $result = $serviceA->lookupByExternalReference($user, $account, $provider);

        $this->assertSame(NiumCustomerLookupState::Existing, $result->state);
        $this->assertPersistenceRejectedWithoutDatabaseWork($serviceB, $account, $result);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_persistence_failure_consumes_success_capability_before_state_service_call(): void
    {
        [$provider, $user, $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        Http::fake(['*' => Http::response([
            'customers' => [$this->validCustomer()],
        ], 200)]);
        $projector = $this->mock(NiumAuthenticatedStateProjector::class);
        $projector->shouldReceive('auditState')->once()->andReturn([]);
        $projector->shouldReceive('accountUpdates')
            ->once()
            ->andThrow(new RuntimeException('synthetic projection failure'));
        $service = new NiumCustomerRetryService(
            app(NiumService::class),
            app(NiumProviderHttpClientFactory::class),
            app(NiumCustomerPayloadHashVerifier::class),
            app(NiumCustomerErrorMapper::class),
            $projector,
        );
        [$user, $account] = $service->resolveFixtureContext();
        $provider = $service->resolveProvider();
        $result = $service->lookupByExternalReference($user, $account, $provider);

        try {
            $service->persistAuthenticatedCustomer($account, $result);
            $this->fail('The synthetic persistence failure must be surfaced.');
        } catch (RuntimeException $exception) {
            $this->assertSame('HOLD_LOCAL_PERSISTENCE_FAILED', $exception->getMessage());
        }

        $this->assertPersistenceRejectedWithoutDatabaseWork($service, $account, $result);
        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_nonpersistable_results_cannot_write_account_submission_or_audit(): void
    {
        [, , $account, $submission] = $this->fixture();
        $before = $this->persistenceSnapshot($account, $submission);
        $service = app(NiumCustomerRetryService::class);
        $results = [
            NiumCustomerLookupResult::absent(200),
            NiumCustomerLookupResult::ambiguous(200, false, false, 'lookup_customers_missing'),
            NiumCustomerLookupResult::failed(503, 'lookup_provider_server_error'),
            NiumCustomerCreateResult::duplicate(409),
            NiumCustomerCreateResult::invalidResponse(200, false, false, 'create_response_shape_invalid'),
            NiumCustomerCreateResult::failed(503, 'create_provider_server_error'),
        ];

        foreach ($results as $result) {
            try {
                $service->persistAuthenticatedCustomer($account, $result);
                $this->fail('A non-persistable result must be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'Nium customer retry result cannot be persisted.',
                    $exception->getMessage(),
                );
            }
        }

        $this->assertSame($before, $this->persistenceSnapshot($account, $submission));
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertFakeStorageEmpty();
    }

    public function test_hash_verifier_uses_fixed_v4_recursive_canonicalization(): void
    {
        $verifier = new NiumCustomerPayloadHashVerifier;
        $first = [
            'z' => ['b' => 2, 'a' => 1],
            'a' => [['z' => 3, 'a' => 4]],
            'fraction' => 1.0,
        ];
        $second = [
            'fraction' => 1.0,
            'a' => [['a' => 4, 'z' => 3]],
            'z' => ['a' => 1, 'b' => 2],
        ];

        $this->assertSame($verifier->sha256($first), $verifier->sha256($second));
        $this->assertFalse($verifier->matchesApproved($first));
        $this->assertSame(
            '157398db48ce68929749db78304b012845dc62c6ac5bc53eab1909874c08c0e3',
            NiumCustomerPayloadHashVerifier::APPROVED_SHA256,
        );
    }

    public function test_result_factories_accept_every_production_failure_category(): void
    {
        foreach ([
            'lookup_customers_missing',
            'lookup_customers_not_list',
            'lookup_customer_count_invalid',
            'lookup_external_reference_mismatch',
            'lookup_identifiers_missing',
            'lookup_identifier_conflict',
        ] as $category) {
            $result = NiumCustomerLookupResult::ambiguous(200, false, false, $category);
            $this->assertSame($category, $result->failureCategory);
        }

        foreach ([
            'lookup_provider_provenance_invalid',
            'lookup_transport_failed',
            'lookup_response_decode_failed',
            'lookup_authentication_failed',
            'lookup_rate_limited',
            'lookup_provider_server_error',
            'lookup_provider_failed',
        ] as $category) {
            $result = NiumCustomerLookupResult::failed(null, $category);
            $this->assertSame($category, $result->failureCategory);
        }

        foreach ([
            'create_response_shape_invalid',
            'create_identifiers_missing',
            'create_external_reference_mismatch',
            'create_identifier_conflict',
        ] as $category) {
            $result = NiumCustomerCreateResult::invalidResponse(200, false, false, $category);
            $this->assertSame($category, $result->failureCategory);
        }

        foreach ([
            'create_lookup_provenance_invalid',
            'payload_hash_mismatch',
            'payload_verification_failed',
            'create_transport_failed',
            'create_mapper_failed',
            'create_authentication_failed',
            'create_rate_limited',
            'create_provider_server_error',
            'create_provider_failed',
        ] as $category) {
            $result = NiumCustomerCreateResult::failed(null, $category);
            $this->assertSame($category, $result->failureCategory);
        }

        $this->assertSame(
            'duplicate_external_id',
            NiumCustomerCreateResult::duplicate(409)->failureCategory,
        );
    }

    public function test_result_factories_reject_arbitrary_failure_categories(): void
    {
        $unapproved = 'synthetic_person@example.test secret=synthetic-credential';
        $attempts = [
            static fn () => NiumCustomerLookupResult::ambiguous(200, false, false, $unapproved),
            static fn () => NiumCustomerLookupResult::failed(500, $unapproved),
            static fn () => NiumCustomerCreateResult::invalidResponse(200, false, false, $unapproved),
            static fn () => NiumCustomerCreateResult::failed(500, $unapproved),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('An unapproved outward failure category must be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('failure category is invalid', $exception->getMessage());
                $this->assertStringNotContainsString($unapproved, $exception->getMessage());
            }
        }
    }

    public function test_result_factories_reject_http_status_outside_100_to_599(): void
    {
        $attempts = [
            static fn () => NiumCustomerLookupResult::existing(99),
            static fn () => NiumCustomerLookupResult::absent(600),
            static fn () => NiumCustomerLookupResult::failed(0, 'lookup_transport_failed'),
            static fn () => NiumCustomerCreateResult::created(99),
            static fn () => NiumCustomerCreateResult::duplicate(600),
            static fn () => NiumCustomerCreateResult::failed(700, 'create_transport_failed'),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('An invalid outward HTTP status must be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('HTTP status is invalid', $exception->getMessage());
            }
        }

        $this->assertNull(
            NiumCustomerLookupResult::failed(null, 'lookup_transport_failed')->httpStatus,
        );
        $this->assertNull(
            NiumCustomerCreateResult::failed(null, 'create_transport_failed')->httpStatus,
        );
    }

    public function test_lookup_persistence_receives_only_minimal_authenticated_state_projection(): void
    {
        [$provider, $user, $account] = $this->fixture();
        $response = [
            ...$this->validCustomer(),
            'complianceStatus' => 'COMPLETED',
            'oddStatus' => 'clear',
            'isResubmissionAllowed' => true,
            'name' => 'Synthetic Private Name',
            'email' => 'private@example.test',
            'address' => ['line1' => 'Synthetic Private Address'],
            'document' => ['number' => 'SYNTHETIC-DOCUMENT'],
            'secret' => 'synthetic-secret',
            'unknownMetadata' => ['retain' => false],
        ];
        Http::fake(['*' => Http::response(['customers' => [$response]], 200)]);
        $projector = \Mockery::mock(
            NiumAuthenticatedStateProjector::class,
            [app(NiumSafeValueProjector::class)],
        )->makePartial();
        $expected = $this->minimalAuthenticatedState();
        $service = $this->serviceWithProjector($projector);
        [$user, $account] = $service->resolveFixtureContext();
        $projector->shouldReceive('accountUpdates')
            ->once()
            ->withArgs(static fn (
                UserProviderAccount $lockedAccount,
                array $payload,
                string $source,
            ): bool => (int) $lockedAccount->getKey() === 4
                && $payload === $expected
                && $source === 'nium_v5_customer_list_response'
            )
            ->passthru();
        $provider = $service->resolveProvider();
        $result = $service->lookupByExternalReference($user, $account, $provider);

        $persisted = $service->persistAuthenticatedCustomer($account, $result);

        $this->assertSame(4, (int) $persisted->getKey());
        $this->assertSame(NiumCustomerLookupState::Existing, $result->state);
    }

    public function test_create_persistence_receives_only_minimal_authenticated_state_projection(): void
    {
        [$provider, $user, $account] = $this->fixture();
        $response = [
            ...$this->validCustomer(),
            'complianceStatus' => 'COMPLETED',
            'oddStatus' => 'clear',
            'isResubmissionAllowed' => true,
            'name' => 'Synthetic Private Name',
            'email' => 'private@example.test',
            'phone' => '+6500000000',
            'dateOfBirth' => '1990-01-01',
            'address' => ['line1' => 'Synthetic Private Address'],
            'document' => ['number' => 'SYNTHETIC-DOCUMENT'],
            'credentials' => ['apiKey' => 'synthetic-secret'],
            'unknownMetadata' => ['retain' => false],
        ];
        Http::fake(['*' => Http::sequence()
            ->push(['customers' => []], 200)
            ->push($response, 201)]);
        $verifier = $this->mock(NiumCustomerPayloadHashVerifier::class);
        $verifier->shouldReceive('matchesApproved')->once()->andReturn(true);
        $projector = \Mockery::mock(
            NiumAuthenticatedStateProjector::class,
            [app(NiumSafeValueProjector::class)],
        )->makePartial();
        $expected = $this->minimalAuthenticatedState();
        $service = $this->serviceWithVerifierAndProjector($verifier, $projector);
        $absent = $this->genuineAbsent($service, $user, $account, $provider);
        $projector->shouldReceive('accountUpdates')
            ->once()
            ->withArgs(static fn (
                UserProviderAccount $lockedAccount,
                array $payload,
                string $source,
            ): bool => (int) $lockedAccount->getKey() === 4
                && $payload === $expected
                && $source === 'nium_v5_customer_create_response'
            )
            ->passthru();
        $result = $service->createCustomer(
            $user,
            $account,
            $provider,
            $absent,
            ['synthetic' => 'approved-payload'],
        );

        $persisted = $service->persistAuthenticatedCustomer($account, $result);

        $this->assertSame(4, (int) $persisted->getKey());
        $this->assertSame(NiumCustomerCreateState::Created, $result->state);
        $this->assertRequestCounts(1, 1);
    }

    public function test_result_types_expose_no_authenticated_state_or_persistence_api(): void
    {
        $resultClasses = [
            NiumCustomerLookupResult::class,
            NiumCustomerCreateResult::class,
        ];

        foreach ($resultClasses as $resultClass) {
            $reflection = new \ReflectionClass($resultClass);
            $this->assertFalse($reflection->hasMethod('applyTo'));
            $this->assertFalse($reflection->hasProperty('authenticatedState'));

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getParameters() as $parameter) {
                    $parameterType = (string) $parameter->getType();
                    $this->assertNotSame(
                        NiumProviderAccountStateService::class,
                        $parameterType,
                    );
                    $this->assertNotSame(UserProviderAccount::class, $parameterType);
                }
            }

            $source = (string) file_get_contents($reflection->getFileName());
            $this->assertStringNotContainsString('authenticatedState', $source);
            $this->assertStringNotContainsString('applyTo', $source);
            $this->assertStringNotContainsString('NiumProviderAccountStateService', $source);
            $this->assertStringNotContainsString('UserProviderAccount', $source);
        }

        $lookupFactory = new \ReflectionMethod(NiumCustomerLookupResult::class, 'existing');
        $createFactory = new \ReflectionMethod(NiumCustomerCreateResult::class, 'created');
        $this->assertCount(1, $lookupFactory->getParameters());
        $this->assertCount(1, $createFactory->getParameters());
        $this->assertSame('int', (string) $lookupFactory->getParameters()[0]->getType());
        $this->assertSame('int', (string) $createFactory->getParameters()[0]->getType());
    }

    public function test_boundary_source_has_no_broad_onboarding_or_side_effect_dependencies(): void
    {
        $serviceSource = (string) file_get_contents(
            app_path('Services/Nium/NiumCustomerRetryService.php'),
        );
        $factorySource = (string) file_get_contents(
            app_path('Services/Nium/NiumProviderHttpClientFactory.php'),
        );
        $stateSource = (string) file_get_contents(
            app_path('Services/Nium/NiumProviderAccountStateService.php'),
        );
        $lookupResultSource = (string) file_get_contents(
            app_path('Services/Nium/NiumCustomerLookupResult.php'),
        );
        $createResultSource = (string) file_get_contents(
            app_path('Services/Nium/NiumCustomerCreateResult.php'),
        );
        $boundarySource = $serviceSource."\n".$factorySource;

        foreach ([
            'NiumCustomerOnboardingService',
            'NiumCustomerDocumentResolver',
            'NiumCustomerDocumentPreparationService',
            'NiumFileService',
            'Storage::',
            'Queue::',
            'dispatch(',
            'sleep(',
            'usleep(',
            'retry(',
            'curl_',
            'firstOrCreate',
            'markReconciliationFailure',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $boundarySource);
        }

        $this->assertSame(1, substr_count($serviceSource, '->get('));
        $this->assertSame(1, substr_count($serviceSource, '->post('));
        $this->assertStringContainsString(
            'UserProviderAccount::query()->lockForUpdate()->findOrFail($providerAccount->id)',
            $stateSource,
        );
        $this->assertStringNotContainsString(
            'applyCustomerRetryAuthenticatedState',
            $serviceSource."\n".$stateSource."\n".$lookupResultSource."\n".$createResultSource,
        );
        $this->assertStringNotContainsString('applyAuthenticatedState', $lookupResultSource);
        $this->assertStringNotContainsString('applyAuthenticatedState', $createResultSource);
        $this->assertStringContainsString('private WeakMap $providerProvenance;', $serviceSource);
        $this->assertStringContainsString('private WeakMap $fixtureUserProvenance;', $serviceSource);
        $this->assertStringContainsString('private WeakMap $fixtureAccountProvenance;', $serviceSource);
        $this->assertStringContainsString('private WeakMap $lookupProvenance;', $serviceSource);
        $this->assertStringContainsString('private WeakMap $successCapabilities;', $serviceSource);
        $this->assertStringContainsString('->whereKey(self::PROVIDER_ACCOUNT_ID)', $serviceSource);
        $this->assertStringContainsString('->whereKey(self::SUBMISSION_ID)', $serviceSource);
    }

    private function serviceWithHashDecision(bool $approved): NiumCustomerRetryService
    {
        $verifier = $this->mock(NiumCustomerPayloadHashVerifier::class);
        $verifier->shouldReceive('matchesApproved')
            ->once()
            ->withArgs(static fn (array $payload): bool => $payload !== [])
            ->andReturn($approved);

        return $this->serviceWithVerifier($verifier);
    }

    private function serviceWithVerifierNeverCalled(): NiumCustomerRetryService
    {
        $verifier = $this->mock(NiumCustomerPayloadHashVerifier::class);
        $verifier->shouldNotReceive('matchesApproved');

        return $this->serviceWithVerifier($verifier);
    }

    private function serviceWithVerifier(
        NiumCustomerPayloadHashVerifier $verifier,
    ): NiumCustomerRetryService {
        return $this->serviceWithVerifierAndProjector(
            $verifier,
            app(NiumAuthenticatedStateProjector::class),
        );
    }

    private function serviceWithProjector(
        NiumAuthenticatedStateProjector $projector,
    ): NiumCustomerRetryService {
        return $this->serviceWithVerifierAndProjector(
            app(NiumCustomerPayloadHashVerifier::class),
            $projector,
        );
    }

    private function serviceWithVerifierAndProjector(
        NiumCustomerPayloadHashVerifier $verifier,
        NiumAuthenticatedStateProjector $projector,
    ): NiumCustomerRetryService {
        return new NiumCustomerRetryService(
            app(NiumService::class),
            app(NiumProviderHttpClientFactory::class),
            $verifier,
            app(NiumCustomerErrorMapper::class),
            $projector,
        );
    }

    private function genuineAbsent(
        NiumCustomerRetryService $service,
        User &$user,
        UserProviderAccount &$account,
        IntegrationProvider &$provider,
    ): NiumCustomerLookupResult {
        [$user, $account] = $service->resolveFixtureContext();
        $provider = $service->resolveProvider();
        $result = $service->lookupByExternalReference($user, $account, $provider);

        $this->assertSame(NiumCustomerLookupState::Absent, $result->state);

        return $result;
    }

    private function genuineExisting(
        NiumCustomerRetryService $service,
        ?User &$user,
        ?UserProviderAccount &$account,
        ?IntegrationProvider &$provider,
    ): NiumCustomerLookupResult {
        [$user, $account] = $service->resolveFixtureContext();
        $provider = $service->resolveProvider();
        $result = $service->lookupByExternalReference($user, $account, $provider);

        $this->assertSame(NiumCustomerLookupState::Existing, $result->state);

        return $result;
    }

    private function fabricatedUser(User $source): User
    {
        $fabricated = new User;
        $fabricated->forceFill($source->getAttributes());
        $fabricated->exists = true;

        return $fabricated;
    }

    private function fabricatedAccount(UserProviderAccount $source): UserProviderAccount
    {
        $fabricated = new UserProviderAccount;
        $fabricated->forceFill($source->getAttributes());
        $fabricated->exists = true;

        return $fabricated;
    }

    private function fabricatedProvider(IntegrationProvider $source): IntegrationProvider
    {
        $fabricated = new IntegrationProvider;
        $fabricated->forceFill($source->getAttributes());
        $fabricated->exists = true;

        return $fabricated;
    }

    private function minimalAuthenticatedState(): array
    {
        return [
            'customerHashId' => self::CUSTOMER_ID,
            'walletHashId' => self::WALLET_ID,
            'status' => 'clear',
            'subStatus' => null,
            'complianceStatus' => 'completed',
            'oddStatus' => 'clear',
            'isResubmissionAllowed' => true,
        ];
    }

    private function provider(): IntegrationProvider
    {
        return IntegrationProvider::query()->forceCreate([
            'id' => 7,
            'code' => 'nium',
            'name' => 'Synthetic Nium',
            'status' => 'active',
        ]);
    }

    private function fixture(
        ?string $reconciliationStatus = 'pending',
        ?string $reconciliationError = null,
        array $accountOverrides = [],
    ): array
    {
        $provider = $this->provider();
        $user = User::factory()->create([
            'id' => 6,
            'email' => 'fixture-v2@example.test',
            'phone' => '+6500000000',
            'full_name' => 'Fixture V2 Synthetic',
            'kyc_status' => 'verified',
        ]);
        $account = UserProviderAccount::query()->forceCreate(array_replace([
            'id' => 4,
            'user_id' => 6,
            'provider_id' => 7,
            'external_reference' => self::EXTERNAL_REFERENCE,
            'status' => 'submitted',
            'provider_status' => 'pending',
            'reconciliation_status' => $reconciliationStatus,
            'reconciliation_error' => $reconciliationError,
            'metadata' => ['integration_status' => 'nium_pending'],
        ], $accountOverrides));
        $submission = KycProviderSubmission::query()->forceCreate([
            'id' => 2,
            'user_id' => 6,
            'provider_id' => 7,
            'provider_account_id' => 4,
            'status' => 'submitted',
            'submitted_at' => now(),
            'metadata' => ['provider_status' => 'pending'],
        ]);

        return [$provider, $user, $account, $submission];
    }

    private function unrelatedAccount(IntegrationProvider $provider): UserProviderAccount
    {
        $user = User::factory()->create(['id' => 7]);

        return UserProviderAccount::query()->forceCreate([
            'id' => 5,
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'external_reference' => 'unrelated-synthetic-reference',
            'status' => 'pending',
            'provider_status' => 'pending',
            'metadata' => ['unrelated' => 'unchanged'],
        ]);
    }

    private function validCustomer(): array
    {
        return [
            'externalId' => self::EXTERNAL_REFERENCE,
            'customerHashId' => self::CUSTOMER_ID,
            'status' => 'clear',
            'subStatus' => '',
            'wallets' => [['walletHashId' => self::WALLET_ID]],
        ];
    }

    private function replaceExactReferenceMarker(array $payload): array
    {
        array_walk_recursive($payload, static function (&$value): void {
            if ($value === '@exact') {
                $value = self::EXTERNAL_REFERENCE;
            }
        });

        return $payload;
    }

    private function persistenceSnapshot(
        UserProviderAccount $account,
        KycProviderSubmission $submission,
    ): array {
        return [
            'account' => $account->fresh()->getRawOriginal(),
            'submission' => $submission->fresh()->getRawOriginal(),
            'audit_count' => AuditLog::query()->count(),
        ];
    }

    private function strictPersistenceSnapshot(): array
    {
        return [
            'account' => (array) DB::table('user_provider_accounts')->where('id', 4)->first(),
            'submissions' => DB::table('kyc_provider_submissions')
                ->orderBy('id')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
            'audits' => DB::table('audit_logs')->orderBy('id')->get()->all(),
        ];
    }

    private function assertFixtureContextRejected(): void
    {
        try {
            app(NiumCustomerRetryService::class)->resolveFixtureContext();
            $this->fail('Unsafe fixture reconciliation state must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Nium customer retry fixture context is unavailable.',
                $exception->getMessage(),
            );
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('api_request_logs', 0);
    }

    private function assertFixtureLookupRejected(
        NiumCustomerRetryService $service,
        User $user,
        UserProviderAccount $account,
        IntegrationProvider $provider,
        int $expectedCompletedRequests = 0,
    ): void {
        try {
            $service->lookupByExternalReference($user, $account, $provider);
            $this->fail('Fixture provenance must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Nium customer retry fixture context is invalid.',
                $exception->getMessage(),
            );
        }

        Http::assertSentCount($expectedCompletedRequests);
        $this->assertDatabaseCount('api_request_logs', $expectedCompletedRequests);
    }

    private function assertSingleRequest(string $method): void
    {
        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => $request->method() === $method);
    }

    private function assertRequestCounts(int $getCount, int $postCount): void
    {
        $this->assertCount(
            $getCount,
            Http::recorded(
                static fn (Request $request): bool => $request->method() === 'GET',
            ),
        );
        $this->assertCount(
            $postCount,
            Http::recorded(
                static fn (Request $request): bool => $request->method() === 'POST',
            ),
        );
        Http::assertSentCount($getCount + $postCount);
    }

    private function assertSafeCompletedResponseLog(
        array $forbiddenRawValues,
        int $expectedCount = 1,
    ): void {
        $this->assertDatabaseCount('api_request_logs', $expectedCount);
        $serialized = json_encode(
            ApiRequestLog::query()->get()->map(static fn (ApiRequestLog $log): array => [
                $log->request_url,
                $log->request_headers,
                $log->request_body,
                $log->response_headers,
                $log->response_body,
            ])->all(),
            JSON_THROW_ON_ERROR,
        );

        foreach ($forbiddenRawValues as $rawValue) {
            $this->assertStringNotContainsString($rawValue, $serialized);
        }
    }

    private function assertPersistenceRejectedWithoutDatabaseWork(
        NiumCustomerRetryService $service,
        UserProviderAccount $account,
        NiumCustomerLookupResult|NiumCustomerCreateResult $result,
    ): void {
        $queries = [];
        $capturing = true;
        DB::listen(static function (QueryExecuted $query) use (&$queries, &$capturing): void {
            if ($capturing) {
                $queries[] = $query->sql;
            }
        });

        try {
            $service->persistAuthenticatedCustomer($account, $result);
            $this->fail('A result without a live capability must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Nium customer retry result cannot be persisted.',
                $exception->getMessage(),
            );
        } finally {
            $capturing = false;
        }

        $this->assertSame([], $queries);
    }

    private function assertStrictPersistenceRejectedWithoutDml(
        NiumCustomerRetryService $service,
        UserProviderAccount $account,
        NiumCustomerLookupResult|NiumCustomerCreateResult $result,
    ): void {
        $queries = [];
        $capturing = true;
        DB::listen(static function (QueryExecuted $query) use (&$queries, &$capturing): void {
            if (
                $capturing
                && preg_match('/^\\s*(insert|update|delete|replace)\\b/i', $query->sql) === 1
            ) {
                $queries[] = $query->sql;
            }
        });

        try {
            $service->persistAuthenticatedCustomer($account, $result);
            $this->fail('Locked persistence context must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'HOLD_LOCAL_PERSISTENCE_FAILED',
                $exception->getMessage(),
            );
        } finally {
            $capturing = false;
        }

        $this->assertSame([], $queries);
        $this->assertPersistenceRejectedWithoutDatabaseWork($service, $account, $result);
    }

    private function mutateFreshnessContext(string $mutation): void
    {
        switch ($mutation) {
            case 'user_status':
                DB::table('users')->where('id', 6)->update(['status' => 'inactive']);
                break;
            case 'user_kyc':
                DB::table('users')->where('id', 6)->update(['kyc_status' => 'pending']);
                break;
            case 'account_reference':
                DB::table('user_provider_accounts')
                    ->where('id', 4)
                    ->update(['external_reference' => 'changed-database-reference']);
                break;
            case 'account_identifier':
                DB::table('user_provider_accounts')
                    ->where('id', 4)
                    ->update(['external_customer_id' => 'changed-database-customer-id']);
                break;
            case 'account_state':
                DB::table('user_provider_accounts')
                    ->where('id', 4)
                    ->update(['provider_status' => 'clear']);
                break;
            case 'account_user_relationship':
                $this->mutateAccountUserRelationship();
                break;
            case 'account_provider_relationship':
                $this->mutateAccountProviderRelationship();
                break;
            case 'provider_code':
                DB::table('integration_providers')
                    ->where('id', 7)
                    ->update(['code' => 'changed_provider']);
                break;
            case 'provider_status':
                DB::table('integration_providers')
                    ->where('id', 7)
                    ->update(['status' => 'inactive']);
                break;
            case 'provider_config':
                config()->set(
                    'services.nium.customer_list_endpoint',
                    '/api/v5/client/{clientHashId}/customers/changed',
                );
                break;
            case 'submission_missing':
                DB::table('kyc_provider_submissions')->where('id', 2)->delete();
                break;
            case 'submission_relationship':
                $this->mutateSubmissionRelationship();
                break;
            case 'submission_state':
                DB::table('kyc_provider_submissions')
                    ->where('id', 2)
                    ->update([
                        'status' => 'failed',
                        'failure_reason' => 'synthetic local failure',
                    ]);
                break;
            case 'submission_approved':
                DB::table('kyc_provider_submissions')
                    ->where('id', 2)
                    ->update(['status' => 'approved', 'approved_at' => now()]);
                break;
            case 'submission_rejected':
                DB::table('kyc_provider_submissions')
                    ->where('id', 2)
                    ->update(['status' => 'rejected', 'rejected_at' => now()]);
                break;
            default:
                throw new InvalidArgumentException('Unknown freshness mutation.');
        }
    }

    private function mutateAccountUserRelationship(): int
    {
        User::factory()->create(['id' => 7]);

        return DB::table('user_provider_accounts')
            ->where('id', 4)
            ->update(['user_id' => 7]);
    }

    private function mutateAccountProviderRelationship(): int
    {
        IntegrationProvider::query()->forceCreate([
            'id' => 8,
            'code' => 'synthetic_other',
            'name' => 'Synthetic Other',
            'status' => 'active',
        ]);

        return DB::table('user_provider_accounts')
            ->where('id', 4)
            ->update(['provider_id' => 8]);
    }

    private function mutateSubmissionRelationship(): int
    {
        User::factory()->create(['id' => 7]);

        return DB::table('kyc_provider_submissions')
            ->where('id', 2)
            ->update(['user_id' => 7]);
    }

    private function freshnessSnapshot(): array
    {
        return [
            'users' => $this->normalizedDatabaseRows('users'),
            'providers' => $this->normalizedDatabaseRows('integration_providers'),
            'accounts' => $this->normalizedDatabaseRows('user_provider_accounts'),
            'submissions' => $this->normalizedDatabaseRows('kyc_provider_submissions'),
            'api_logs' => $this->normalizedDatabaseRows('api_request_logs'),
            'audits' => $this->normalizedDatabaseRows('audit_logs'),
            'provider_config' => $this->normalizeSnapshotValue(config('services.nium')),
        ];
    }

    private function normalizedDatabaseRows(string $table): array
    {
        return DB::table($table)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => $this->normalizeSnapshotValue((array) $row))
            ->all();
    }

    private function normalizeSnapshotValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return is_string($value) || is_int($value) || is_float($value)
                || is_bool($value) || $value === null
                    ? $value
                    : (string) $value;
        }

        if (array_is_list($value)) {
            return array_map($this->normalizeSnapshotValue(...), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $child) {
            $value[$key] = $this->normalizeSnapshotValue($child);
        }

        return $value;
    }

    private function assertFakeStorageEmpty(): void
    {
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('kyc_private')->allFiles());
    }

    private function integrationProviderDml(array $queries): array
    {
        return array_values(array_filter(
            $queries,
            static fn (string $query): bool => preg_match(
                '/^\\s*(insert|update|delete|replace)\\b/i',
                $query,
            ) === 1 && str_contains(strtolower($query), 'integration_providers'),
        ));
    }
}
