<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\KycDocument;
use App\Models\KycProfile;
use App\Models\KycRelatedPerson;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumCustomerPayloadFactory;
use App\Services\Nium\NiumCustomerDocumentResolver;
use App\Services\Nium\NiumHkCorporateV5Validator;
use App\Services\Nium\NiumHkCustomerV5OneShotRunner;
use App\Services\Nium\NiumProviderAccountStateService;
use App\Services\Nium\NiumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class NiumHkCustomerV5OneShotRunnerTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $executionRoots = [];

    private array $payload;

    private string $lockedPayloadSha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn (): string => 'staging');
        config([
            'services.nium.regulatory_region' => 'HK',
            'services.nium.client_id' => 'safe-test-client',
            'services.nium.customer_list_endpoint' => '/api/v5/client/{clientHashId}/customers',
            'services.nium.customer_create_endpoint' => '/api/v5/client/{clientHashId}/customers',
        ]);
        $this->seedCheckpoint();
    }

    protected function tearDown(): void
    {
        foreach ($this->executionRoots as $root) {
            @unlink($root.'/EXECUTION_STARTED');
            @rmdir($root);
        }

        parent::tearDown();
    }

    public function test_existing_customer_uses_one_lookup_zero_posts_and_persists_identifiers(): void
    {
        $calls = $this->mockGateway([
            'customers' => [[
                'externalId' => 'origin-wallet-user-9',
                'customerHashId' => 'customer-safe-test',
                'walletHashId' => 'wallet-safe-test',
            ]],
        ]);

        $result = $this->runner()->run($this->executionRoot());

        $this->assertSame('PASS_EXISTING_CUSTOMER_FOUND', $result['terminal']);
        $this->assertSame(['GET'], $calls->methods);
        $this->assertNotNull(UserProviderAccount::query()->findOrFail(7)->external_customer_id);
        $this->assertSame(7, $result['fixture_customer_post_count']);
        $this->assertSame(96, $result['api_request_log_count']);
    }

    public function test_existing_customer_without_wallet_passes_and_persists_only_customer(): void
    {
        $calls = $this->mockGateway([
            'customers' => [[
                'externalId' => 'origin-wallet-user-9',
                'customerHashId' => 'customer-safe-test',
            ]],
        ]);

        $result = $this->runner()->run($this->executionRoot());
        $account = UserProviderAccount::query()->findOrFail(7);

        $this->assertSame('PASS_EXISTING_CUSTOMER_FOUND', $result['terminal']);
        $this->assertSame(['GET'], $calls->methods);
        $this->assertSame('customer-safe-test', $account->external_customer_id);
        $this->assertNull($account->external_account_id);
        $this->assertSame(7, $result['fixture_customer_post_count']);
    }

    public function test_existing_lookup_without_customer_identifier_holds_without_post(): void
    {
        $calls = $this->mockGateway([
            'customers' => [[
                'externalId' => 'origin-wallet-user-9',
                'walletHashId' => 'wallet-safe-test',
            ]],
        ]);

        $result = $this->runner()->run($this->executionRoot());

        $this->assertSame('HOLD_LOOKUP_OUTCOME_UNKNOWN', $result['terminal']);
        $this->assertSame(['GET'], $calls->methods);
        $this->assertSame(7, $result['fixture_customer_post_count']);
    }

    public function test_unknown_lookup_never_posts(): void
    {
        $calls = $this->mockGateway(['unexpected' => []]);

        $result = $this->runner()->run($this->executionRoot());

        $this->assertSame('HOLD_LOOKUP_OUTCOME_UNKNOWN', $result['terminal']);
        $this->assertSame(['GET'], $calls->methods);
        $this->assertSame(7, $result['fixture_customer_post_count']);
    }

    public function test_absent_lookup_permits_exactly_one_successful_create_and_stops(): void
    {
        $calls = $this->mockGateway(['customers' => []], [
            'customerHashId' => 'customer-safe-test',
            'walletHashId' => 'wallet-safe-test',
        ]);

        $result = $this->runner()->run($this->executionRoot());

        $this->assertSame('PASS_CUSTOMER_CREATED', $result['terminal']);
        $this->assertSame(['GET', 'POST'], $calls->methods);
        $this->assertSame(8, $result['fixture_customer_post_count']);
        $this->assertSame(97, $result['api_request_log_count']);
        $account = UserProviderAccount::query()->findOrFail(7);
        $this->assertNotNull($account->external_customer_id);
        $this->assertSame('nium-v5-hk-customer-create-8-factual-registered-state-v4', $account->metadata['customer_v5_submission_marker']);
        $this->assertSame('nium-v5-hk-customer-create-7-factual-declaration-timestamp-v3', $account->metadata['customer_v5_previous_submission']['submission_marker']);
        $this->assertSame('customer_create_rejected', $account->metadata['customer_v5_previous_submission']['submission_state']);
        $this->assertSame('ec95fad0d7560c528173cbaf9317bb80ea843faef39dbc6facb33c915a40c571', $account->metadata['customer_v5_previous_submission']['payload_fingerprint']);
        $this->assertSame(400, $account->metadata['customer_v5_previous_submission']['provider_response_status']);
        $this->assertSame('5a45f7beaf2d0ce6', $account->metadata['customer_v5_previous_submission']['provider_error_field_fingerprint']);
        $this->assertSame(
            [
                'nium-v5-hk-customer-create-4',
                'nium-v5-hk-customer-create-5-factual-v1',
                'nium-v5-hk-customer-create-6-factual-business-type-v2',
                'nium-v5-hk-customer-create-7-factual-declaration-timestamp-v3',
            ],
            collect($account->metadata['customer_v5_submission_history'])->pluck('submission_marker')->all(),
        );
    }

    public function test_successful_create_without_wallet_passes_and_persists_only_customer(): void
    {
        $calls = $this->mockGateway(['customers' => []], [
            'customerHashId' => 'customer-safe-test',
        ]);

        $result = $this->runner()->run($this->executionRoot());
        $account = UserProviderAccount::query()->findOrFail(7);

        $this->assertSame('PASS_CUSTOMER_CREATED', $result['terminal']);
        $this->assertSame(['GET', 'POST'], $calls->methods);
        $this->assertSame('customer-safe-test', $account->external_customer_id);
        $this->assertNull($account->external_account_id);
        $this->assertSame(8, $result['fixture_customer_post_count']);
    }

    public function test_successful_create_without_customer_identifier_holds_without_replay(): void
    {
        $calls = $this->mockGateway(['customers' => []], [
            'walletHashId' => 'wallet-safe-test',
        ]);

        $result = $this->runner()->run($this->executionRoot());

        $this->assertSame('HOLD_RESPONSE_REVIEW', $result['terminal']);
        $this->assertSame(['GET', 'POST'], $calls->methods);
        $this->assertSame(8, $result['fixture_customer_post_count']);
        $this->assertFalse(UserProviderAccount::query()->findOrFail(7)->metadata['is_resubmission_allowed']);
    }

    #[DataProvider('unsafeLookupResponses')]
    public function test_malformed_multiple_and_mismatched_lookup_responses_never_post(array $lookupBody): void
    {
        $calls = $this->mockGateway($lookupBody);

        $result = $this->runner()->run($this->executionRoot());

        $this->assertSame('HOLD_LOOKUP_OUTCOME_UNKNOWN', $result['terminal']);
        $this->assertSame(['GET'], $calls->methods);
        $this->assertSame(7, $result['fixture_customer_post_count']);
    }

    public static function unsafeLookupResponses(): array
    {
        return [
            'malformed object' => [['unexpected' => []]],
            'customers not list shaped' => [['customers' => ['customerHashId' => 'customer-safe-test']]],
            'multiple customers' => [['customers' => [
                ['externalId' => 'origin-wallet-user-9', 'customerHashId' => 'customer-safe-test-1'],
                ['externalId' => 'origin-wallet-user-9', 'customerHashId' => 'customer-safe-test-2'],
            ]]],
            'mismatched external reference' => [['customers' => [[
                'externalId' => 'another-fixture',
                'customerHashId' => 'customer-safe-test',
            ]]]],
            'non-string customer identifier' => [['customers' => [[
                'externalId' => 'origin-wallet-user-9',
                'customerHashId' => [],
            ]]]],
        ];
    }

    public function test_safe_output_contains_no_raw_identifiers_file_ids_or_pii(): void
    {
        $this->mockGateway(['customers' => []], [
            'customerHashId' => 'customer-safe-test',
            'walletHashId' => 'wallet-safe-test',
        ]);

        $output = json_encode($this->runner()->run($this->executionRoot()), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('customer-safe-test', $output);
        $this->assertStringNotContainsString('wallet-safe-test', $output);
        $this->assertStringNotContainsString('20000000-0000-4000-8000-', $output);
        $this->assertStringNotContainsString('applicant@example.test', $output);
        $this->assertStringNotContainsString('origin-wallet-user-9', $output);
        $this->assertStringNotContainsString('30000000-0000-4000-8000-000000000009', $output);
    }

    public function test_valid_payload_device_session_passes_without_identity_verification_session_row(): void
    {
        $this->assertDatabaseCount('identity_verification_sessions', 0);
        $calls = $this->mockGateway(['unexpected' => []]);

        $result = $this->runner()->run($this->executionRoot());

        $this->assertSame('HOLD_LOOKUP_OUTCOME_UNKNOWN', $result['terminal']);
        $this->assertSame(['GET'], $calls->methods);
        $this->assertDatabaseCount('identity_verification_sessions', 0);
    }

    public function test_empty_payload_device_session_fails_before_http_without_identity_row(): void
    {
        data_set($this->payload, 'deviceDetails.sessionId', '');

        $this->assertPreflightFailureBeforeHttp();
        $this->assertDatabaseCount('identity_verification_sessions', 0);
    }

    public function test_hk_payload_missing_required_device_details_fails_without_identity_row(): void
    {
        unset($this->payload['deviceDetails']);
        $this->assertDatabaseCount('identity_verification_sessions', 0);
        $this->assertPreflightFailureBeforeHttp();
        $this->assertDatabaseCount('identity_verification_sessions', 0);
    }

    public function test_factual_vn_device_country_matching_approved_metadata_passes_preflight(): void
    {
        $calls = $this->mockGateway(['unexpected' => []]);

        $result = $this->runner()->run($this->executionRoot());

        $this->assertSame('HOLD_LOOKUP_OUTCOME_UNKNOWN', $result['terminal']);
        $this->assertSame(['GET'], $calls->methods);
    }

    public function test_device_country_mismatching_approved_metadata_fails_before_http(): void
    {
        data_set($this->payload, 'deviceDetails.ipCountryCode', 'SG');

        $this->assertPreflightFailureBeforeHttp();
    }

    public function test_all_historical_documents_superseded_allows_normal_preflight(): void
    {
        $calls = $this->mockGateway(['unexpected' => []]);

        $result = $this->runner()->run($this->executionRoot());

        $this->assertSame('HOLD_LOOKUP_OUTCOME_UNKNOWN', $result['terminal']);
        $this->assertSame(['GET'], $calls->methods);
    }

    #[DataProvider('invalidHistoricalDocumentStatuses')]
    public function test_any_non_superseded_historical_document_fails_before_http(string $status): void
    {
        KycDocument::query()->findOrFail(21)->update(['status' => $status]);

        $this->assertPreflightFailureBeforeHttp();
    }

    public static function invalidHistoricalDocumentStatuses(): array
    {
        return [
            'approved' => ['approved'],
            'rejected' => ['rejected'],
            'pending' => ['pending'],
        ];
    }

    public function test_missing_historical_document_fails_before_http(): void
    {
        KycDocument::query()->findOrFail(18)->delete();

        $this->assertPreflightFailureBeforeHttp();
    }

    public function test_payload_with_identity_documents_fails_before_http(): void
    {
        $this->payload['applicant']['documents'] = [['fileIds' => ['20000000-0000-4000-8000-000000000022']]];

        $this->assertPreflightFailureBeforeHttp();
    }

    public function test_extra_stakeholder_fails_before_http(): void
    {
        $this->payload['stakeholders']['individual'][] = $this->payload['stakeholders']['individual'][0];

        $this->assertPreflightFailureBeforeHttp();
    }

    public function test_missing_stakeholder_fails_before_http(): void
    {
        $this->payload['stakeholders']['individual'] = [];

        $this->assertPreflightFailureBeforeHttp();
    }

    public function test_integer_applicant_full_ownership_passes_guard(): void
    {
        $this->payload['applicant']['sharePercentage'] = 100;
        $this->refreshLockedPayloadSha();
        $calls = $this->mockGateway(['unexpected' => []]);

        $result = $this->runner()->run($this->executionRoot());

        $this->assertSame('HOLD_LOOKUP_OUTCOME_UNKNOWN', $result['terminal']);
        $this->assertSame(['GET'], $calls->methods);
    }

    public function test_float_applicant_full_ownership_passes_guard(): void
    {
        $this->assertIsFloat($this->payload['applicant']['sharePercentage']);
        $calls = $this->mockGateway(['unexpected' => []]);

        $result = $this->runner()->run($this->executionRoot());

        $this->assertSame('HOLD_LOOKUP_OUTCOME_UNKNOWN', $result['terminal']);
        $this->assertSame(['GET'], $calls->methods);
    }

    public function test_float_stakeholder_full_ownership_passes_guard(): void
    {
        $this->assertIsFloat($this->payload['stakeholders']['individual'][0]['sharePercentage']);
        $calls = $this->mockGateway(['unexpected' => []]);

        $result = $this->runner()->run($this->executionRoot());

        $this->assertSame('HOLD_LOOKUP_OUTCOME_UNKNOWN', $result['terminal']);
        $this->assertSame(['GET'], $calls->methods);
    }

    #[DataProvider('invalidFullOwnershipValues')]
    public function test_invalid_applicant_full_ownership_fails_before_http(mixed $value): void
    {
        $this->payload['applicant']['sharePercentage'] = $value;

        $this->assertPreflightFailureBeforeHttp();
    }

    public static function invalidFullOwnershipValues(): array
    {
        return [
            'numeric string' => ['100'],
            'numeric decimal string' => ['100.0'],
            'integer below full ownership' => [99],
            'fractional below full ownership' => [99.999],
            'fractional above full ownership' => [100.01],
            'null' => [null],
            'array' => [[100]],
            'boolean' => [true],
        ];
    }

    public function test_production_resolver_selection_other_than_exact_documents_fails_before_http(): void
    {
        KycDocument::query()->forceCreate([
            'id' => 26,
            'kyc_profile_id' => 9,
            'type' => 'proof_of_business',
            'status' => 'approved',
            'file_url' => 'private://fixture/26',
            'metadata' => [
                'nium_file_id' => '20000000-0000-4000-8000-000000000026',
                'nium_file_state' => 'AVAILABLE',
            ],
        ]);

        $this->assertPreflightFailureBeforeHttp();
    }

    public function test_locked_payload_sha_mismatch_fails_before_http(): void
    {
        $this->payload['businessName'] = 'Changed factual business';

        $this->assertPreflightFailureBeforeHttp();
    }

    #[DataProvider('invalidFactualRegisteredAddressValues')]
    public function test_wrong_or_missing_factual_registered_address_fails_before_http(string $path, mixed $value): void
    {
        if ($value === null) {
            data_forget($this->payload, $path);
        } else {
            data_set($this->payload, $path, $value);
        }
        $this->refreshLockedPayloadSha();

        $this->assertPreflightFailureBeforeHttp();
    }

    public static function invalidFactualRegisteredAddressValues(): array
    {
        return [
            'missing state' => ['addresses.registeredAddress.state', null],
            'wrong state' => ['addresses.registeredAddress.state', 'HONG KONG'],
            'wrong city' => ['addresses.registeredAddress.city', 'KOWLOON'],
            'wrong country' => ['addresses.registeredAddress.country', 'SG'],
        ];
    }

    public function test_correct_factual_registered_address_passes_preflight(): void
    {
        $calls = $this->mockGateway(['unexpected' => []]);

        $result = $this->runner()->run($this->executionRoot());

        $this->assertSame('HOLD_LOOKUP_OUTCOME_UNKNOWN', $result['terminal']);
        $this->assertSame(['GET'], $calls->methods);
    }

    public function test_locked_payload_sha_uses_unescaped_slashes(): void
    {
        $unescaped = hash('sha256', json_encode($this->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $escaped = hash('sha256', json_encode($this->payload, JSON_THROW_ON_ERROR));

        $this->assertSame($unescaped, $this->lockedPayloadSha);
        $this->assertNotSame($escaped, $this->lockedPayloadSha);

        $calls = $this->mockGateway(['unexpected' => []]);
        $result = $this->runner()->run($this->executionRoot());

        $this->assertSame('HOLD_LOOKUP_OUTCOME_UNKNOWN', $result['terminal']);
        $this->assertSame(['GET'], $calls->methods);
    }

    public function test_production_hk_validator_failure_blocks_all_http(): void
    {
        $this->mock(NiumService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('get');
            $mock->shouldNotReceive('post');
        });

        try {
            $this->runner(validatePayloadWithHkValidator: true)->run($this->executionRoot());
            $this->fail('Expected the production HK validator to reject the incomplete isolated payload.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Nium HK Corporate Full requires', $exception->getMessage());
        }

        $this->assertSame(95, ApiRequestLog::query()->count());
        $this->assertSame(7, ApiRequestLog::query()->where('operation', 'customer_create')->where('request_method', 'POST')->count());
    }

    #[DataProvider('invalidPreviousSubmissionStates')]
    public function test_generation_seven_state_mismatch_fails_before_http(string $path, mixed $value): void
    {
        $account = UserProviderAccount::query()->findOrFail(7);

        if (str_starts_with($path, 'metadata.')) {
            $metadata = (array) $account->metadata;
            data_set($metadata, substr($path, 9), $value);
            $account->forceFill(['metadata' => $metadata])->save();
        } else {
            $account->forceFill([$path => $value])->save();
        }

        $this->assertPreflightFailureBeforeHttp();
    }

    public static function invalidPreviousSubmissionStates(): array
    {
        return [
            'marker' => ['metadata.customer_v5_submission_marker', 'wrong-marker'],
            'state' => ['metadata.customer_v5_submission_state', 'customer_create_unknown'],
            'payload fingerprint' => ['metadata.customer_v5_payload_fingerprint', str_repeat('f', 64)],
            'previous marker' => ['metadata.customer_v5_previous_submission.submission_marker', 'wrong-marker'],
            'reconciliation status' => ['reconciliation_status', 'pending'],
            'reconciliation error' => ['reconciliation_error', 'customer_create_unknown'],
            'resubmission flag' => ['metadata.is_resubmission_allowed', true],
        ];
    }

    public function test_generation_seven_post_count_mismatch_fails_before_http(): void
    {
        ApiRequestLog::query()->where('operation', 'customer_create')->latest('id')->firstOrFail()->delete();
        $this->logRequest('GET', 'safe_diagnostic', 200);

        $this->assertPreflightFailureBeforeHttp(6);
    }

    public function test_generation_seven_last_scoped_post_must_be_a_definite_rejection(): void
    {
        ApiRequestLog::query()->where('operation', 'customer_create')->latest('id')->firstOrFail()->update([
            'response_status' => 200,
            'is_success' => true,
        ]);

        $this->assertPreflightFailureBeforeHttp();
    }

    public function test_latest_scoped_customer_post_must_remain_id_93(): void
    {
        ApiRequestLog::query()->findOrFail(93)->delete();
        $this->logRequest('POST', 'customer_create', 400, ['error_field_fingerprint' => '5a45f7beaf2d0ce6']);

        $this->assertPreflightFailureBeforeHttp();
    }

    public function test_generation_seven_last_scoped_post_must_prove_registered_state_fingerprint(): void
    {
        ApiRequestLog::query()->where('operation', 'customer_create')->latest('id')->firstOrFail()->update([
            'response_body' => [
                'error_field_fingerprint' => 'wrong-fingerprint',
            ],
        ]);

        $this->assertPreflightFailureBeforeHttp();
    }

    public function test_generation_seven_last_scoped_post_must_prove_response_received_transport(): void
    {
        ApiRequestLog::query()->where('operation', 'customer_create')->latest('id')->firstOrFail()->update([
            'transport_outcome' => 'connection_failed',
        ]);

        $this->assertPreflightFailureBeforeHttp();
    }

    public function test_diagnostic_logs_after_generation_seven_scoped_post_do_not_block_preflight(): void
    {
        $lastPost = ApiRequestLog::query()->where('operation', 'customer_create')->latest('id')->firstOrFail();
        $this->assertSame(93, $lastPost->id);
        $this->assertSame(95, ApiRequestLog::query()->max('id'));
        $calls = $this->mockGateway(['unexpected' => []]);

        $result = $this->runner()->run($this->executionRoot());

        $this->assertSame('HOLD_LOOKUP_OUTCOME_UNKNOWN', $result['terminal']);
        $this->assertSame(['GET'], $calls->methods);
    }

    public function test_generation_seven_history_must_contain_exactly_generations_four_five_and_six(): void
    {
        $account = UserProviderAccount::query()->findOrFail(7);
        $metadata = (array) $account->metadata;
        $metadata['customer_v5_submission_history'][] = $metadata['customer_v5_submission_history'][1];
        $account->forceFill(['metadata' => $metadata])->save();

        $this->assertPreflightFailureBeforeHttp();
    }

    public function test_generation_eight_constants_match_factual_locks(): void
    {
        $this->assertSame('3185070c589266eea2cbe4eda08267e72f993cb0', NiumHkCustomerV5OneShotRunner::EXPECTED_HEAD);
        $this->assertSame('55d97d8fa869c9ce7f21fa96e64e8dfe89e5cd287ded8d4d756c76d989c2c0d6', NiumHkCustomerV5OneShotRunner::LOCKED_PAYLOAD_SHA256);
    }

    public function test_generation_eight_marker_is_not_written_when_lookup_is_ambiguous(): void
    {
        $calls = $this->mockGateway(['unexpected' => []]);

        $this->runner()->run($this->executionRoot());

        $this->assertSame(['GET'], $calls->methods);
        $this->assertSame(
            'nium-v5-hk-customer-create-7-factual-declaration-timestamp-v3',
            UserProviderAccount::query()->findOrFail(7)->metadata['customer_v5_submission_marker'],
        );
    }

    public function test_protected_account_remains_byte_identical(): void
    {
        $before = UserProviderAccount::query()->findOrFail(4)->getRawOriginal();
        $this->mockGateway(['customers' => []], ['customerHashId' => 'customer-safe-test']);

        $this->runner()->run($this->executionRoot());

        $this->assertSame($before, UserProviderAccount::query()->findOrFail(4)->getRawOriginal());
    }

    #[DataProvider('rejectedStatuses')]
    public function test_definite_4xx_and_5xx_never_retry(int $status): void
    {
        $calls = $this->mockGateway(['customers' => []], ['errorCode' => 'safe_error'], $status);

        $result = $this->runner()->run($this->executionRoot());

        $this->assertSame('STOP_CREATE_REJECTED_NO_RETRY', $result['terminal']);
        $this->assertSame(['GET', 'POST'], $calls->methods);
        $this->assertFalse(UserProviderAccount::query()->findOrFail(7)->metadata['is_resubmission_allowed']);
    }

    public static function rejectedStatuses(): array
    {
        return ['4xx' => [400], '5xx' => [500]];
    }

    public function test_ambiguous_create_connection_outcome_never_retries(): void
    {
        $calls = new class
        {
            public array $methods = [];
        };
        $this->mock(NiumService::class, function (MockInterface $mock) use ($calls): void {
            $mock->shouldReceive('clientId')->andReturn('safe-test-client');
            $mock->shouldReceive('path')->andReturn('/api/v5/client/safe/customers');
            $mock->shouldReceive('get')->once()->andReturnUsing(function () use ($calls): Response {
                $calls->methods[] = 'GET';
                $this->logRequest('GET', 'customer_list', 200);

                return new Response(new \GuzzleHttp\Psr7\Response(200, [], '{"customers":[]}'));
            });
            $mock->shouldReceive('post')->once()->andReturnUsing(function () use ($calls): never {
                $calls->methods[] = 'POST';
                throw new ConnectionException('Ambiguous connection outcome.');
            });
        });

        $result = $this->runner()->run($this->executionRoot());

        $this->assertSame('STOP_CREATE_OUTCOME_UNKNOWN_NO_RETRY', $result['terminal']);
        $this->assertSame(['GET', 'POST'], $calls->methods);
        $this->assertFalse(UserProviderAccount::query()->findOrFail(7)->metadata['is_resubmission_allowed']);
    }

    public function test_execution_marker_and_durable_claim_both_block_a_second_post(): void
    {
        $root = $this->executionRoot();
        $calls = $this->mockGateway(['customers' => []], ['errorCode' => 'safe_error'], 400);
        $this->runner()->run($root);

        try {
            $this->runner()->run($root);
            $this->fail('Expected the external marker to block a second invocation.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('EXECUTION_STARTED already exists', $exception->getMessage());
        }

        $newRoot = $this->executionRoot();

        try {
            $this->runner()->run($newRoot);
            $this->fail('Expected the durable database claim to block a new execution root.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('locked generation #7 rejected state', $exception->getMessage());
        }

        $this->assertSame(['GET', 'POST'], $calls->methods);
    }

    private function runner(bool $validatePayloadWithHkValidator = false): NiumHkCustomerV5OneShotRunner
    {
        return new NiumHkCustomerV5OneShotRunner(
            app(NiumService::class),
            app(NiumCustomerPayloadFactory::class),
            app(NiumCustomerDocumentResolver::class),
            app(NiumProviderAccountStateService::class),
            app(NiumHkCorporateV5Validator::class),
            $this->lockedPayloadSha,
            $validatePayloadWithHkValidator,
        );
    }

    private function mockGateway(array $lookupBody, ?array $createBody = null, int $createStatus = 201): object
    {
        $calls = new class
        {
            /** @var list<string> */
            public array $methods = [];
        };
        $this->mock(NiumService::class, function (MockInterface $mock) use ($calls, $lookupBody, $createBody, $createStatus): void {
            $mock->shouldReceive('clientId')->andReturn('safe-test-client');
            $mock->shouldReceive('path')->andReturn('/api/v5/client/safe/customers');
            $mock->shouldReceive('get')->once()->andReturnUsing(function () use ($calls, $lookupBody): Response {
                $calls->methods[] = 'GET';
                $this->logRequest('GET', 'customer_list', 200);

                return new Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode($lookupBody, JSON_THROW_ON_ERROR)));
            });

            if ($createBody === null) {
                $mock->shouldNotReceive('post');
            } else {
                $mock->shouldReceive('post')->once()->andReturnUsing(function () use ($calls, $createBody, $createStatus): Response {
                    $calls->methods[] = 'POST';
                    $this->logRequest('POST', 'customer_create', $createStatus);

                    return new Response(new \GuzzleHttp\Psr7\Response($createStatus, [], json_encode($createBody, JSON_THROW_ON_ERROR)));
                });
            }
        });

        return $calls;
    }

    private function seedCheckpoint(): void
    {
        $provider = IntegrationProvider::query()->forceCreate(['id' => 1, 'code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        User::factory()->create(['id' => 4]);
        $user = User::factory()->create(['id' => 9, 'email' => 'applicant@example.test']);
        KycProfile::query()->forceCreate([
            'id' => 9,
            'user_id' => 9,
            'status' => 'approved',
            'applicant_type' => 'business',
            'legal_name' => 'HK Fixture Limited',
            'address_line1' => '1 Test Road',
            'city' => 'MONGKOK, KOWLOON',
            'state' => 'KOWLOON',
            'registered_country_code' => 'HK',
            'country_code' => 'HK',
            'metadata' => [
                'nium_region' => 'HK',
                'nium_v5_fields' => [
                    'deviceDetails' => [
                        'ipCountryCode' => 'VN',
                        'deviceInfo' => 'Factual browser',
                        'ipAddress' => '192.0.2.9',
                        'sessionId' => '30000000-0000-4000-8000-000000000009',
                    ],
                ],
            ],
        ]);
        KycRelatedPerson::query()->forceCreate([
            'id' => 13,
            'kyc_profile_id' => 9,
            'relationship_type' => 'applicant',
            'status' => 'approved',
            'legal_name' => 'Fixture Applicant',
        ]);
        KycRelatedPerson::query()->forceCreate([
            'id' => 14,
            'kyc_profile_id' => 9,
            'relationship_type' => 'beneficial_owner',
            'status' => 'approved',
            'legal_name' => 'Fixture Stakeholder',
        ]);
        UserProviderAccount::query()->forceCreate(['id' => 4, 'user_id' => 4, 'provider_id' => $provider->id]);
        UserProviderAccount::query()->forceCreate([
            'id' => 7,
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'external_reference' => 'origin-wallet-user-9',
            'reconciliation_status' => 'failed',
            'reconciliation_error' => 'customer_create_rejected',
            'metadata' => [
                'customer_v5_submission_marker' => 'nium-v5-hk-customer-create-7-factual-declaration-timestamp-v3',
                'customer_v5_submission_state' => 'customer_create_rejected',
                'customer_v5_payload_fingerprint' => 'ec95fad0d7560c528173cbaf9317bb80ea843faef39dbc6facb33c915a40c571',
                'is_resubmission_allowed' => false,
                'customer_v5_previous_submission' => [
                    'submission_marker' => 'nium-v5-hk-customer-create-6-factual-business-type-v2',
                    'submission_state' => 'customer_create_rejected',
                    'payload_fingerprint' => '767d875a5fbcd468c186bf0f045349f36d019694a77b18627ec4a3a468732ad9',
                    'reconciliation_status' => 'failed',
                    'reconciliation_error' => 'customer_create_rejected',
                    'is_resubmission_allowed' => false,
                ],
                'customer_v5_submission_history' => [
                    [
                        'submission_marker' => 'nium-v5-hk-customer-create-4',
                        'submission_state' => 'customer_create_rejected',
                        'payload_fingerprint' => str_repeat('a', 64),
                        'reconciliation_status' => 'failed',
                        'reconciliation_error' => 'customer_create_rejected',
                        'is_resubmission_allowed' => false,
                    ],
                    [
                        'submission_marker' => 'nium-v5-hk-customer-create-5-factual-v1',
                        'submission_state' => 'customer_create_rejected',
                        'payload_fingerprint' => 'dfb4dd25efd1e264054b175600c3b04a26c62531b14ae8b86d879a7d36364769',
                        'reconciliation_status' => 'failed',
                        'reconciliation_error' => 'customer_create_rejected',
                        'is_resubmission_allowed' => false,
                    ],
                    [
                        'submission_marker' => 'nium-v5-hk-customer-create-6-factual-business-type-v2',
                        'submission_state' => 'customer_create_rejected',
                        'payload_fingerprint' => '767d875a5fbcd468c186bf0f045349f36d019694a77b18627ec4a3a468732ad9',
                        'reconciliation_status' => 'failed',
                        'reconciliation_error' => 'customer_create_rejected',
                        'is_resubmission_allowed' => false,
                    ],
                ],
            ],
        ]);
        foreach ([18, 19, 20, 21, 22, 23] as $id) {
            KycDocument::query()->forceCreate([
                'id' => $id,
                'kyc_profile_id' => 9,
                'kyc_related_person_id' => in_array($id, [19, 22], true) ? 13 : (in_array($id, [20, 23], true) ? 14 : null),
                'type' => in_array($id, [18, 21], true) ? 'business_registration' : 'passport_front',
                'status' => 'superseded',
                'file_url' => 'private://fixture/'.$id,
                'metadata' => [
                    'nium_file_id' => sprintf('20000000-0000-4000-8000-%012d', $id),
                    'nium_file_state' => 'AVAILABLE',
                ],
            ]);
        }

        foreach ([24 => 'nar1', 25 => 'business_registration_doc'] as $id => $type) {
            KycDocument::query()->forceCreate([
                'id' => $id,
                'kyc_profile_id' => 9,
                'type' => $type,
                'status' => 'approved',
                'file_url' => 'private://fixture/'.$id,
                'metadata' => [
                    'nium_file_id' => sprintf('20000000-0000-4000-8000-%012d', $id),
                    'nium_file_state' => 'AVAILABLE',
                ],
            ]);
        }

        for ($index = 0; $index < 86; $index++) {
            $this->logRequest('GET', 'safe_diagnostic', 200);
        }
        for ($index = 0; $index < 6; $index++) {
            $this->logRequest('POST', 'customer_create', 400, ['error_field_fingerprint' => 'cb538016d80b3271']);
        }
        $this->logRequest('POST', 'customer_create', 400, ['error_field_fingerprint' => '5a45f7beaf2d0ce6']);
        $this->logRequest('GET', 'fetch_corporate_constants', 200);
        $this->logRequest('GET', 'public_corporate_details', 200);

        $this->payload = [
            'type' => 'corporate',
            'region' => 'HK',
            'kycType' => 'full',
            'businessType' => 'private_company',
            'registeredCountry' => 'HK',
            'businessName' => 'Factual HK Company',
            'website' => 'https://hongkongmachininggroup.com',
            'applicantDeclarationTimeStamp' => '2026-07-23 05:00:00',
            'addresses' => [
                'registeredAddress' => [
                    'addressLine1' => 'FLAT/ROOM 1618B, 16/F, PIONEER CENTRE',
                    'addressLine2' => '750 NATHAN ROAD',
                    'city' => 'MONGKOK, KOWLOON',
                    'state' => 'KOWLOON',
                    'country' => 'HK',
                ],
            ],
            'deviceDetails' => [
                'ipCountryCode' => 'VN',
                'deviceInfo' => 'Factual browser',
                'ipAddress' => '192.0.2.9',
                'sessionId' => '30000000-0000-4000-8000-000000000009',
            ],
            'applicant' => [
                'email' => 'applicant@example.test',
                'sharePercentage' => 100.0,
                'positions' => [
                    ['title' => 'representative'],
                    ['title' => 'director'],
                    ['title' => 'ubo'],
                    ['title' => 'shareholder'],
                ],
            ],
            'stakeholders' => ['individual' => [[
                'sharePercentage' => 100.0,
                'positions' => [
                    ['title' => 'director'],
                    ['title' => 'ubo'],
                    ['title' => 'shareholder'],
                ],
            ]]],
            'documents' => [
                ['type' => 'nar1', 'fileIds' => ['20000000-0000-4000-8000-000000000024']],
                ['type' => 'business_registration_doc', 'fileIds' => ['20000000-0000-4000-8000-000000000025']],
            ],
        ];
        $this->lockedPayloadSha = hash('sha256', json_encode($this->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $this->mock(NiumCustomerPayloadFactory::class, fn (MockInterface $mock) => $mock->shouldReceive('build')->andReturnUsing(fn (): array => $this->payload));
        $this->mock(NiumProviderAccountStateService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('applyAuthenticatedState')->andReturnUsing(function (UserProviderAccount $account, array $payload): UserProviderAccount {
                $account->forceFill([
                    'external_customer_id' => $payload['customerHashId'],
                    'external_account_id' => $payload['walletHashId'] ?? null,
                ])->save();

                return $account->fresh();
            });
        });
    }

    private function logRequest(string $method, string $operation, int $status, ?array $responseBody = null): void
    {
        ApiRequestLog::query()->create([
            'provider_id' => 1,
            'user_id' => 9,
            'operation' => $operation,
            'request_method' => $method,
            'request_url' => '/safe',
            'response_status' => $status,
            'response_body' => $responseBody,
            'transport_outcome' => 'response_received',
            'is_success' => $status >= 200 && $status < 300,
        ]);
    }

    private function executionRoot(): string
    {
        $root = sys_get_temp_dir().'/nium-hk-customer-v5-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $this->executionRoots[] = $root;

        return $root;
    }

    private function assertPreflightFailureBeforeHttp(int $expectedCustomerPosts = 7): void
    {
        $this->mock(NiumService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('get');
            $mock->shouldNotReceive('post');
        });

        try {
            $this->runner()->run($this->executionRoot());
            $this->fail('Expected the role-binding preflight to fail before provider HTTP.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(95, ApiRequestLog::query()->count());
        $this->assertSame($expectedCustomerPosts, ApiRequestLog::query()->where('operation', 'customer_create')->where('request_method', 'POST')->count());
    }

    private function refreshLockedPayloadSha(): void
    {
        $this->lockedPayloadSha = hash('sha256', json_encode($this->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

}
