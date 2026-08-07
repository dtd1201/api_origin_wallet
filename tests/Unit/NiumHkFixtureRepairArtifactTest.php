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
    }
}
