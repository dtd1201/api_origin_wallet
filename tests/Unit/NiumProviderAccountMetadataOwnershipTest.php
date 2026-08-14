<?php

namespace Tests\Unit;

use App\Services\Nium\NiumProviderAccountMetadataOwnership;
use Tests\TestCase;

class NiumProviderAccountMetadataOwnershipTest extends TestCase
{
    public function test_generation_eight_claim_preserves_only_safe_one_shot_metadata(): void
    {
        $merged = app(NiumProviderAccountMetadataOwnership::class)->merge([
            'nium_stakeholder_submit_kyc_retry_generation_8' => [
                'state' => 'rejected', 'generation' => 8, 'contract_fingerprint' => str_repeat('a', 16),
                'prebuilt_session_log_id' => 117,
                'expired_prebuilt_session_override' => 'human_verified_provider_ui_expired',
                'provider_http_status' => 400, 'transport_outcome' => 'response_received',
                'identificationNumber' => 'must-not-survive',
            ],
        ], []);

        $claim = $merged['nium_stakeholder_submit_kyc_retry_generation_8'];
        $this->assertSame(8, $claim['generation']);
        $this->assertSame(400, $claim['provider_http_status']);
        $this->assertSame('response_received', $claim['transport_outcome']);
        $this->assertArrayNotHasKey('identificationNumber', $claim);
    }

    public function test_generation_seven_claim_preserves_only_safe_execution_evidence(): void
    {
        $merged = app(NiumProviderAccountMetadataOwnership::class)->merge([
            'nium_stakeholder_submit_kyc_retry_generation_7' => [
                'state' => 'unknown', 'generation' => 7, 'contract_fingerprint' => str_repeat('a', 16),
                'prebuilt_session_log_id' => 117,
                'expired_prebuilt_session_override' => 'human_verified_provider_ui_expired',
                'provider_http_status' => 500, 'transport_outcome' => 'ambiguous',
                'request_id_fingerprint' => str_repeat('b', 16), 'identificationNumber' => 'must-not-survive',
                'updated_at' => '2026-08-13T12:00:00.000000Z',
            ],
        ], []);

        $claim = $merged['nium_stakeholder_submit_kyc_retry_generation_7'];
        $this->assertSame(7, $claim['generation']);
        $this->assertSame(117, $claim['prebuilt_session_log_id']);
        $this->assertSame('human_verified_provider_ui_expired', $claim['expired_prebuilt_session_override']);
        $this->assertSame('ambiguous', $claim['transport_outcome']);
        $this->assertArrayNotHasKey('identificationNumber', $claim);
    }

    public function test_prebuilt_form_session_preserves_only_safe_fields(): void
    {
        $merged = app(NiumProviderAccountMetadataOwnership::class)->merge([
            'nium_kyc_prebuilt_form_session' => [
                'state' => 'created', 'created_at' => '2026-08-13T12:00:00Z',
                'expiry_at' => '2026-08-13T14:00:00Z', 'session_id_fingerprint' => str_repeat('a', 16),
                'provider_http_status' => 200, 'sessionId' => 'must-not-survive',
            ],
        ], []);

        $claim = $merged['nium_kyc_prebuilt_form_session'];
        $this->assertSame('created', $claim['state']);
        $this->assertSame(str_repeat('a', 16), $claim['session_id_fingerprint']);
        $this->assertArrayNotHasKey('sessionId', $claim);
    }

    public function test_prebuilt_form_session_generation_two_preserves_only_safe_fields(): void
    {
        $merged = app(NiumProviderAccountMetadataOwnership::class)->merge([
            'nium_kyc_prebuilt_form_session_generation_2' => [
                'generation' => 2, 'state' => 'created',
                'session_id_fingerprint' => str_repeat('a', 16),
                'created_at' => '2026-08-14T01:00:00Z', 'expiry_at' => '2026-08-14T03:00:00Z',
                'provider_http_status' => 200, 'transport_outcome' => 'response_received',
                'previous_session_log_id' => 117,
                'recovery_reason' => 'expired_after_ekyc_redirect_configuration_blocker',
                'sessionId' => 'must-not-survive', 'url' => 'must-not-survive',
            ],
        ], []);

        $claim = $merged['nium_kyc_prebuilt_form_session_generation_2'];
        $this->assertSame(2, $claim['generation']);
        $this->assertSame(117, $claim['previous_session_log_id']);
        $this->assertSame('expired_after_ekyc_redirect_configuration_blocker', $claim['recovery_reason']);
        $this->assertArrayNotHasKey('sessionId', $claim);
        $this->assertArrayNotHasKey('url', $claim);
    }

    public function test_customer_onboarding_rfi_session_preserves_only_safe_fields(): void
    {
        $merged = app(NiumProviderAccountMetadataOwnership::class)->merge([
            'nium_customer_onboarding_rfi_prebuilt_session' => [
                'generation' => 1, 'state' => 'created', 'feature_type' => 'customer_onboarding_rfi',
                'session_id_fingerprint' => str_repeat('a', 16), 'provider_http_status' => 200,
                'transport_outcome' => 'response_received', 'created_at' => '2026-08-14T01:00:00Z',
                'expiry_at' => '2026-08-14T03:00:00Z', 'updated_at' => '2026-08-14T01:00:01Z',
                'sessionId' => 'must-not-survive', 'answers' => ['secret'], 'url' => 'must-not-survive',
            ],
        ], []);

        $claim = $merged['nium_customer_onboarding_rfi_prebuilt_session'];
        $this->assertSame('customer_onboarding_rfi', $claim['feature_type']);
        $this->assertArrayNotHasKey('sessionId', $claim);
        $this->assertArrayNotHasKey('answers', $claim);
        $this->assertArrayNotHasKey('url', $claim);
    }

