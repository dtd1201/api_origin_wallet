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
use App\Services\Nium\NiumHkStakeholderSubmitKycGenerationThreeOneShotRunner;
use App\Services\Nium\NiumHkSubmitKycPayloadFactory;
use App\Services\Nium\NiumHkSubmitKycValidator;
use App\Services\Nium\NiumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Mockery\MockInterface;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

class NiumHkStakeholderSubmitKycGenerationThreeOneShotRunnerTest extends TestCase
{
    use RefreshDatabase;

    private const APPLICANT_REFERENCE = 'c620e0e9-ab0a-43bd-aa10-44f573db723a';
    private const STAKEHOLDER_REFERENCE = '7609d9d1-9d37-4e08-9197-602d792f7a2e';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEvidence();
        $this->mock(NiumService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('post'));
    }

    public function test_proof_of_address_fingerprint_is_locked(): void
    {
        $this->assertSame('a5b7a48f01932655', substr(hash('sha256', 'proofOfAddressDocument'), 0, 16));
    }

    public function test_current_lineage_contains_required_generation_three_parent(): void
    {
        $reflection = new ReflectionClass(NiumHkStakeholderSubmitKycGenerationThreeOneShotRunner::class);
        $this->assertSame(
            '9d975f965f539c5d8ef2d07e37e3632f1a6b7989',
            $reflection->getConstant('REQUIRED_PARENT_HEAD'),
        );
        $this->assertSame('READY_FOR_SEPARATE_HUMAN_APPROVAL', $this->runner()->audit()['terminal']);
    }

    public function test_lineage_containing_only_older_generation_two_parent_is_rejected(): void
    {
        $method = (new ReflectionClass(NiumHkStakeholderSubmitKycGenerationThreeOneShotRunner::class))
            ->getMethod('lineageIsCompatible');

        $this->assertFalse($method->invoke(null, '8241704f5e65dc0f93b1225846ddb10985a46d00', false));
    }

    public function test_manual_payload_uses_identity_array_and_poa_object(): void
    {
        $payload = $this->payload();

        $this->assertTrue(array_is_list($payload['proofOfIdentityDocument']));
        $this->assertCount(1, $payload['proofOfIdentityDocument']);
        $this->assertFalse(array_is_list($payload['proofOfAddressDocument']));
        $this->assertSame('proof_of_address', $payload['proofOfAddressDocument']['type']);
        $this->assertCount(1, $payload['proofOfAddressDocument']['fileIds']);
    }

    public function test_manual_payload_omits_poa_when_absent(): void
    {
        $payload = app(NiumHkSubmitKycPayloadFactory::class)->buildManual(
            KycRelatedPerson::query()->findOrFail(14),
            self::STAKEHOLDER_REFERENCE,
            KycDocument::query()->findOrFail(27),
            null,
        );

        $this->assertArrayNotHasKey('proofOfAddressDocument', $payload);
    }

    public function test_old_poa_array_is_rejected(): void
    {
        $payload = $this->payload();
        $payload['proofOfAddressDocument'] = [$payload['proofOfAddressDocument']];
        $this->expectException(RuntimeException::class);
        app(NiumHkSubmitKycValidator::class)->assertManual($payload);
    }

    public function test_malformed_poa_object_is_rejected(): void
    {
        $payload = $this->payload();
        $payload['proofOfAddressDocument'] = ['type' => '', 'fileIds' => []];
        $this->expectException(RuntimeException::class);
        app(NiumHkSubmitKycValidator::class)->assertManual($payload);
    }

    public function test_multiple_poa_file_ids_are_rejected(): void
    {
        $payload = $this->payload();
        $payload['proofOfAddressDocument']['fileIds'][] = '30000000-0000-4000-8000-000000000099';
        $this->expectException(RuntimeException::class);
        app(NiumHkSubmitKycValidator::class)->assertManual($payload);
    }

    public function test_offline_audit_is_ready_safe_and_immutable(): void
    {
        $logsBefore = ApiRequestLog::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $accountBefore = UserProviderAccount::query()->findOrFail(4)->getRawOriginal();
        $g3AccountBefore = UserProviderAccount::query()->findOrFail(7)->getRawOriginal();

        $result = $this->runner()->audit();

        $this->assertSame('READY_FOR_SEPARATE_HUMAN_APPROVAL', $result['terminal']);
        $this->assertSame('structured_exact', $result['generation_two_error_evidence_mode']);
        $this->assertSame('INDIVIDUAL_STAKEHOLDER', $result['entity_type']);
        $this->assertSame('MANUAL_KYC', $result['kyc_mode']);
        $this->assertSame('HK', $result['region']);
        $this->assertSame('array', $result['identity_container']);
        $this->assertSame(1, $result['identity_count']);
        $this->assertSame('object', $result['poa_container']);
        $this->assertSame(1, $result['poa_file_id_count']);
        $this->assertSame(27, $result['identity_document_id']);
        $this->assertSame(28, $result['poa_document_id']);
        $this->assertSame(0, $result['stakeholder_generation_three_post_count']);
        $this->assertSame(0, $result['db_write_count']);
        $this->assertSame($logsBefore, ApiRequestLog::query()->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertSame($accountBefore, UserProviderAccount::query()->findOrFail(4)->getRawOriginal());
        $this->assertSame($g3AccountBefore, UserProviderAccount::query()->findOrFail(7)->getRawOriginal());
    }

    public function test_wrong_113_fingerprint_is_rejected(): void
    {
        $log = ApiRequestLog::query()->findOrFail(113);
        $body = $log->response_body;
        $body['error_items'][0]['error_field_fingerprint'] = str_repeat('f', 16);
        $body['error_field_fingerprint'] = str_repeat('f', 16);
        $log->forceFill(['response_body' => $body])->save();
        $this->assertAuditHolds();
    }

    public function test_live_113_sanitized_structured_shape_is_ready_and_immutable(): void
    {
        $this->setSanitizedGenerationTwoBody(topLevelField: 'omit', itemField: 'omit');
        $logBefore = ApiRequestLog::query()->findOrFail(113)->getRawOriginal();
        $accountBefore = UserProviderAccount::query()->findOrFail(4)->getRawOriginal();
        $documentsBefore = KycDocument::query()->whereIn('id', [27, 28])->orderBy('id')->get()->map->getRawOriginal()->all();
        $accountSevenBefore = UserProviderAccount::query()->findOrFail(7)->getRawOriginal();

        $result = $this->runner()->audit();

        $this->assertSame('READY_FOR_SEPARATE_HUMAN_APPROVAL', $result['terminal']);
        $this->assertSame('sanitized_structured_fingerprint_113', $result['generation_two_error_evidence_mode']);
        $this->assertSame('a5b7a48f01932655', substr(hash('sha256', 'proofOfAddressDocument'), 0, 16));
        $this->assertSame($logBefore, ApiRequestLog::query()->findOrFail(113)->getRawOriginal());
        $this->assertSame($accountBefore, UserProviderAccount::query()->findOrFail(4)->getRawOriginal());
        $this->assertSame($documentsBefore, KycDocument::query()->whereIn('id', [27, 28])->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertSame($accountSevenBefore, UserProviderAccount::query()->findOrFail(7)->getRawOriginal());
        $this->assertArrayNotHasKey('nium_stakeholder_submit_kyc_retry_generation_3', UserProviderAccount::query()->findOrFail(7)->metadata);
        $this->assertSame(0, $result['stakeholder_generation_three_post_count']);
        $this->assertSame(0, $result['db_write_count']);
    }

    public function test_113_sanitized_null_top_level_and_null_item_is_accepted(): void
    {
        $this->assertSanitizedFieldCombinationAccepted('null', 'null');
    }

    public function test_113_sanitized_omitted_top_level_and_null_item_is_accepted(): void
    {
        $this->assertSanitizedFieldCombinationAccepted('omit', 'null');
    }

    public function test_113_sanitized_null_top_level_and_omitted_item_is_accepted(): void
    {
        $this->assertSanitizedFieldCombinationAccepted('null', 'omit');
    }

    public function test_113_sanitized_non_null_wrong_top_level_field_is_rejected(): void
    {
        $this->setSanitizedGenerationTwoBody(topLevelField: 'wrong', itemField: 'omit');
        $this->assertAuditHolds();
    }

    public function test_113_sanitized_non_null_wrong_item_field_is_rejected(): void
    {
        $this->setSanitizedGenerationTwoBody(topLevelField: 'omit', itemField: 'wrong');
        $this->assertAuditHolds();
    }

    public function test_113_sanitized_null_field_with_wrong_fingerprint_is_rejected(): void
    {
        $this->setSanitizedGenerationTwoBody();
        $log = ApiRequestLog::query()->findOrFail(113);
        $body = $log->response_body;
        $body['error_items'][0]['error_field_fingerprint'] = str_repeat('f', 16);
        $log->forceFill(['response_body' => $body])->save();
        $this->assertAuditHolds();
    }

    public function test_113_sanitized_null_field_with_empty_items_is_rejected(): void
    {
        $this->setSanitizedGenerationTwoBody();
        $log = ApiRequestLog::query()->findOrFail(113);
        $body = $log->response_body;
        $body['error_items'] = [];
        $log->forceFill(['response_body' => $body])->save();
        $this->assertAuditHolds();
    }

    public function test_113_sanitized_null_field_with_two_items_is_rejected(): void
    {
        $this->setSanitizedGenerationTwoBody();
        $log = ApiRequestLog::query()->findOrFail(113);
        $body = $log->response_body;
        $body['error_items'][] = $body['error_items'][0];
        $log->forceFill(['response_body' => $body])->save();
        $this->assertAuditHolds();
    }

    public function test_113_sanitized_shape_with_wrong_log_id_is_rejected(): void
    {
        $this->setSanitizedGenerationTwoBody();
        ApiRequestLog::query()->findOrFail(113)->forceFill(['id' => 114])->save();
        $this->assertAuditHolds();
    }

    public function test_113_sanitized_shape_with_wrong_http_is_rejected(): void
    {
        $this->setSanitizedGenerationTwoBody();
        ApiRequestLog::query()->findOrFail(113)->forceFill(['response_status' => 422])->save();
        $this->assertAuditHolds();
    }

    public function test_113_sanitized_shape_with_wrong_error_code_is_rejected(): void
    {
        $this->setSanitizedGenerationTwoBody();
        $log = ApiRequestLog::query()->findOrFail(113);
        $body = $log->response_body;
        $body['error_code'] = 'other_error';
        $log->forceFill(['response_body' => $body])->save();
        $this->assertAuditHolds();
    }

    public function test_113_sanitized_shape_with_wrong_transport_is_rejected(): void
    {
        $this->setSanitizedGenerationTwoBody();
        ApiRequestLog::query()->findOrFail(113)->forceFill(['transport_outcome' => 'connection_failed'])->save();
        $this->assertAuditHolds();
    }

    public function test_113_non_null_wrong_field_with_correct_fingerprint_is_rejected(): void
    {
        $this->setSanitizedGenerationTwoBody();
        $log = ApiRequestLog::query()->findOrFail(113);
        $body = $log->response_body;
        $body['error_items'][0]['error_field'] = 'entityType';
        $log->forceFill(['response_body' => $body])->save();
        $this->assertAuditHolds();
    }

    public function test_113_sanitized_malformed_item_is_rejected(): void
    {
        $this->setSanitizedGenerationTwoBody();
        $log = ApiRequestLog::query()->findOrFail(113);
        $body = $log->response_body;
        $body['error_items'] = ['malformed'];
        $log->forceFill(['response_body' => $body])->save();
        $this->assertAuditHolds();
    }

    public function test_106_legacy_flat_evidence_remains_accepted(): void
    {
        $this->setLegacyErrorBody(106, 'b4753588f3f6ef2b');
        $this->assertSame('READY_FOR_SEPARATE_HUMAN_APPROVAL', $this->runner()->audit()['terminal']);
    }

    public function test_106_wrong_legacy_flat_fingerprint_is_rejected(): void
    {
        $this->setLegacyErrorBody(106, str_repeat('f', 16));
        $this->assertAuditHolds();
    }

    public function test_113_malformed_structured_evidence_does_not_fall_back_to_correct_flat_fingerprint(): void
    {
        $log = ApiRequestLog::query()->findOrFail(113);
        $body = $log->response_body;
        $body['error_items'] = [['error_code' => 'invalid_input']];
        $log->forceFill(['response_body' => $body])->save();
        $this->assertAuditHolds();
    }

    public function test_113_wrong_structured_field_does_not_fall_back_to_correct_flat_fingerprint(): void
    {
        $log = ApiRequestLog::query()->findOrFail(113);
        $body = $log->response_body;
        $body['error_items'][0]['error_field'] = 'entityType';
        $log->forceFill(['response_body' => $body])->save();
        $this->assertAuditHolds();
    }

    public function test_113_missing_structured_evidence_fails_closed(): void
    {
        $this->setLegacyErrorBody(113, 'a5b7a48f01932655');
        $this->assertAuditHolds();
    }

    public function test_generation_two_rejected_claim_is_required(): void
    {
        $account = UserProviderAccount::query()->findOrFail(7);
        $metadata = $account->metadata;
        unset($metadata['nium_stakeholder_submit_kyc_retry_generation_2']);
        $account->forceFill(['metadata' => $metadata])->save();
        $this->assertAuditHolds();
    }

    public function test_existing_generation_three_claim_blocks_execution(): void
    {
        $account = UserProviderAccount::query()->findOrFail(7);
        $metadata = $account->metadata;
        $metadata['nium_stakeholder_submit_kyc_retry_generation_3'] = ['state' => 'submitting'];
        $account->forceFill(['metadata' => $metadata])->save();
        $this->assertAuditHolds('HOLD_GENERATION_3_ALREADY_CLAIMED');
    }

    public function test_documents_27_and_28_must_remain_available(): void
    {
        $document = KycDocument::query()->findOrFail(28);
        $document->forceFill(['metadata' => [...$document->metadata, 'nium_file_state' => 'PROCESSING']])->save();
        $this->assertAuditHolds('Factual documents 27 and 28 must be approved and NIUM AVAILABLE.');
    }

    public function test_113_must_be_the_exact_second_stakeholder_post(): void
    {
        $attributes = ApiRequestLog::query()->findOrFail(113)->getAttributes();
        unset($attributes['id'], $attributes['created_at']);
        ApiRequestLog::query()->forceCreate($attributes);
        $this->assertAuditHolds();
    }

    public function test_run_requires_separate_human_approval(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Separate human approval');
        $this->runner()->run();
    }

    public function test_success_is_claimed_before_one_post_and_second_run_is_blocked(): void
    {
        $result = $this->runMocked(200, [
            'kycStatus' => 'initiated',
            'kycMode' => 'MANUAL_KYC',
            'entityType' => 'INDIVIDUAL_STAKEHOLDER',
            'referenceId' => self::STAKEHOLDER_REFERENCE,
        ]);

        $this->assertSame('PASS_KYC_INITIATED', $result['terminal']);
        $this->assertExecutionClosed('initiated');
    }

    public function test_http_400_is_rejected_without_retry(): void
    {
        $result = $this->runMocked(400, ['error_code' => 'invalid_input']);

        $this->assertSame('STOP_KYC_REJECTED_NO_RETRY', $result['terminal']);
        $this->assertExecutionClosed('rejected');
    }

    public function test_transport_exception_is_unknown_without_retry(): void
    {
        $before = $this->immutableEvidence();
        $calls = new class { public int $count = 0; };
        $this->mock(NiumService::class, function (MockInterface $mock) use ($calls): void {
            $mock->shouldReceive('clientId')->andReturn('safe-test-client');
            $mock->shouldReceive('path')->andReturn('/safe/submitKyc');
            $mock->shouldReceive('post')->once()->andReturnUsing(function () use ($calls): never {
                $calls->count++;
                $this->assertSame('submitting', $this->g3ClaimState());
                throw new ConnectionException('ambiguous');
            });
        });

        $result = $this->runner()->run(true);
        $this->assertSame('STOP_KYC_OUTCOME_UNKNOWN_NO_RETRY', $result['terminal']);
        $this->assertSame(1, $calls->count);
        $this->assertExecutionClosed('unknown', 0, $before);
    }

    public function test_malformed_success_response_is_held_for_review_without_retry(): void
    {
        $result = $this->runMocked(200, ['kycStatus' => 'initiated']);

        $this->assertSame('HOLD_RESPONSE_REVIEW', $result['terminal']);
        $this->assertExecutionClosed('response_review');
    }

    private function payload(): array
    {
        return app(NiumHkSubmitKycPayloadFactory::class)->buildManual(
            KycRelatedPerson::query()->findOrFail(14),
            self::STAKEHOLDER_REFERENCE,
            KycDocument::query()->findOrFail(27),
            KycDocument::query()->findOrFail(28),
        );
    }

    private function runMocked(int $status, array $body): array
    {
        $before = $this->immutableEvidence();
        $this->mock(NiumService::class, function (MockInterface $mock) use ($status, $body): void {
            $mock->shouldReceive('clientId')->andReturn('safe-test-client');
            $mock->shouldReceive('path')->andReturn('/safe/submitKyc');
            $mock->shouldReceive('post')->once()->andReturnUsing(function () use ($status, $body): Response {
                $this->assertSame('submitting', $this->g3ClaimState());
                $this->createLog(114, self::STAKEHOLDER_REFERENCE, $status, $status < 400, $body);

                return new Response(new \GuzzleHttp\Psr7\Response($status, [], json_encode($body, JSON_THROW_ON_ERROR)));
            });
        });

        $result = $this->runner()->run(true);
        $this->assertSame(1, $result['stakeholder_generation_three_post_count']);
        $this->assertSame($before, $this->immutableEvidence());

        return $result;
    }

    private function assertExecutionClosed(string $state, int $additionalPosts = 1, ?array $before = null): void
    {
        $this->assertSame($state, $this->g3ClaimState());
        $this->assertSame($additionalPosts, ApiRequestLog::query()->where('id', '>', 113)->where('operation', 'submit_kyc')->count());
        if ($before !== null) {
            $this->assertSame($before, $this->immutableEvidence());
        }
        try {
            $this->runner()->run(true);
            $this->fail('Expected the generation #3 claim to block a second run.');
        } catch (RuntimeException $exception) {
            $this->assertSame('HOLD_GENERATION_3_ALREADY_CLAIMED', $exception->getMessage());
        }
        $this->assertSame($additionalPosts, ApiRequestLog::query()->where('id', '>', 113)->where('operation', 'submit_kyc')->count());
        $this->assertSame(0, ApiRequestLog::query()->whereIn('operation', [
            'onboarding_simulation_submit_kyc', 'file_create', 'file_details', 'van', 'payout',
        ])->count());
    }

    private function immutableEvidence(): array
    {
        return [
            'logs' => ApiRequestLog::query()->whereIn('id', [104, 106, 113])->orderBy('id')->get()->map->getRawOriginal()->all(),
            'account_4' => UserProviderAccount::query()->findOrFail(4)->getRawOriginal(),
            'documents' => KycDocument::query()->whereIn('id', [27, 28])->orderBy('id')->get()->map->getRawOriginal()->all(),
        ];
    }

    private function g3ClaimState(): ?string
    {
        return UserProviderAccount::query()->findOrFail(7)->metadata['nium_stakeholder_submit_kyc_retry_generation_3']['state'] ?? null;
    }

    private function setLegacyErrorBody(int $id, string $fingerprint): void
    {
        $log = ApiRequestLog::query()->findOrFail($id);
        $body = $log->response_body;
        unset($body['error_items']);
        $body['error_field_fingerprint'] = $fingerprint;
        $log->forceFill(['response_body' => $body])->save();
    }

    private function assertSanitizedFieldCombinationAccepted(string $topLevelField, string $itemField): void
    {
        $this->setSanitizedGenerationTwoBody($topLevelField, $itemField);

        $result = $this->runner()->audit();

        $this->assertSame('READY_FOR_SEPARATE_HUMAN_APPROVAL', $result['terminal']);
        $this->assertSame('sanitized_structured_fingerprint_113', $result['generation_two_error_evidence_mode']);
    }

    private function setSanitizedGenerationTwoBody(string $topLevelField = 'null', string $itemField = 'null'): void
    {
        $log = ApiRequestLog::query()->findOrFail(113);
        $item = [
            'error_code' => 'invalid_input',
            'error_field_fingerprint' => 'a5b7a48f01932655',
        ];
        $body = [
            'error_code' => 'invalid_input',
            'error_field_fingerprint' => 'a5b7a48f01932655',
        ];
        if ($topLevelField !== 'omit') {
            $body['error_field'] = $topLevelField === 'null' ? null : 'entityType';
        }
        if ($itemField !== 'omit') {
            $item['error_field'] = $itemField === 'null' ? null : 'entityType';
        }
        $body['error_items'] = [$item];
        $log->forceFill(['response_body' => $body])->save();
    }

    private function assertAuditHolds(?string $message = null): void
    {
        try {
            $this->runner()->audit();
            $this->fail('Expected generation #3 preflight to hold.');
        } catch (RuntimeException $exception) {
            if ($message !== null) {
                $this->assertSame($message, $exception->getMessage());
            }
            $this->addToAssertionCount(1);
        }
    }

    private function runner(): NiumHkStakeholderSubmitKycGenerationThreeOneShotRunner
    {
        return app(NiumHkStakeholderSubmitKycGenerationThreeOneShotRunner::class);
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
                'type' => 'passport', 'identification_number' => 'FACTUAL-PASSPORT-NUMBER', 'issuance_country' => 'VN',
                'expiry_date' => '2099-12-31', 'factual' => true, 'synthetic' => false,
                'source' => 'operator_verified_factual_identity_v1',
            ]]]);
        foreach ([
            ['id' => 27, 'type' => 'passport', 'file' => '30000000-0000-4000-8000-000000000027'],
            ['id' => 28, 'type' => 'bank_statement', 'file' => '30000000-0000-4000-8000-000000000028'],
        ] as $document) {
            KycDocument::query()->forceCreate(['id' => $document['id'], 'kyc_profile_id' => 9, 'kyc_related_person_id' => 14,
                'type' => $document['type'], 'status' => 'approved', 'file_url' => 'private://factual/'.$document['id'],
                'metadata' => ['document_purpose' => $document['type'], 'factual' => true, 'synthetic' => false,
                    'nium_file_id' => $document['file'], 'nium_file_state' => 'AVAILABLE']]);
        }
        UserProviderAccount::query()->forceCreate(['id' => 4, 'user_id' => 4, 'provider_id' => 1]);
        UserProviderAccount::query()->forceCreate(['id' => 7, 'user_id' => 9, 'provider_id' => 1,
            'external_customer_id' => 'customer-safe-id', 'external_account_id' => 'wallet-safe-id',
            'reconciliation_status' => 'reconciled',
            'metadata' => ['nium_stakeholder_submit_kyc_retry_generation_2' => ['state' => 'rejected']]]);
        WebhookEvent::query()->forceCreate(['provider_id' => 1, 'event_id' => 'customer-awaiting-kyc',
            'event_type' => 'CUSTOMER_STATUS_WEBHOOK', 'external_resource_id' => 'customer-safe-id',
            'payload' => ['status' => 'pending', 'subStatus' => 'awaiting_kyc'], 'processing_status' => 'processed', 'processed_at' => now()]);
        $this->createLog(104, self::APPLICANT_REFERENCE, 200, true, []);
        $this->createLog(106, self::STAKEHOLDER_REFERENCE, 400, false, $this->errorBody('entityType', 'b4753588f3f6ef2b'));
        $this->createLog(113, self::STAKEHOLDER_REFERENCE, 400, false, $this->errorBody('proofOfAddressDocument', 'a5b7a48f01932655'));
    }

    private function createLog(int $id, string $reference, int $status, bool $success, array $body): void
    {
        ApiRequestLog::query()->forceCreate(['id' => $id, 'provider_id' => 1, 'user_id' => 9, 'operation' => 'submit_kyc',
            'external_reference' => $reference, 'request_method' => 'POST', 'request_url' => '/safe/submitKyc',
            'response_status' => $status, 'transport_outcome' => 'response_received', 'is_success' => $success, 'response_body' => $body]);
    }

    private function errorBody(string $field, string $fingerprint): array
    {
        return ['error_code' => 'invalid_input', 'error_field_fingerprint' => $fingerprint, 'error_items' => [[
            'error_code' => 'invalid_input', 'error_field' => $field, 'error_field_fingerprint' => $fingerprint,
        ]]];
    }
}
