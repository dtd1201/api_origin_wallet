<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\KycProfile;
use App\Models\KycRelatedPerson;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use App\Services\Nium\NiumHkSubmitKycOneShotRunner;
use App\Services\Nium\NiumHkSubmitKycPayloadFactory;
use App\Services\Nium\NiumHkSubmitKycValidator;
use App\Services\Nium\NiumService;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class NiumHkSubmitKycOneShotRunnerTest extends TestCase
{
    use RefreshDatabase;

    private const PLACEHOLDER_IDENTITY = 'TEST-PASSPORT-ONLY';

    private const APPLICANT_REFERENCE = 'c620e0e9-ab0a-43bd-aa10-44f573db723a';

    private const STAKEHOLDER_REFERENCE = '7609d9d1-9d37-4e08-9197-602d792f7a2e';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.nium.client_id' => 'safe-test-client',
            'services.nium.customer_submit_kyc_endpoint' => '/api/v5/client/{clientHashId}/customer/{customerHashId}/submitKyc',
        ]);
        $this->seedCheckpoint();
    }

    #[DataProvider('targets')]
    public function test_payload_binds_each_entity_and_uses_biometric_passport_without_files(
        string $target,
        int $personId,
        string $referenceId,
    ): void {
        $payload = app(NiumHkSubmitKycPayloadFactory::class)->build(
            KycRelatedPerson::query()->findOrFail($personId),
            $target,
            $referenceId,
        );

        $this->assertSame('HK', $payload['region']);
        $this->assertSame($target, $payload['entityType']);
        $this->assertSame($referenceId, $payload['entityReferenceId']);
        $this->assertFalse($payload['isResident']);
        $this->assertSame('biometric_kyc', $payload['kycMode']);
        $this->assertSame('passport', $payload['proofOfIdentityDocument'][0]['type']);
        $this->assertSame(self::PLACEHOLDER_IDENTITY, $payload['proofOfIdentityDocument'][0]['identificationNumber']);
        $this->assertSame('VN', $payload['proofOfIdentityDocument'][0]['issuanceCountry']);
        $this->assertSame('2099-12-31', $payload['proofOfIdentityDocument'][0]['expiryDate']);
        $this->assertArrayNotHasKey('fileIds', $payload['proofOfIdentityDocument'][0]);
        $this->assertArrayNotHasKey('proofOfAddressDocument', $payload);
        $this->assertDatabaseCount('kyc_documents', 0);
    }

    public function test_valid_applicant_metadata_passes_preflight(): void
    {
        $calls = $this->mockInitiated(NiumHkSubmitKycOneShotRunner::APPLICANT, self::APPLICANT_REFERENCE);
        $result = $this->runner()->run(NiumHkSubmitKycOneShotRunner::APPLICANT);

        $this->assertSame('PASS_KYC_INITIATED', $result['terminal']);
        $this->assertSame(1, $result['submit_kyc_post_count']);
        $this->assertSame(['POST'], $calls->methods);
        $this->assertDatabaseCount('api_request_logs', 1);
        $this->assertSame(0, ApiRequestLog::query()->where('operation', 'customer_create')->count());
    }

    public function test_valid_stakeholder_metadata_passes_preflight(): void
    {
        $calls = $this->mockInitiated(NiumHkSubmitKycOneShotRunner::STAKEHOLDER, self::STAKEHOLDER_REFERENCE);
        $result = $this->runner()->run(NiumHkSubmitKycOneShotRunner::STAKEHOLDER);

        $this->assertSame('PASS_KYC_INITIATED', $result['terminal']);
        $this->assertSame(1, $result['submit_kyc_post_count']);
        $this->assertSame(['POST'], $calls->methods);
    }

    public function test_applicant_prior_post_does_not_block_stakeholder_one_shot(): void
    {
        $this->logSubmit(self::APPLICANT_REFERENCE, 200);
        $calls = $this->mockInitiated(NiumHkSubmitKycOneShotRunner::STAKEHOLDER, self::STAKEHOLDER_REFERENCE);

        $result = $this->runner()->run(NiumHkSubmitKycOneShotRunner::STAKEHOLDER);

        $this->assertSame('PASS_KYC_INITIATED', $result['terminal']);
        $this->assertSame(1, $result['submit_kyc_post_count']);
        $this->assertSame(['POST'], $calls->methods);
        $this->assertDatabaseCount('api_request_logs', 2);
    }

    #[DataProvider('invalidAccountStates')]
    public function test_awaiting_kyc_account_prerequisites_fail_before_http(string $field, mixed $value): void
    {
        UserProviderAccount::query()->findOrFail(7)->forceFill([$field => $value])->save();
        $this->assertFailsBeforeHttp(NiumHkSubmitKycOneShotRunner::APPLICANT);
    }

    public function test_stale_projected_state_cannot_override_correct_raw_webhook_state(): void
    {
        $account = UserProviderAccount::query()->findOrFail(7);
        $metadata = (array) $account->metadata;
        $key = 'ref_'.substr(hash('sha256', self::APPLICANT_REFERENCE), 0, 16);
        $metadata['nium_entity_kyc_states'][$key]['kyc_status'] = 'submitted';
        $account->forceFill([
            'provider_status' => 'clear',
            'provider_sub_status' => 'under_review',
            'metadata' => $metadata,
        ])->save();
        $this->mockInitiated(NiumHkSubmitKycOneShotRunner::APPLICANT, self::APPLICANT_REFERENCE);

        $result = $this->runner()->run(NiumHkSubmitKycOneShotRunner::APPLICANT);

        $this->assertSame('PASS_KYC_INITIATED', $result['terminal']);
    }

    public function test_wrong_latest_webhook_reference_fails_before_http(): void
    {
        $this->seedEntityWebhook(13, 'wrong-reference', 'applicant');

        $this->assertFailsBeforeHttp(NiumHkSubmitKycOneShotRunner::APPLICANT);
    }

    public function test_wrong_latest_webhook_entity_type_fails_before_http(): void
    {
        $this->seedEntityWebhook(13, self::APPLICANT_REFERENCE, 'individual_stakeholder');

        $this->assertFailsBeforeHttp(NiumHkSubmitKycOneShotRunner::APPLICANT);
    }

    public function test_placeholder_status_is_not_accepted_as_current_evidence(): void
    {
        WebhookEvent::query()->where('event_type', 'CUSTOMER_ENTITY_KYC_STATUS')->delete();
        $this->seedEntityWebhook(13, self::APPLICANT_REFERENCE, 'applicant', '${kycStatus}');

        $this->assertFailsBeforeHttp(NiumHkSubmitKycOneShotRunner::APPLICANT);
    }

    public function test_wrong_target_is_rejected_before_http(): void
    {
        $this->assertFailsBeforeHttp('wrong_entity');
    }

    #[DataProvider('invalidIdentitySources')]
    public function test_invalid_identity_source_fails_before_http(mixed $identity): void
    {
        $person = KycRelatedPerson::query()->findOrFail(13);
        $person->forceFill(['metadata' => ['nium_biometric_identity' => $identity]])->save();

        $this->assertFailsBeforeHttp(NiumHkSubmitKycOneShotRunner::APPLICANT);
    }

    public function test_missing_identity_source_fails_before_http(): void
    {
        KycRelatedPerson::query()->findOrFail(13)->forceFill(['metadata' => []])->save();

        $this->assertFailsBeforeHttp(NiumHkSubmitKycOneShotRunner::APPLICANT);
    }

    #[DataProvider('invalidPayloadIdentityValues')]
    public function test_validator_rejects_non_string_or_malformed_identity_values(array $changes): void
    {
        $payload = $this->validPayload();
        $payload['proofOfIdentityDocument'][0] = [
            ...$payload['proofOfIdentityDocument'][0],
            ...$changes,
        ];

        $this->expectException(RuntimeException::class);
        app(NiumHkSubmitKycValidator::class)->assert($payload);
    }

    #[DataProvider('rejectedStatuses')]
    public function test_definite_rejection_has_no_retry(int $status): void
    {
        $calls = $this->mockResponse(['message' => 'safe rejection'], $status);
        $result = $this->runner()->run(NiumHkSubmitKycOneShotRunner::APPLICANT);

        $this->assertSame('STOP_KYC_REJECTED_NO_RETRY', $result['terminal']);
        $this->assertSame(['POST'], $calls->methods);
    }

    public function test_ambiguous_transport_has_no_retry(): void
    {
        $calls = new class { public array $methods = []; };
        $this->mock(NiumService::class, function (MockInterface $mock) use ($calls): void {
            $mock->shouldReceive('clientId')->andReturn('safe-test-client');
            $mock->shouldReceive('path')->andReturn('/safe/submitKyc');
            $mock->shouldReceive('post')->once()->andReturnUsing(function () use ($calls): never {
                $calls->methods[] = 'POST';
                throw new ConnectionException('ambiguous');
            });
        });

        $result = $this->runner()->run(NiumHkSubmitKycOneShotRunner::APPLICANT);
        $this->assertSame('STOP_KYC_OUTCOME_UNKNOWN_NO_RETRY', $result['terminal']);
        $this->assertSame(['POST'], $calls->methods);
    }

    public function test_pass_requires_exactly_one_scoped_post_evidence(): void
    {
        $this->mock(NiumService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('clientId')->andReturn('safe-test-client');
            $mock->shouldReceive('path')->andReturn('/safe/submitKyc');
            $mock->shouldReceive('post')->once()->andReturn(new Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                'kycStatus' => 'initiated',
                'kycMode' => 'biometric_kyc',
                'entityType' => 'applicant',
                'referenceId' => self::APPLICANT_REFERENCE,
                'redirectUrl' => 'https://redirect.example.test/session',
            ], JSON_THROW_ON_ERROR))));
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('postcondition failed closed');
        $this->runner()->run(NiumHkSubmitKycOneShotRunner::APPLICANT);
    }

    public function test_unrelated_submit_logs_do_not_affect_postcondition(): void
    {
        $this->mock(NiumService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('clientId')->andReturn('safe-test-client');
            $mock->shouldReceive('path')->andReturn('/safe/submitKyc');
            $mock->shouldReceive('post')->once()->andReturnUsing(function (): Response {
                $this->logSubmit(self::STAKEHOLDER_REFERENCE, 200);
                $this->logSubmit(self::APPLICANT_REFERENCE, 200);

                return new Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                    'kycStatus' => 'initiated',
                    'kycMode' => 'biometric_kyc',
                    'entityType' => 'applicant',
                    'referenceId' => self::APPLICANT_REFERENCE,
                    'redirectUrl' => 'https://redirect.example.test/session',
                ], JSON_THROW_ON_ERROR)));
            });
        });

        $result = $this->runner()->run(NiumHkSubmitKycOneShotRunner::APPLICANT);

        $this->assertSame('PASS_KYC_INITIATED', $result['terminal']);
        $this->assertSame(1, $result['submit_kyc_post_count']);
        $this->assertDatabaseCount('api_request_logs', 2);
    }

    #[DataProvider('reviewResponses')]
    public function test_incomplete_or_mismatched_success_response_holds(array $response): void
    {
        $this->mockResponse($response, 200);
        $result = $this->runner()->run(NiumHkSubmitKycOneShotRunner::APPLICANT);

        $this->assertSame('HOLD_RESPONSE_REVIEW', $result['terminal']);
    }

    public function test_prior_submit_attempt_blocks_replay(): void
    {
        $this->logSubmit(self::APPLICANT_REFERENCE, 200);
        $this->assertFailsBeforeHttp(NiumHkSubmitKycOneShotRunner::APPLICANT, 1);
    }

    public function test_passport_number_is_not_persisted_in_operational_evidence(): void
    {
        $this->mockInitiated(NiumHkSubmitKycOneShotRunner::APPLICANT, self::APPLICANT_REFERENCE);

        $result = $this->runner()->run(NiumHkSubmitKycOneShotRunner::APPLICANT);

        $evidence = UserProviderAccount::query()->findOrFail(7)->metadata;
        $logs = ApiRequestLog::query()->get()->toArray();
        $safeOutput = json_encode([$result, $evidence, $logs], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::PLACEHOLDER_IDENTITY, $safeOutput);
    }

    public function test_request_logging_sanitizer_redacts_identification_number(): void
    {
        $sanitized = app(SensitiveDataSanitizer::class)->sanitize($this->validPayload());

        $this->assertSame(
            '[REDACTED]',
            $sanitized['proofOfIdentityDocument'][0]['identificationNumber'],
        );
        $this->assertStringNotContainsString(
            self::PLACEHOLDER_IDENTITY,
            json_encode($sanitized, JSON_THROW_ON_ERROR),
        );
    }

    public function test_account_four_remains_byte_identical(): void
    {
        $before = UserProviderAccount::query()->findOrFail(4)->getRawOriginal();
        $this->mockInitiated(NiumHkSubmitKycOneShotRunner::APPLICANT, self::APPLICANT_REFERENCE);
        $this->runner()->run(NiumHkSubmitKycOneShotRunner::APPLICANT);

        $this->assertSame($before, UserProviderAccount::query()->findOrFail(4)->getRawOriginal());
    }

    public static function targets(): array
    {
        return [
            'applicant' => [NiumHkSubmitKycOneShotRunner::APPLICANT, 13, self::APPLICANT_REFERENCE],
            'stakeholder' => [NiumHkSubmitKycOneShotRunner::STAKEHOLDER, 14, self::STAKEHOLDER_REFERENCE],
        ];
    }

    public static function invalidAccountStates(): array
    {
        return [
            'missing customer' => ['external_customer_id', null],
            'missing wallet' => ['external_account_id', null],
            'not reconciled' => ['reconciliation_status', 'failed'],
        ];
    }

    public static function invalidIdentitySources(): array
    {
        $valid = self::identityMetadata();

        return [
            'non-array identity' => ['not-an-object'],
            'list identity' => [['passport', self::PLACEHOLDER_IDENTITY]],
            'wrong type' => [[...$valid, 'type' => 'national_id']],
            'empty number' => [[...$valid, 'identification_number' => '']],
            'numeric number' => [[...$valid, 'identification_number' => 123456]],
            'wrong country' => [[...$valid, 'issuance_country' => 'SG']],
            'malformed expiry' => [[...$valid, 'expiry_date' => '2030-02-31']],
            'expired' => [[...$valid, 'expiry_date' => '2020-01-01']],
            'factual not true' => [[...$valid, 'factual' => false]],
            'synthetic not false' => [[...$valid, 'synthetic' => true]],
            'wrong source' => [[...$valid, 'source' => 'unapproved_source']],
        ];
    }

    public static function rejectedStatuses(): array
    {
        return ['400' => [400], '500' => [500]];
    }

    public static function invalidPayloadIdentityValues(): array
    {
        return [
            'numeric identification number' => [['identificationNumber' => 123456]],
            'non-string expiry' => [['expiryDate' => 20300101]],
            'normalized invalid date' => [['expiryDate' => '2030-02-31']],
            'malformed date' => [['expiryDate' => 'not-a-date']],
        ];
    }

    public static function reviewResponses(): array
    {
        $valid = [
            'kycStatus' => 'initiated',
            'kycMode' => 'biometric_kyc',
            'entityType' => 'applicant',
            'referenceId' => self::APPLICANT_REFERENCE,
            'redirectUrl' => 'https://redirect.example.test/session',
        ];

        return [
            'missing redirect' => [[...$valid, 'redirectUrl' => null]],
            'wrong entity' => [[...$valid, 'entityType' => 'individual_stakeholder']],
            'wrong reference' => [[...$valid, 'referenceId' => self::STAKEHOLDER_REFERENCE]],
            'wrong status' => [[...$valid, 'kycStatus' => 'submitted']],
        ];
    }

    private function runner(): NiumHkSubmitKycOneShotRunner
    {
        return app(NiumHkSubmitKycOneShotRunner::class);
    }

    private function mockInitiated(string $target, string $referenceId): object
    {
        return $this->mockResponse([
            'kycStatus' => 'initiated',
            'kycMode' => 'biometric_kyc',
            'entityType' => $target,
            'referenceId' => $referenceId,
            'redirectUrl' => 'https://redirect.example.test/session',
        ], 200);
    }

    private function mockResponse(array $body, int $status): object
    {
        $calls = new class { public array $methods = []; };
        $this->mock(NiumService::class, function (MockInterface $mock) use ($calls, $body, $status): void {
            $mock->shouldReceive('clientId')->andReturn('safe-test-client');
            $mock->shouldReceive('path')->andReturn('/safe/submitKyc');
            $mock->shouldReceive('post')->once()->andReturnUsing(function () use ($calls, $body, $status): Response {
                $calls->methods[] = 'POST';
                $this->logSubmit($body['referenceId'] ?? self::APPLICANT_REFERENCE, $status);

                return new Response(new \GuzzleHttp\Psr7\Response($status, [], json_encode($body, JSON_THROW_ON_ERROR)));
            });
        });

        return $calls;
    }

    private function assertFailsBeforeHttp(string $target, int $expectedLogCount = 0): void
    {
        $this->mock(NiumService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('post');
        });

        try {
            $this->runner()->run($target);
            $this->fail('Expected preflight failure.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertDatabaseCount('api_request_logs', $expectedLogCount);
    }

    private function validPayload(): array
    {
        return app(NiumHkSubmitKycPayloadFactory::class)->build(
            KycRelatedPerson::query()->findOrFail(13),
            NiumHkSubmitKycOneShotRunner::APPLICANT,
            self::APPLICANT_REFERENCE,
        );
    }

    private function seedCheckpoint(): void
    {
        $provider = IntegrationProvider::query()->forceCreate(['id' => 1, 'code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        User::factory()->create(['id' => 4]);
        $user = User::factory()->create(['id' => 9]);
        KycProfile::query()->forceCreate([
            'id' => 9,
            'user_id' => 9,
            'status' => 'approved',
            'applicant_type' => 'business',
            'legal_name' => 'Placeholder Company',
            'address_line1' => 'Placeholder Address',
            'city' => 'Hong Kong',
            'country_code' => 'HK',
        ]);
        foreach ([13 => 'applicant', 14 => 'beneficial_owner'] as $id => $relationship) {
            KycRelatedPerson::query()->forceCreate([
                'id' => $id,
                'kyc_profile_id' => 9,
                'relationship_type' => $relationship,
                'status' => 'approved',
                'legal_name' => 'Placeholder Person',
                'metadata' => ['nium_biometric_identity' => self::identityMetadata()],
            ]);
        }

        UserProviderAccount::query()->forceCreate(['id' => 4, 'user_id' => 4, 'provider_id' => 1]);
        $account = UserProviderAccount::query()->forceCreate([
            'id' => 7,
            'user_id' => 9,
            'provider_id' => $provider->id,
            'external_customer_id' => 'customer-safe-id',
            'external_account_id' => 'wallet-safe-id',
            'provider_status' => 'pending',
            'provider_sub_status' => 'awaiting_kyc',
            'reconciliation_status' => 'reconciled',
            'metadata' => [],
        ]);
        $this->seedEntityState($account, self::APPLICANT_REFERENCE, 'applicant');
        $this->seedEntityState($account->fresh(), self::STAKEHOLDER_REFERENCE, 'individual_stakeholder');
        $this->seedCustomerWebhook();
        $this->seedEntityWebhook(13, self::APPLICANT_REFERENCE, 'applicant');
        $this->seedEntityWebhook(14, self::STAKEHOLDER_REFERENCE, 'individual_stakeholder');
    }

    private function seedCustomerWebhook(): void
    {
        WebhookEvent::query()->forceCreate([
            'provider_id' => 1,
            'event_id' => 'customer-status-'.WebhookEvent::query()->count(),
            'event_type' => 'CUSTOMER_STATUS_WEBHOOK',
            'external_resource_id' => 'customer-safe-id',
            'payload' => ['status' => 'pending', 'subStatus' => 'awaiting_kyc'],
            'processing_status' => 'processed',
            'processed_at' => now(),
        ]);
    }

    private function seedEntityWebhook(
        int $personId,
        string $referenceId,
        string $entityType,
        string $kycStatus = 'kyc_required',
    ): void {
        WebhookEvent::query()->forceCreate([
            'provider_id' => 1,
            'event_id' => 'entity-status-'.WebhookEvent::query()->count(),
            'event_type' => 'CUSTOMER_ENTITY_KYC_STATUS',
            'external_resource_id' => 'customer-safe-id',
            'payload' => [
                'externalId' => 'origin-wallet-person-'.$personId,
                'kycStatus' => $kycStatus,
                'entityType' => $entityType,
                'referenceId' => $referenceId,
            ],
            'processing_status' => 'processed',
            'processed_at' => now(),
        ]);
    }

    private function seedEntityState(UserProviderAccount $account, string $referenceId, string $entityType): void
    {
        $metadata = (array) $account->metadata;
        $key = 'ref_'.substr(hash('sha256', $referenceId), 0, 16);
        $metadata['nium_entity_kyc_states'][$key] = [
            'kyc_status' => 'kyc_required',
            'entity_type' => $entityType,
            'updated_at' => now()->toISOString(),
        ];
        $account->forceFill(['metadata' => $metadata])->save();
    }

    private function logSubmit(string $referenceId, int $status): void
    {
        ApiRequestLog::query()->create([
            'provider_id' => 1,
            'user_id' => 9,
            'operation' => 'submit_kyc',
            'external_reference' => $referenceId,
            'request_method' => 'POST',
            'request_url' => '/safe/submitKyc',
            'response_status' => $status,
            'transport_outcome' => 'response_received',
            'is_success' => $status >= 200 && $status < 300,
        ]);
    }

    private static function identityMetadata(): array
    {
        return [
            'type' => 'passport',
            'identification_number' => self::PLACEHOLDER_IDENTITY,
            'issuance_country' => 'VN',
            'expiry_date' => '2099-12-31',
            'factual' => true,
            'synthetic' => false,
            'source' => 'operator_verified_factual_identity_v1',
        ];
    }
}
