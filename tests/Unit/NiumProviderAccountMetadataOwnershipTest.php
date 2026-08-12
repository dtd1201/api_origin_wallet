<?php

namespace Tests\Unit;

use App\Services\Nium\NiumProviderAccountMetadataOwnership;
use Tests\TestCase;

class NiumProviderAccountMetadataOwnershipTest extends TestCase
{
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
