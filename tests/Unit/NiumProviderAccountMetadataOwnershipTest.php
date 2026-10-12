<?php

namespace Tests\Unit;

use App\Services\Nium\NiumProviderAccountMetadataOwnership;
use Tests\TestCase;

class NiumProviderAccountMetadataOwnershipTest extends TestCase
{
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
                    'biometric_url' => 'https://idv.nium.test/protected-session',
                    'biometric_url_fingerprint' => str_repeat('b', 16),
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
        $this->assertSame(
            'https://idv.nium.test/protected-session',
            $merged['nium_submit_kyc_attempts'][$key]['biometric_url'],
        );
        $this->assertSame('submitting', $merged['nium_sandbox_simulation_submit_kyc_attempt']['state']);
        $this->assertArrayNotHasKey('unknown_metadata', $merged);
        $this->assertArrayNotHasKey('untrusted-key', $merged['nium_submit_kyc_attempts']);

        $serialized = json_encode($merged, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('TEST-PII-MUST-NOT-SURVIVE', $serialized);
        $this->assertStringNotContainsString('must-not-survive', $serialized);
        $this->assertStringNotContainsString('local_override', $serialized);
    }

    public function test_manual_and_entity_attempts_survive_projection_with_strict_fields_and_keys(): void
    {
        $manualKey = 'manual_'.str_repeat('a', 24);
        $entityKey = 'entity_'.str_repeat('b', 24);
        $merged = app(NiumProviderAccountMetadataOwnership::class)->merge([
            'nium_submit_kyc_attempts' => [
                $manualKey => [
                    'state' => 'accepted',
                    'kyc_mode' => 'biometric_kyc',
                    'kyc_status' => 'initiated',
                    'manual_reference_id' => 'origin-wallet-manual-synthetic',
                    'manual_admin_action' => true,
                    'updated_at' => '2026-10-12T01:02:03Z',
                ],
                $entityKey => [
                    'state' => 'accepted',
                    'entity_type' => 'applicant',
                    'external_id' => 'origin-wallet-applicant-42',
                    'updated_at' => '2026-10-12T01:02:04Z',
                ],
                'manual_'.str_repeat('c', 23) => ['state' => 'accepted'],
                'manual_'.str_repeat('G', 24) => ['state' => 'accepted'],
                'ref_'.str_repeat('d', 15) => ['state' => 'accepted'],
            ],
        ], []);

        $this->assertSame('initiated', $merged['nium_submit_kyc_attempts'][$manualKey]['kyc_status']);
        $this->assertSame('origin-wallet-manual-synthetic', $merged['nium_submit_kyc_attempts'][$manualKey]['manual_reference_id']);
        $this->assertTrue($merged['nium_submit_kyc_attempts'][$manualKey]['manual_admin_action']);
        $this->assertSame('origin-wallet-applicant-42', $merged['nium_submit_kyc_attempts'][$entityKey]['external_id']);
        $this->assertCount(2, $merged['nium_submit_kyc_attempts']);
    }
}
