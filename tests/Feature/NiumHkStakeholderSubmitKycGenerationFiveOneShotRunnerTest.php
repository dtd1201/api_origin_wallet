<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\KycDocument;
use App\Models\KycProfile;
use App\Models\KycRelatedPerson;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use App\Services\Nium\NiumHkStakeholderSubmitKycGenerationFiveOneShotRunner;
use App\Services\Nium\NiumHkKycIdentityResolver;
use App\Services\Nium\NiumHkSubmitKycPayloadFactory;
use App\Services\Nium\NiumHkSubmitKycValidator;
use App\Services\Nium\NiumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class NiumHkStakeholderSubmitKycGenerationFiveOneShotRunnerTest extends TestCase
{
    use RefreshDatabase;

    private const APPLICANT_REFERENCE = 'c620e0e9-ab0a-43bd-aa10-44f573db723a';
    private const STAKEHOLDER_REFERENCE = '7609d9d1-9d37-4e08-9197-602d792f7a2e';
    private const IDENTITY = 'FACTUAL-PASSPORT-NUMBER';
    private const ERROR_FIELDS = [
        'proofOfIdentityDocument[0].identificationNumber' => 'ac1d1f08d0faba5d',
        'proofOfIdentityDocument[0].expiryDate' => 'fb88615850deb9e8',
        'proofOfIdentityDocument[0].issuanceCountry' => '9c46af0c3435d750',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEvidence();
        $this->mock(NiumService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('post'));
    }

    public function test_generation_three_error_field_fingerprints_recompute_exactly(): void
    {
        foreach (self::ERROR_FIELDS as $field => $fingerprint) {
            $this->assertSame($fingerprint, substr(hash('sha256', $field), 0, 16));
        }
    }

    public function test_exact_three_field_set_passes_and_safe_audit_is_immutable(): void
    {
        $before = $this->immutableEvidence();
        $accountSeven = UserProviderAccount::query()->findOrFail(7)->getRawOriginal();

        $result = $this->runner()->audit();

        $this->assertSame('READY_FOR_SEPARATE_HUMAN_APPROVAL', $result['terminal']);
        $this->assertSame(115, $result['previous_log_id']);
        $this->assertSame(1, $result['previous_error_field_count']);
        $this->assertSame('individual_stakeholder', $result['entity_type']);
        $this->assertSame('manual_kyc', $result['kyc_mode']);
        $this->assertSame('HK', $result['region']);
        $this->assertSame('array', $result['identity_container']);
        $this->assertSame(1, $result['identity_count']);
        $this->assertSame('passport', $result['identity_type']);
        $this->assertSame(1, $result['identity_file_id_count']);
        $this->assertTrue($result['identification_number_present']);
        $this->assertTrue($result['expiry_date_present']);
        $this->assertSame('VN', $result['issuance_country']);
        $this->assertSame('object', $result['poa_container']);
        $this->assertSame(1, $result['poa_file_id_count']);
        $this->assertSame(27, $result['identity_document_id']);
        $this->assertSame(28, $result['poa_document_id']);
        $this->assertSame(0, $result['g5_post_count']);
        $this->assertSame(0, $result['db_write_count']);
        $this->assertStringNotContainsString(self::IDENTITY, json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame($before, $this->immutableEvidence());
        $this->assertSame($accountSeven, UserProviderAccount::query()->findOrFail(7)->getRawOriginal());
        $this->assertArrayNotHasKey('nium_stakeholder_submit_kyc_retry_generation_5', UserProviderAccount::query()->findOrFail(7)->metadata);
    }

    public function test_g5_manual_identity_and_poa_contract(): void
    {
        $payload = $this->payload();
        $identity = $payload['proofOfIdentityDocument'][0];

        $this->assertTrue(array_is_list($payload['proofOfIdentityDocument']));
        $this->assertCount(1, $payload['proofOfIdentityDocument']);
        $this->assertSame('passport', $identity['type']);
        $this->assertSame(['30000000-0000-4000-8000-000000000027'], $identity['fileIds']);
        $this->assertSame(self::IDENTITY, $identity['identificationNumber']);
        $this->assertSame('2099-12-31', $identity['expiryDate']);
        $this->assertSame('VN', $identity['issuanceCountry']);
        $this->assertFalse(array_is_list($payload['proofOfAddressDocument']));
        $this->assertSame('proof_of_address', $payload['proofOfAddressDocument']['type']);
        $this->assertSame(['30000000-0000-4000-8000-000000000028'], $payload['proofOfAddressDocument']['fileIds']);
        $this->assertArrayNotHasKey('isResident', $payload);
    }

    public function test_exact_generation_four_rejection_and_tenant_webhook_evidence_pass(): void
    {
        $result = $this->runner()->audit();

        $this->assertSame(115, $result['previous_log_id']);
        $this->assertSame(1, $result['previous_error_field_count']);
        $this->assertSame(6, $result['tenant_webhook_id']);
        $this->assertSame('individual_stakeholder', $result['tenant_entity_type']);
        $this->assertFalse($result['is_resident_present']);
    }

    public function test_wrong_generation_four_rejection_evidence_fails(): void
    {
        foreach ([
            ['response_status' => 422],
            ['transport_outcome' => 'connection_failed'],
            ['external_reference' => 'wrong-reference'],
        ] as $changes) {
            $log = ApiRequestLog::query()->findOrFail(115);
            $original = $log->getRawOriginal();
            $log->forceFill($changes)->save();
            $this->assertAuditHolds();
            $log->forceFill($original)->save();
        }
    }

    public function test_wrong_generation_four_error_item_shape_fails(): void
    {
        foreach ([
            ['error_field' => 'kycMode'],
            ['error_field_fingerprint' => str_repeat('a', 16)],
        ] as $changes) {
            $log = ApiRequestLog::query()->findOrFail(115);
            $body = $log->response_body;
            $body['error_items'][0] = [...$body['error_items'][0], ...$changes];
            $log->forceFill(['response_body' => $body])->save();
            $this->assertAuditHolds();
            $this->seedGenerationFourResponseBody($log);
        }
    }

    public function test_wrong_tenant_webhook_reference_or_entity_type_fails(): void
    {
        foreach ([
            ['referenceId' => 'wrong-reference'],
            ['entityType' => 'INDIVIDUAL_STAKEHOLDER'],
        ] as $changes) {
            $event = WebhookEvent::query()->findOrFail(6);
            $original = $event->payload;
            $event->forceFill(['payload' => [...$event->payload, ...$changes]])->save();
            $this->assertAuditHolds();
            $event->forceFill(['payload' => $original])->save();
        }
    }

    public function test_wrong_tenant_webhook_processing_evidence_fails(): void
    {
        foreach ([
            ['event_type' => 'KYC_STATUS_WEBHOOK'],
            ['processing_status' => 'failed'],
            ['processed_at' => null],
        ] as $changes) {
            $event = WebhookEvent::query()->findOrFail(6);
            $original = $event->getRawOriginal();
            $event->forceFill($changes)->save();
            $this->assertAuditHolds();
            $event->forceFill($original)->save();
        }
    }

    public function test_generation_five_exact_casing_and_absent_is_resident_are_enforced(): void
    {
        foreach ([
            ['entityType' => 'INDIVIDUAL_STAKEHOLDER'],
            ['kycMode' => 'MANUAL_KYC'],
            ['isResident' => false],
        ] as $changes) {
            $payload = [...$this->payload(), ...$changes];
            try {
                app(NiumHkSubmitKycValidator::class)->assertManualGenerationFive($payload);
                $this->fail('Invalid generation #5 exact contract was accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_manual_validator_rejects_missing_identity_fields_and_multiple_file_ids(): void
    {
        foreach (['identificationNumber', 'expiryDate', 'issuanceCountry'] as $field) {
            $payload = $this->payload();
            unset($payload['proofOfIdentityDocument'][0][$field]);
            try {
                app(NiumHkSubmitKycValidator::class)->assertManual($payload);
                $this->fail("Missing {$field} was accepted.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        $payload = $this->payload();
        $payload['proofOfIdentityDocument'][0]['fileIds'][] = '30000000-0000-4000-8000-000000000099';
        $this->expectException(RuntimeException::class);
        app(NiumHkSubmitKycValidator::class)->assertManual($payload);
    }

    public function test_missing_extra_duplicate_and_wrong_114_evidence_fail(): void
    {
        $this->mutateItems(fn (array $items): array => array_slice($items, 1));
        $this->assertAuditHolds();
    }

    public function test_extra_fourth_114_error_item_fails(): void
    {
        $this->mutateItems(fn (array $items): array => [...$items, ['error_field_fingerprint' => str_repeat('a', 16)]]);
        $this->assertAuditHolds();
    }

    public function test_duplicate_114_fingerprint_fails(): void
    {
        $this->mutateItems(function (array $items): array {
            $items[2]['error_field_fingerprint'] = $items[0]['error_field_fingerprint'];
            return $items;
        });
        $this->assertAuditHolds();
    }

    public function test_wrong_114_log_id_http_transport_and_reference_fail(): void
    {
        ApiRequestLog::query()->findOrFail(114)->forceFill(['id' => 116])->save();
        $this->assertAuditHolds();
    }

    public function test_wrong_114_http_fails(): void
    {
        ApiRequestLog::query()->findOrFail(114)->forceFill(['response_status' => 422])->save();
        $this->assertAuditHolds();
    }

    public function test_wrong_114_transport_fails(): void
    {
        ApiRequestLog::query()->findOrFail(114)->forceFill(['transport_outcome' => 'connection_failed'])->save();
        $this->assertAuditHolds();
    }

    public function test_wrong_114_reference_fails(): void
    {
        ApiRequestLog::query()->findOrFail(114)->forceFill(['external_reference' => 'wrong-reference'])->save();
        $this->assertAuditHolds();
    }

    public function test_missing_or_invalid_factual_identity_metadata_fails(): void
    {
        $this->setIdentity(['identification_number' => '']);
        $this->assertAuditHolds();
    }

    public function test_missing_expiry_date_fails(): void
    {
        $this->setIdentity(['expiry_date' => null]);
        $this->assertAuditHolds();
    }

    public function test_wrong_issuance_country_fails(): void
    {
        $this->setIdentity(['issuance_country' => 'US']);
        $this->assertAuditHolds();
    }

    public function test_identity_resolver_returns_validated_factual_issuance_country(): void
    {
        $identity = app(NiumHkKycIdentityResolver::class)->resolve(KycRelatedPerson::query()->findOrFail(14));

        $this->assertSame(
            KycRelatedPerson::query()->findOrFail(14)->metadata['nium_biometric_identity']['issuance_country'],
            $identity['issuance_country'],
        );
        $this->assertSame('VN', $identity['issuance_country']);
    }

    public function test_synthetic_identity_metadata_fails(): void
    {
        $this->setIdentity(['synthetic' => true]);
        $this->assertAuditHolds();
    }

    public function test_generation_three_rejected_claim_and_no_g5_claim_are_required(): void
    {
        $account = UserProviderAccount::query()->findOrFail(7);
        $metadata = $account->metadata;
        $metadata['nium_stakeholder_submit_kyc_retry_generation_4']['state'] = 'unknown';
        $account->forceFill(['metadata' => $metadata])->save();
        $this->assertAuditHolds();
    }

    public function test_existing_g5_claim_blocks(): void
    {
        $account = UserProviderAccount::query()->findOrFail(7);
        $metadata = $account->metadata;
        $metadata['nium_stakeholder_submit_kyc_retry_generation_5'] = ['state' => 'submitting'];
        $account->forceFill(['metadata' => $metadata])->save();
        $this->assertAuditHolds('HOLD_GENERATION_5_ALREADY_CLAIMED');
    }

    public function test_run_requires_separate_human_approval(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Separate human approval');
        $this->runner()->run();
    }

    public function test_success_rejection_transport_and_review_are_one_shot(): void
    {
        $result = $this->runMocked(200, ['kycStatus' => 'initiated', 'kycMode' => 'manual_kyc',
            'entityType' => 'individual_stakeholder', 'referenceId' => self::STAKEHOLDER_REFERENCE]);
        $this->assertSame('PASS_KYC_INITIATED', $result['terminal']);
        $this->assertExecutionClosed('initiated');
    }

    public function test_uppercase_success_response_is_accepted(): void
    {
        $result = $this->runMocked(200, ['kycStatus' => 'INITIATED', 'kycMode' => 'MANUAL_KYC',
            'entityType' => 'INDIVIDUAL_STAKEHOLDER', 'referenceId' => self::STAKEHOLDER_REFERENCE]);
        $this->assertSame('PASS_KYC_INITIATED', $result['terminal']);
        $this->assertExecutionClosed('initiated');
    }

    public function test_mixed_case_success_response_is_accepted(): void
    {
        $result = $this->runMocked(200, ['kycStatus' => 'Initiated', 'kycMode' => 'Manual_Kyc',
            'entityType' => 'Individual_Stakeholder', 'referenceId' => self::STAKEHOLDER_REFERENCE]);
        $this->assertSame('PASS_KYC_INITIATED', $result['terminal']);
        $this->assertExecutionClosed('initiated');
    }

    public function test_wrong_success_response_kyc_mode_requires_review(): void
    {
        $this->assertResponseReviewFor(['kycMode' => 'biometric_kyc']);
    }

    public function test_wrong_success_response_entity_type_requires_review(): void
    {
        $this->assertResponseReviewFor(['entityType' => 'applicant']);
    }

    public function test_wrong_success_response_reference_requires_review(): void
    {
        $this->assertResponseReviewFor(['referenceId' => 'wrong-reference']);
    }

    public function test_wrong_success_response_kyc_status_requires_review(): void
    {
        $this->assertResponseReviewFor(['kycStatus' => 'kyc_required']);
    }

    public function test_rejection_is_one_shot(): void
    {
        $result = $this->runMocked(400, ['error_code' => 'invalid_input']);
        $this->assertSame('STOP_KYC_REJECTED_NO_RETRY', $result['terminal']);
        $this->assertExecutionClosed('rejected');
    }

    public function test_transport_unknown_is_one_shot(): void
    {
        $before = $this->immutableEvidence();
        $this->mock(NiumService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('clientId')->andReturn('safe-test-client');
            $mock->shouldReceive('path')->andReturn('/safe/submitKyc');
            $mock->shouldReceive('post')->once()->andReturnUsing(function (): never {
                $this->assertSame('submitting', $this->g5State());
                throw new ConnectionException('ambiguous');
            });
        });
        $result = $this->runner()->run(true);
        $this->assertSame('STOP_KYC_OUTCOME_UNKNOWN_NO_RETRY', $result['terminal']);
        $this->assertSame($before, $this->immutableEvidence());
        $this->assertExecutionClosed('unknown', 0);
    }

    public function test_malformed_success_is_review_and_blocks_rerun(): void
    {
        $result = $this->runMocked(200, ['kycStatus' => 'initiated']);
        $this->assertSame('HOLD_RESPONSE_REVIEW', $result['terminal']);
        $this->assertExecutionClosed('response_review');
    }

    private function runMocked(int $status, array $body): array
    {
        $before = $this->immutableEvidence();
        $this->mock(NiumService::class, function (MockInterface $mock) use ($status, $body): void {
            $mock->shouldReceive('clientId')->andReturn('safe-test-client');
            $mock->shouldReceive('path')->andReturn('/safe/submitKyc');
            $mock->shouldReceive('post')->once()->andReturnUsing(function () use ($status, $body): Response {
                $this->assertSame('submitting', $this->g5State());
                $claim = UserProviderAccount::query()->findOrFail(7)->metadata['nium_stakeholder_submit_kyc_retry_generation_5'];
                $this->assertSame(115, $claim['previous_log_id']);
                $this->assertSame(400, $claim['previous_http_status']);
                $this->assertSame(1, $claim['previous_error_field_count']);
                $this->assertArrayNotHasKey('previous_error_code', $claim);
                $this->createLog(116, self::STAKEHOLDER_REFERENCE, $status, $status < 400, $body);
                return new Response(new \GuzzleHttp\Psr7\Response($status, [], json_encode($body, JSON_THROW_ON_ERROR)));
            });
        });
        $result = $this->runner()->run(true);
        $this->assertSame(1, $result['g5_post_count']);
        $this->assertSame($before, $this->immutableEvidence());
        return $result;
    }

    private function assertResponseReviewFor(array $changes): void
    {
        $body = [...[
            'kycStatus' => 'initiated',
            'kycMode' => 'manual_kyc',
            'entityType' => 'individual_stakeholder',
            'referenceId' => self::STAKEHOLDER_REFERENCE,
        ], ...$changes];

        $this->assertSame('HOLD_RESPONSE_REVIEW', $this->runMocked(200, $body)['terminal']);
        $this->assertExecutionClosed('response_review');
    }

    private function assertExecutionClosed(string $state, int $posts = 1): void
    {
        $this->assertSame($state, $this->g5State());
        $this->assertSame($posts, ApiRequestLog::query()->where('id', '>', 115)->where('operation', 'submit_kyc')->count());
        try {
            $this->runner()->run(true);
            $this->fail('Expected G5 claim to block second run.');
        } catch (RuntimeException $exception) {
            $this->assertSame('HOLD_GENERATION_5_ALREADY_CLAIMED', $exception->getMessage());
        }
        $this->assertSame($posts, ApiRequestLog::query()->where('id', '>', 115)->where('operation', 'submit_kyc')->count());
        $this->assertSame(0, ApiRequestLog::query()->whereIn('operation', [
            'onboarding_simulation_submit_kyc', 'file_create', 'file_details', 'van', 'payout',
        ])->count());
    }

    private function payload(): array
    {
        return app(NiumHkSubmitKycPayloadFactory::class)->buildManualGenerationFive(
            KycRelatedPerson::query()->findOrFail(14), self::STAKEHOLDER_REFERENCE,
            KycDocument::query()->findOrFail(27), KycDocument::query()->findOrFail(28),
        );
    }

    private function immutableEvidence(): array
    {
        return [
            'logs' => ApiRequestLog::query()->whereIn('id', [104, 106, 113, 114])->orderBy('id')->get()->map->getRawOriginal()->all(),
            'account_4' => UserProviderAccount::query()->findOrFail(4)->getRawOriginal(),
            'documents' => KycDocument::query()->whereIn('id', [27, 28])->orderBy('id')->get()->map->getRawOriginal()->all(),
        ];
    }

    private function mutateItems(callable $callback): void
    {
        $log = ApiRequestLog::query()->findOrFail(114);
        $body = $log->response_body;
        $body['error_items'] = $callback($body['error_items']);
        $log->forceFill(['response_body' => $body])->save();
    }

    private function setIdentity(array $changes): void
    {
        $person = KycRelatedPerson::query()->findOrFail(14);
        $metadata = $person->metadata;
        $metadata['nium_biometric_identity'] = [...$metadata['nium_biometric_identity'], ...$changes];
        $person->forceFill(['metadata' => $metadata])->save();
    }

    private function g5State(): ?string
    {
        return UserProviderAccount::query()->findOrFail(7)->metadata['nium_stakeholder_submit_kyc_retry_generation_5']['state'] ?? null;
    }

    private function assertAuditHolds(?string $message = null): void
    {
        try {
            $this->runner()->audit();
            $this->fail('Expected G5 audit hold.');
        } catch (RuntimeException $exception) {
            if ($message !== null) {
                $this->assertSame($message, $exception->getMessage());
            }
            $this->addToAssertionCount(1);
        }
    }

    private function runner(): NiumHkStakeholderSubmitKycGenerationFiveOneShotRunner
    {
        return app(NiumHkStakeholderSubmitKycGenerationFiveOneShotRunner::class);
    }

    private function seedEvidence(): void
    {
        IntegrationProvider::query()->forceCreate(['id' => 1, 'code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        User::factory()->create(['id' => 4]);
        User::factory()->create(['id' => 9]);
        KycProfile::query()->forceCreate(['id' => 9, 'user_id' => 9, 'status' => 'approved', 'applicant_type' => 'business',
            'legal_name' => 'Placeholder Company', 'address_line1' => 'Placeholder Address', 'city' => 'Hong Kong', 'country_code' => 'HK']);
        KycRelatedPerson::query()->forceCreate(['id' => 14, 'kyc_profile_id' => 9, 'relationship_type' => 'beneficial_owner',
            'status' => 'approved', 'legal_name' => 'Placeholder Person', 'metadata' => ['nium_biometric_identity' => [
                'type' => 'passport', 'identification_number' => self::IDENTITY, 'issuance_country' => 'VN',
                'expiry_date' => '2099-12-31', 'factual' => true, 'synthetic' => false,
                'source' => 'operator_verified_factual_identity_v1',
            ]]]);
        foreach ([['id' => 27, 'type' => 'passport'], ['id' => 28, 'type' => 'bank_statement']] as $document) {
            KycDocument::query()->forceCreate(['id' => $document['id'], 'kyc_profile_id' => 9, 'kyc_related_person_id' => 14,
                'type' => $document['type'], 'status' => 'approved', 'file_url' => 'private://factual/'.$document['id'],
                'metadata' => ['document_purpose' => $document['type'], 'factual' => true, 'synthetic' => false,
                    'nium_file_id' => '30000000-0000-4000-8000-0000000000'.$document['id'], 'nium_file_state' => 'AVAILABLE']]);
        }
        UserProviderAccount::query()->forceCreate(['id' => 4, 'user_id' => 4, 'provider_id' => 1]);
        UserProviderAccount::query()->forceCreate(['id' => 7, 'user_id' => 9, 'provider_id' => 1,
            'external_customer_id' => 'customer-safe-id', 'external_account_id' => 'wallet-safe-id',
            'reconciliation_status' => 'reconciled',
            'metadata' => ['nium_stakeholder_submit_kyc_retry_generation_4' => ['state' => 'rejected']]]);
        WebhookEvent::query()->forceCreate(['id' => 6, 'provider_id' => 1, 'event_id' => 'stakeholder-evidence',
            'event_type' => 'CUSTOMER_ENTITY_KYC_STATUS', 'external_resource_id' => self::STAKEHOLDER_REFERENCE,
            'payload' => ['referenceId' => self::STAKEHOLDER_REFERENCE, 'externalId' => 'origin-wallet-person-14',
                'entityType' => 'individual_stakeholder', 'kycStatus' => 'kyc_required', 'kycMode' => 'none'],
            'processing_status' => 'processed', 'processed_at' => now()]);
        WebhookEvent::query()->forceCreate(['provider_id' => 1, 'event_id' => 'customer-awaiting-kyc',
            'event_type' => 'CUSTOMER_STATUS_WEBHOOK', 'external_resource_id' => 'customer-safe-id',
            'payload' => ['status' => 'pending', 'subStatus' => 'awaiting_kyc'], 'processing_status' => 'processed', 'processed_at' => now()]);
        $this->createLog(104, self::APPLICANT_REFERENCE, 200, true, []);
        $this->createLog(106, self::STAKEHOLDER_REFERENCE, 400, false, $this->errorBody('b4753588f3f6ef2b'));
        $this->createLog(113, self::STAKEHOLDER_REFERENCE, 400, false, [
            'error_code' => 'invalid_input',
            'error_field_fingerprint' => 'a5b7a48f01932655',
            'error_items' => [[
                'error_code' => 'invalid_input',
                'error_field_fingerprint' => 'a5b7a48f01932655',
            ]],
        ]);
        $this->createLog(114, self::STAKEHOLDER_REFERENCE, 400, false, ['error_code' => 'invalid_input', 'error_items' => array_map(
            fn (string $fingerprint): array => ['error_field_fingerprint' => $fingerprint],
            array_values(self::ERROR_FIELDS),
        )]);
        $this->createLog(115, self::STAKEHOLDER_REFERENCE, 400, false, [
            'error_code' => 'invalid_input',
            'error_field_fingerprint' => 'b4753588f3f6ef2b',
            'error_items' => [[
                'error_code' => 'invalid_input',
                'error_field' => 'entityType',
                'error_field_fingerprint' => 'b4753588f3f6ef2b',
            ]],
        ]);
    }

    private function createLog(int $id, string $reference, int $status, bool $success, array $body): void
    {
        ApiRequestLog::query()->forceCreate(['id' => $id, 'provider_id' => 1, 'user_id' => 9, 'operation' => 'submit_kyc',
            'external_reference' => $reference, 'request_method' => 'POST', 'request_url' => '/safe/submitKyc',
            'response_status' => $status, 'transport_outcome' => 'response_received', 'is_success' => $success, 'response_body' => $body]);
    }

    private function seedGenerationFourResponseBody(ApiRequestLog $log): void
    {
        $log->forceFill(['response_body' => [
            'error_code' => 'invalid_input',
            'error_field_fingerprint' => 'b4753588f3f6ef2b',
            'error_items' => [[
                'error_code' => 'invalid_input',
                'error_field' => 'entityType',
                'error_field_fingerprint' => 'b4753588f3f6ef2b',
            ]],
        ]])->save();
    }

    private function errorBody(string $fingerprint): array
    {
        return ['error_code' => 'invalid_input', 'error_field_fingerprint' => $fingerprint];
    }
}