    public function test_assign_payment_id_one_shot_preserves_only_safe_fields(): void
    {
        $merged = app(NiumProviderAccountMetadataOwnership::class)->merge([
            'nium_assign_payment_id_one_shot_v1' => [
                'version' => 1, 'state' => 'assigned', 'currency_code' => 'USD',
                'account_category' => 'COLLECTION_ACCOUNT',
                'bank_name_fingerprint' => str_repeat('b', 16), 'payload_fingerprint' => str_repeat('a', 16),
                'provider_http_status' => 200, 'transport_outcome' => 'response_received',
                'api_request_log_id' => 120, 'created_at' => '2026-08-14T01:00:00Z',
                'updated_at' => '2026-08-14T01:00:01Z', 'customerHashId' => 'must-not-survive',
                'walletHashId' => 'must-not-survive', 'raw_response' => ['secret'],
            ],
        ], []);

        $claim = $merged['nium_assign_payment_id_one_shot_v1'];
        $this->assertSame(1, $claim['version']);
        $this->assertSame('USD', $claim['currency_code']);
        $this->assertArrayNotHasKey('account_type', $claim);
        $this->assertArrayNotHasKey('customerHashId', $claim);
        $this->assertArrayNotHasKey('walletHashId', $claim);
        $this->assertArrayNotHasKey('raw_response', $claim);
    }

    public function test_generation_six_claim_preserves_only_safe_provenance(): void
    {
        $merged = app(NiumProviderAccountMetadataOwnership::class)->merge([
            'nium_stakeholder_submit_kyc_retry_generation_6' => [
                'state' => 'submitting', 'previous_log_id' => 116, 'previous_http_status' => 400,
                'previous_error_field_count' => 1, 'previous_error_code' => 'invalid_input',
                'updated_at' => '2026-08-13T12:00:00.000000Z',
            ],
        ], []);

        $claim = $merged['nium_stakeholder_submit_kyc_retry_generation_6'];
        $this->assertSame(116, $claim['previous_log_id']);
        $this->assertSame(1, $claim['previous_error_field_count']);
        $this->assertArrayNotHasKey('previous_error_code', $claim);
    }

    public function test_generation_five_claim_preserves_only_safe_provenance(): void
    {
        $merged = app(NiumProviderAccountMetadataOwnership::class)->merge([
            'nium_stakeholder_submit_kyc_retry_generation_5' => [
                'state' => 'submitting',
                'previous_log_id' => 115,
                'previous_http_status' => 400,
                'previous_error_field_count' => 1,
                'previous_error_code' => 'invalid_input',
                'updated_at' => '2026-08-13T12:00:00.000000Z',
            ],
        ], []);

        $claim = $merged['nium_stakeholder_submit_kyc_retry_generation_5'];
        $this->assertSame(115, $claim['previous_log_id']);
        $this->assertSame(1, $claim['previous_error_field_count']);
        $this->assertArrayNotHasKey('previous_error_code', $claim);
    }

    public function test_generation_four_claim_preserves_only_proven_provenance(): void
    {
        $merged = app(NiumProviderAccountMetadataOwnership::class)->merge([
            'nium_stakeholder_submit_kyc_retry_generation_4' => [
                'state' => 'submitting',
                'previous_log_id' => 114,
                'previous_http_status' => 400,
                'previous_error_field_count' => 3,
                'previous_error_code' => 'invalid_input',
                'updated_at' => '2026-08-12T12:00:00.000000Z',
            ],
        ], []);

        $claim = $merged['nium_stakeholder_submit_kyc_retry_generation_4'];
        $this->assertSame('submitting', $claim['state']);
        $this->assertSame(114, $claim['previous_log_id']);
        $this->assertSame(400, $claim['previous_http_status']);
        $this->assertSame(3, $claim['previous_error_field_count']);
        $this->assertArrayNotHasKey('previous_error_code', $claim);
    }

    public function test_only_valid_local_owned_metadata_is_preserved_and_provider_projection_wins(): void
    {
        $key = 'ref_'.str_repeat('a', 16);
        $merged = app(NiumProviderAccountMetadataOwnership::class)->merge([
            'integration_status' => 'local_override',
            'unknown_metadata' => ['raw' => 'must-not-survive'],
            'nium_submit_kyc_attempts' => [
                $key => [
                    'state' => 'response_review',
                    'kyc_mode' => 'biometric_kyc',
                    'provider_http_status' => 200,
                    'identificationNumber' => 'TEST-PII-MUST-NOT-SURVIVE',
                ],
                'untrusted-key' => ['state' => 'response_review'],
            ],
            'nium_sandbox_simulation_submit_kyc_attempt' => [
                'state' => 'submitting',
                'updated_at' => '2026-08-11T08:00:00.000000Z',
                'secret' => 'must-not-survive',
            ],
        ], [
            'integration_status' => 'nium_pending_awaiting_kyc',
            'nium_last_state_source' => 'nium_webhook_notification:customer_status_webhook',
        ]);

        $this->assertSame('nium_pending_awaiting_kyc', $merged['integration_status']);
        $this->assertSame('response_review', $merged['nium_submit_kyc_attempts'][$key]['state']);
        $this->assertSame('submitting', $merged['nium_sandbox_simulation_submit_kyc_attempt']['state']);
        $this->assertArrayNotHasKey('unknown_metadata', $merged);
        $this->assertArrayNotHasKey('untrusted-key', $merged['nium_submit_kyc_attempts']);

        $serialized = json_encode($merged, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('TEST-PII-MUST-NOT-SURVIVE', $serialized);
        $this->assertStringNotContainsString('must-not-survive', $serialized);
        $this->assertStringNotContainsString('local_override', $serialized);
    }
}
