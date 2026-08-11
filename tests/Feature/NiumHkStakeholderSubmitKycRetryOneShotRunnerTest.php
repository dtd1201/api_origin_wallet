<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\KycProfile;
use App\Models\KycRelatedPerson;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use App\Services\Nium\NiumHkStakeholderSubmitKycRetryOneShotRunner;
use App\Services\Nium\NiumProviderAccountMetadataOwnership;
use App\Services\Nium\NiumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class NiumHkStakeholderSubmitKycRetryOneShotRunnerTest extends TestCase
{
    use RefreshDatabase;

    private const APPLICANT_REFERENCE = 'c620e0e9-ab0a-43bd-aa10-44f573db723a';
    private const STAKEHOLDER_REFERENCE = '7609d9d1-9d37-4e08-9197-602d792f7a2e';
    private const IDENTITY = 'FACTUAL-PASSPORT-NUMBER';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.nium.confirmed_hk_stakeholder_entity_type' => 'individual_stakeholder',
            'services.nium.confirmed_hk_stakeholder_kyc_mode' => 'biometric_kyc',
        ]);
        $this->seedEvidence();
        $this->mock(NiumService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('post'));
    }

    public function test_absent_provider_confirmed_entity_type_holds_before_http(): void
    {
        config()->set('services.nium.confirmed_hk_stakeholder_entity_type');
        $this->assertAuditHolds();
    }

    public function test_absent_provider_confirmed_kyc_mode_holds_before_http(): void
    {
        config()->set('services.nium.confirmed_hk_stakeholder_kyc_mode');
        $this->assertAuditHolds();
    }

    public function test_wrong_previous_log_id_holds_before_http(): void
    {
        ApiRequestLog::query()->findOrFail(106)->forceFill(['id' => 107])->save();
        $this->assertAuditHolds();
    }

    public function test_previous_http_other_than_400_holds_before_http(): void
    {
        ApiRequestLog::query()->findOrFail(106)->forceFill(['response_status' => 422])->save();
        $this->assertAuditHolds();
    }

    public function test_previous_error_field_other_than_entity_type_holds_before_http(): void
    {
        $log = ApiRequestLog::query()->findOrFail(106);
        $body = $log->response_body;
        $body['error_items'][0]['error_field'] = 'kycMode';
        $log->forceFill(['response_body' => $body])->save();
        $this->assertAuditHolds();
    }

    public function test_prior_generation_two_claim_holds_before_http(): void
    {
        $account = UserProviderAccount::query()->findOrFail(7);
        $metadata = $account->metadata;
        $metadata['nium_stakeholder_submit_kyc_retry_generation_2'] = ['state' => 'rejected'];
        $account->forceFill(['metadata' => $metadata])->save();
        $this->assertAuditHolds();
    }

    public function test_applicant_state_missing_holds_before_http(): void
    {
        $account = UserProviderAccount::query()->findOrFail(7);
        $metadata = $account->metadata;
        unset($metadata['nium_submit_kyc_attempts'][$this->attemptKey(self::APPLICANT_REFERENCE)]);
        $account->forceFill(['metadata' => $metadata])->save();
        $this->assertAuditHolds();
    }

    public function test_customer_not_awaiting_kyc_holds_before_http(): void
    {
        $event = WebhookEvent::query()->where('event_type', 'CUSTOMER_STATUS_WEBHOOK')->sole();
        $event->forceFill(['payload' => ['status' => 'pending', 'subStatus' => 'under_review']])->save();
        $this->assertAuditHolds();
    }

    public function test_factual_identity_mismatch_holds_before_http(): void
    {
        $person = KycRelatedPerson::query()->findOrFail(14);
        $metadata = $person->metadata;
        $metadata['nium_biometric_identity']['factual'] = false;
        $person->forceFill(['metadata' => $metadata])->save();
        $this->assertAuditHolds();
    }

    public function test_valid_confirmed_values_pass_offline_only(): void
    {
        $result = $this->runner()->audit();

        $this->assertSame('READY_FOR_SEPARATE_HUMAN_APPROVAL', $result['terminal']);
        $this->assertSame(106, $result['previous_log_id']);
        $this->assertSame(0, $result['stakeholder_retry_post_count']);
        $this->assertDatabaseCount('api_request_logs', 1);
    }

    public function test_run_without_separate_human_approval_holds_before_http(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Separate human approval');

        $this->runner()->run();
    }

    public function test_more_than_one_previous_scoped_post_holds_generation_two(): void
    {
        $attributes = ApiRequestLog::query()->findOrFail(106)->getAttributes();
        unset($attributes['id'], $attributes['created_at']);
        ApiRequestLog::query()->forceCreate($attributes);
        $this->assertAuditHolds(expectedLogCount: 2);
    }

    public function test_rejected_generation_two_claim_cannot_be_retried(): void
    {
        $this->setRetryClaim('rejected');
        $this->assertAuditHolds();
    }

    public function test_unknown_generation_two_claim_cannot_be_retried(): void
    {
        $this->setRetryClaim('unknown');
        $this->assertAuditHolds();
    }

    public function test_manual_kyc_without_factual_required_documents_holds(): void
    {
        config()->set('services.nium.confirmed_hk_stakeholder_kyc_mode', 'manual_kyc');
        $this->assertAuditHolds();
    }

    public function test_account_four_is_byte_identical_after_offline_audit(): void
    {
        $before = UserProviderAccount::query()->findOrFail(4)->getRawOriginal();
        $this->runner()->audit();
        $this->assertSame($before, UserProviderAccount::query()->findOrFail(4)->getRawOriginal());
    }

    public function test_generation_two_metadata_ownership_preserves_only_safe_provenance(): void
    {
        $projection = app(NiumProviderAccountMetadataOwnership::class)->merge([
            'nium_stakeholder_submit_kyc_retry_generation_2' => [
                'state' => 'submitting',
                'previous_log_id' => 106,
                'previous_http_status' => 400,
                'previous_error_code' => 'invalid_input',
                'previous_error_field' => 'entityType',
                'previous_error_field_fingerprint' => 'b4753588f3f6ef2b',
                'confirmed_entity_type' => 'individual_stakeholder',
                'confirmed_kyc_mode' => 'biometric_kyc',
                'identification_number' => self::IDENTITY,
                'updated_at' => '2026-08-11T00:00:00.000000Z',
            ],
        ], []);

        $serialized = json_encode($projection, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::IDENTITY, $serialized);
        $this->assertStringNotContainsString('identification_number', $serialized);
        $this->assertSame(
            'entityType',
            $projection['nium_stakeholder_submit_kyc_retry_generation_2']['previous_error_field'],
        );
    }

    private function assertAuditHolds(int $expectedLogCount = 1): void
    {
        try {
            $this->runner()->audit();
            $this->fail('Expected generation #2 offline preflight to hold.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertDatabaseCount('api_request_logs', $expectedLogCount);
    }

    private function setRetryClaim(string $state): void
    {
        $account = UserProviderAccount::query()->findOrFail(7);
        $metadata = $account->metadata;
        $metadata['nium_stakeholder_submit_kyc_retry_generation_2'] = ['state' => $state];
        $account->forceFill(['metadata' => $metadata])->save();
    }

    private function runner(): NiumHkStakeholderSubmitKycRetryOneShotRunner
    {
        return app(NiumHkStakeholderSubmitKycRetryOneShotRunner::class);
    }

    private function seedEvidence(): void
    {
        IntegrationProvider::query()->forceCreate(['id' => 1, 'code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        User::factory()->create(['id' => 4]);
        User::factory()->create(['id' => 9]);
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
        KycRelatedPerson::query()->forceCreate([
            'id' => 14,
            'kyc_profile_id' => 9,
            'relationship_type' => 'beneficial_owner',
            'status' => 'approved',
            'legal_name' => 'Placeholder Person',
            'metadata' => ['nium_biometric_identity' => [
                'type' => 'passport',
                'identification_number' => self::IDENTITY,
                'issuance_country' => 'VN',
                'expiry_date' => '2099-12-31',
                'factual' => true,
                'synthetic' => false,
                'source' => 'operator_verified_factual_identity_v1',
            ]],
        ]);
        UserProviderAccount::query()->forceCreate(['id' => 4, 'user_id' => 4, 'provider_id' => 1]);
        UserProviderAccount::query()->forceCreate([
            'id' => 7,
            'user_id' => 9,
            'provider_id' => 1,
            'external_customer_id' => 'customer-safe-id',
            'external_account_id' => 'wallet-safe-id',
            'reconciliation_status' => 'reconciled',
            'metadata' => ['nium_submit_kyc_attempts' => [
                $this->attemptKey(self::APPLICANT_REFERENCE) => ['state' => 'provider_accepted_200_sandbox_review'],
                $this->attemptKey(self::STAKEHOLDER_REFERENCE) => ['state' => 'rejected'],
            ]],
        ]);
        WebhookEvent::query()->forceCreate([
            'provider_id' => 1,
            'event_id' => 'customer-awaiting-kyc',
            'event_type' => 'CUSTOMER_STATUS_WEBHOOK',
            'external_resource_id' => 'customer-safe-id',
            'payload' => ['status' => 'pending', 'subStatus' => 'awaiting_kyc'],
            'processing_status' => 'processed',
            'processed_at' => now(),
        ]);
        ApiRequestLog::query()->forceCreate([
            'id' => 106,
            'provider_id' => 1,
            'user_id' => 9,
            'operation' => 'submit_kyc',
            'external_reference' => self::STAKEHOLDER_REFERENCE,
            'request_method' => 'POST',
            'request_url' => '/safe/submitKyc',
            'response_status' => 400,
            'transport_outcome' => 'response_received',
            'is_success' => false,
            'response_body' => [
                'http_status' => 400,
                'error_code' => 'invalid_input',
                'error_field_fingerprint' => 'b4753588f3f6ef2b',
                'error_items' => [[
                    'error_code' => 'invalid_input',
                    'error_field' => 'entityType',
                    'error_field_fingerprint' => 'b4753588f3f6ef2b',
                    'error_description_fingerprint' => str_repeat('a', 16),
                ]],
            ],
        ]);
    }

    private function attemptKey(string $referenceId): string
    {
        return 'ref_'.substr(hash('sha256', $referenceId), 0, 16);
    }
}
