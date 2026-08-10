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
use App\Services\Nium\NiumHkCustomerV5OneShotRunner;
use App\Services\Nium\NiumProviderAccountStateService;
use App\Services\Nium\NiumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertSame(3, $result['fixture_customer_post_count']);
        $this->assertSame(66, $result['api_request_log_count']);
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
        $this->assertSame(3, $result['fixture_customer_post_count']);
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
        $this->assertSame(3, $result['fixture_customer_post_count']);
    }

    public function test_unknown_lookup_never_posts(): void
    {
        $calls = $this->mockGateway(['unexpected' => []]);

        $result = $this->runner()->run($this->executionRoot());

        $this->assertSame('HOLD_LOOKUP_OUTCOME_UNKNOWN', $result['terminal']);
        $this->assertSame(['GET'], $calls->methods);
        $this->assertSame(3, $result['fixture_customer_post_count']);
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
        $this->assertSame(4, $result['fixture_customer_post_count']);
        $this->assertSame(67, $result['api_request_log_count']);
        $this->assertNotNull(UserProviderAccount::query()->findOrFail(7)->external_customer_id);
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
        $this->assertSame(4, $result['fixture_customer_post_count']);
    }

    public function test_successful_create_without_customer_identifier_holds_without_replay(): void
    {
        $calls = $this->mockGateway(['customers' => []], [
            'walletHashId' => 'wallet-safe-test',
        ]);

        $result = $this->runner()->run($this->executionRoot());

        $this->assertSame('HOLD_RESPONSE_REVIEW', $result['terminal']);
        $this->assertSame(['GET', 'POST'], $calls->methods);
        $this->assertSame(4, $result['fixture_customer_post_count']);
        $this->assertFalse(UserProviderAccount::query()->findOrFail(7)->metadata['is_resubmission_allowed']);
    }

    #[DataProvider('unsafeLookupResponses')]
    public function test_malformed_multiple_and_mismatched_lookup_responses_never_post(array $lookupBody): void
    {
        $calls = $this->mockGateway($lookupBody);

        $result = $this->runner()->run($this->executionRoot());

        $this->assertSame('HOLD_LOOKUP_OUTCOME_UNKNOWN', $result['terminal']);
        $this->assertSame(['GET'], $calls->methods);
        $this->assertSame(3, $result['fixture_customer_post_count']);
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

    public function test_invalid_payload_device_session_fails_before_http_without_identity_row(): void
    {
        data_set($this->payload, 'deviceDetails.sessionId', 'not-a-uuid');

        $this->assertPreflightFailureBeforeHttp();
        $this->assertDatabaseCount('identity_verification_sessions', 0);
    }

    public function test_hk_payload_may_omit_optional_device_details_without_identity_row(): void
    {
        unset($this->payload['deviceDetails']);
        $this->assertDatabaseCount('identity_verification_sessions', 0);
        $calls = $this->mockGateway(['unexpected' => []]);

        $result = $this->runner()->run($this->executionRoot());

        $this->assertSame('HOLD_LOOKUP_OUTCOME_UNKNOWN', $result['terminal']);
        $this->assertSame(['GET'], $calls->methods);
        $this->assertDatabaseCount('identity_verification_sessions', 0);
    }

    public function test_non_hk_device_country_fails_before_http(): void
    {
        data_set($this->payload, 'deviceDetails.ipCountryCode', 'SG');

        $this->assertPreflightFailureBeforeHttp();
    }

    #[DataProvider('invalidDocumentBindings')]
    public function test_invalid_document_relationships_and_logical_roles_fail_before_http(string $case): void
    {
        match ($case) {
            'applicant document attached to stakeholder' => KycDocument::query()->findOrFail(22)->forceFill(['kyc_related_person_id' => 14])->save(),
            'stakeholder document attached to applicant' => KycDocument::query()->findOrFail(23)->forceFill(['kyc_related_person_id' => 13])->save(),
            'company document attached to person' => KycDocument::query()->findOrFail(21)->forceFill(['kyc_related_person_id' => 13])->save(),
            'logical role mismatch' => $this->replaceDocumentMetadata(22, ['logical_role' => 'beneficial_owner_stakeholder_identity']),
        };

        $this->assertPreflightFailureBeforeHttp();
    }

    public static function invalidDocumentBindings(): array
    {
        return [
            'doc22 stakeholder' => ['applicant document attached to stakeholder'],
            'doc23 applicant' => ['stakeholder document attached to applicant'],
            'doc21 person' => ['company document attached to person'],
            'role mismatch' => ['logical role mismatch'],
        ];
    }

    #[DataProvider('swappedPayloadRoles')]
    public function test_swapped_payload_file_ids_fail_before_http(string $leftPath, string $rightPath): void
    {
        $left = data_get($this->payload, $leftPath);
        $right = data_get($this->payload, $rightPath);
        data_set($this->payload, $leftPath, $right);
        data_set($this->payload, $rightPath, $left);

        $this->assertPreflightFailureBeforeHttp();
    }

    public static function swappedPayloadRoles(): array
    {
        return [
            'company and applicant' => ['documents.0.fileIds', 'applicant.documents.0.fileIds'],
            'applicant and stakeholder' => ['applicant.documents.0.fileIds', 'stakeholders.individual.0.documents.0.fileIds'],
            'company and stakeholder' => ['documents.0.fileIds', 'stakeholders.individual.0.documents.0.fileIds'],
        ];
    }

    public function test_extra_stakeholder_fails_before_http(): void
    {
        KycRelatedPerson::query()->forceCreate([
            'id' => 15,
            'kyc_profile_id' => 9,
            'relationship_type' => 'beneficial_owner',
            'status' => 'approved',
            'legal_name' => 'Extra Stakeholder',
        ]);

        $this->assertPreflightFailureBeforeHttp();
    }

    public function test_missing_stakeholder_fails_before_http(): void
    {
        KycRelatedPerson::query()->findOrFail(14)->delete();

        $this->assertPreflightFailureBeforeHttp();
    }

    public function test_production_resolver_selection_other_than_exact_documents_fails_before_http(): void
    {
        KycDocument::query()->forceCreate([
            'id' => 24,
            'kyc_profile_id' => 9,
            'kyc_related_person_id' => 14,
            'type' => 'passport_front',
            'status' => 'approved',
            'file_url' => 'private://fixture/24',
            'metadata' => [
                'logical_role' => 'beneficial_owner_stakeholder_identity',
                'nium_file_id' => '20000000-0000-4000-8000-000000000024',
                'nium_file_state' => 'AVAILABLE',
            ],
        ]);

        $this->assertPreflightFailureBeforeHttp();
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
            $this->assertStringContainsString('already been claimed', $exception->getMessage());
        }

        $this->assertSame(['GET', 'POST'], $calls->methods);
    }

    private function runner(): NiumHkCustomerV5OneShotRunner
    {
        return app(NiumHkCustomerV5OneShotRunner::class);
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
            'city' => 'Hong Kong',
            'registered_country_code' => 'HK',
            'country_code' => 'HK',
            'metadata' => ['nium_region' => 'HK'],
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
        ]);
        foreach ([21, 22, 23] as $index => $id) {
            KycDocument::query()->forceCreate([
                'id' => $id,
                'kyc_profile_id' => 9,
                'kyc_related_person_id' => match ($id) {
                    22 => 13,
                    23 => 14,
                    default => null,
                },
                'type' => $index === 0 ? 'business_registration' : 'passport_front',
                'status' => 'approved',
                'file_url' => 'private://fixture/'.$id,
                'metadata' => [
                    'logical_role' => match ($id) {
                        21 => 'corporate_registration',
                        22 => 'applicant_authorized_person_identity',
                        23 => 'beneficial_owner_stakeholder_identity',
                    },
                    'nium_file_id' => sprintf('20000000-0000-4000-8000-%012d', $id),
                    'nium_file_state' => 'AVAILABLE',
                ],
            ]);
        }

        for ($index = 0; $index < 65; $index++) {
            $this->logRequest($index < 3 ? 'POST' : 'GET', $index < 3 ? 'customer_create' : 'safe_diagnostic', 200);
        }

        $this->payload = [
            'type' => 'corporate',
            'region' => 'HK',
            'kycType' => 'full',
            'registeredCountry' => 'HK',
            'deviceDetails' => [
                'ipCountryCode' => 'HK',
                'deviceInfo' => 'Fixture browser',
                'ipAddress' => '192.0.2.9',
                'sessionId' => '30000000-0000-4000-8000-000000000009',
            ],
            'applicant' => ['email' => 'applicant@example.test', 'documents' => [['fileIds' => ['20000000-0000-4000-8000-000000000022']]]],
            'stakeholders' => ['individual' => [['documents' => [['fileIds' => ['20000000-0000-4000-8000-000000000023']]]]]],
            'documents' => [['fileIds' => ['20000000-0000-4000-8000-000000000021']]],
        ];
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

    private function logRequest(string $method, string $operation, int $status): void
    {
        ApiRequestLog::query()->create([
            'provider_id' => 1,
            'user_id' => 9,
            'operation' => $operation,
            'request_method' => $method,
            'request_url' => '/safe',
            'response_status' => $status,
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

    private function assertPreflightFailureBeforeHttp(): void
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

        $this->assertSame(65, ApiRequestLog::query()->count());
        $this->assertSame(3, ApiRequestLog::query()->where('operation', 'customer_create')->where('request_method', 'POST')->count());
    }

    private function replaceDocumentMetadata(int $documentId, array $changes): void
    {
        $document = KycDocument::query()->findOrFail($documentId);
        $document->forceFill(['metadata' => [...(array) $document->metadata, ...$changes]])->save();
    }
}
