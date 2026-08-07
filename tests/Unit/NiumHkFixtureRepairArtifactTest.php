<?php

namespace Tests\Unit;

use Tests\TestCase;

class NiumHkFixtureRepairArtifactTest extends TestCase
{
    public function test_repair_remains_blocked_while_document_semantics_are_unproven(): void
    {
        $repair = file_get_contents(base_path('scripts/nium/fixture_v4_hk_repair_proposed.sql'));
        $diagnostic = file_get_contents(base_path('scripts/nium/fixture_v4_hk_diagnostic.sql'));

        $this->assertIsString($repair);
        $this->assertIsString($diagnostic);
        $this->assertStringContainsString("\\set document_semantic_preflight 'UNPROVEN'", $repair);
        $this->assertStringContainsString('PROVEN_AND_METADATA_ALREADY_ACCURATE', $repair);
        $this->assertStringContainsString('Documents 18, 19, or 20 changed', $repair);
        $this->assertStringNotContainsString('UPDATE kyc_documents', $repair);
        $this->assertStringContainsString('safe_content_hash_if_retained', $diagnostic);
        $this->assertStringContainsString('customer_payload_reference', $diagnostic);
        $this->assertStringContainsString("'authorized_representative'", $diagnostic);
        $this->assertStringContainsString("'authorised_representative'", $diagnostic);
    }

    public function test_new_document_record_preparation_is_additive_and_excludes_historical_rows(): void
    {
        $preparation = file_get_contents(base_path('scripts/nium/fixture_v5_hk_document_records_prepare_proposed.sql'));
        $diagnostic = file_get_contents(base_path('scripts/nium/fixture_v5_hk_document_records_diagnostic.sql'));
        $runner = file_get_contents(base_path('scripts/nium/run_hk_sandbox_file_stage.php'));

        $this->assertIsString($preparation);
        $this->assertIsString($diagnostic);
        $this->assertIsString($runner);
        $this->assertStringContainsString('INSERT INTO kyc_documents', $preparation);
        $this->assertStringNotContainsString('UPDATE kyc_documents', $preparation);
        $this->assertStringNotContainsString('DELETE FROM kyc_documents', $preparation);
        $this->assertStringContainsString('kd.id IN (18, 19, 20)', $preparation);
        $this->assertStringContainsString("metadata ? 'nium_file_id'", $preparation);
        $this->assertStringContainsString("ip.code = 'nium'", $preparation);
        $this->assertStringContainsString('arl.user_id = 9', $preparation);
        $this->assertStringContainsString('arl.user_id = 8', $preparation);
        $this->assertStringContainsString('user_8_customer_posts_before', $preparation);
        $this->assertStringContainsString("ip.code = 'nium'", $diagnostic);
        $this->assertStringContainsString('arl.user_id = 9', $diagnostic);
        $this->assertStringContainsString('global_customer_create_post_count', $diagnostic);
        $this->assertStringNotContainsString('NiumCustomerOnboardingService', $runner);
        $this->assertStringNotContainsString('PaymentId', $runner);
        $this->assertStringNotContainsString('Beneficiary', $runner);
        $this->assertStringNotContainsString('Transfer', $runner);
    }
}
