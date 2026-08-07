<?php

namespace Tests\Unit;

use Illuminate\Support\Str;
use Tests\TestCase;

require_once __DIR__.'/../../scripts/nium/generate_hk_sandbox_documents.php';

class NiumHkSandboxDocumentFixtureTest extends TestCase
{
    public function test_generated_documents_are_deterministic_visible_nontrivial_sandbox_artifacts(): void
    {
        $firstDirectory = sys_get_temp_dir().'/nium-hk-fixture-'.Str::uuid();
        $secondDirectory = sys_get_temp_dir().'/nium-hk-fixture-'.Str::uuid();
        $first = generateNiumHkSandboxDocuments($firstDirectory);
        $second = generateNiumHkSandboxDocuments($secondDirectory);
        $firstManifest = file_get_contents($first['manifest_path']);
        $secondManifest = file_get_contents($second['manifest_path']);

        $this->assertSame(3, count($first['generated_artifacts']));
        $this->assertSame($firstManifest, $secondManifest);
        $this->assertSame(hash('sha256', (string) $firstManifest), hash('sha256', (string) $secondManifest));
        $this->assertSame(
            array_column($first['generated_artifacts'], 'sha256'),
            array_column($second['generated_artifacts'], 'sha256'),
        );

        foreach ($first['runtime_artifacts'] as $artifact) {
            $bytes = file_get_contents($artifact['external_local_path']);

            $this->assertIsString($bytes);
            $this->assertStringStartsWith('%PDF-1.4', $bytes);
            $this->assertStringContainsString('SANDBOX TEST ONLY', $bytes);
            $this->assertStringContainsString('NOT A REAL DOCUMENT', $bytes);
            $this->assertGreaterThan(69, $artifact['byte_size']);
            $this->assertSame('application/pdf', $artifact['mime_type']);
            $this->assertSame('application/pdf', (new \finfo(FILEINFO_MIME_TYPE))->file($artifact['external_local_path']));
            $this->assertSame(['width' => 612, 'height' => 792], $artifact['page_dimensions_points']);
            $this->assertSame(hash('sha256', $bytes), $artifact['sha256']);
            $this->assertTrue($artifact['visibly_test_only']);
            $this->assertSame('HK', $artifact['target_region']);
        }

        $this->assertSame([
            '68e006d3f97f33b24e5ced1a07aaa4ff970270acba6fcee05e7658814a57822a',
            '310f7f2716bf6945d4591e459e13449df2f41e044487ff4c3f36b97228f397a2',
            'd4b5d6945d047f8a892c7cb93694e37c2dd6efb98b8f63e86b18394f0c2ad953',
        ], array_column($first['generated_artifacts'], 'sha256'));

        foreach ($first['generated_artifacts'] as $artifact) {
            $this->assertArrayNotHasKey('external_local_path', $artifact);
            $this->assertArrayHasKey('artifact_filename', $artifact);
        }
    }

    public function test_manifest_and_upload_plan_are_safe_and_exclude_historical_documents(): void
    {
        $directory = sys_get_temp_dir().'/nium-hk-manifest-'.Str::uuid();
        $manifest = generateNiumHkSandboxDocuments($directory);
        $manifestJson = file_get_contents($manifest['manifest_path']);
        $planJson = file_get_contents(base_path('scripts/nium/hk_sandbox_file_upload_plan.json'));
        $plan = json_decode((string) $planJson, true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsString($manifestJson);
        $this->assertIsString($planJson);
        $this->assertSame([
            'corporate_registration',
            'applicant_authorized_person_identity',
            'beneficial_owner_stakeholder_identity',
        ], array_column($manifest['generated_artifacts'], 'logical_role'));
        $this->assertSame([18, 19, 20], $plan['historical_document_ids_excluded']);
        $this->assertSame([18, 19, 20], $plan['future_fixture_selection']['reject_document_ids']);
        $this->assertFalse($plan['automatic_retry']);
        $this->assertFalse($plan['customer_create_allowed']);
        $this->assertSame('STOP_WITHOUT_RETRY', $plan['ambiguous_outcome_policy']);

        foreach ($plan['documents'] as $document) {
            $this->assertSame(
                ['CREATE_FILE_ONCE', 'GET_FILE_DETAILS_ONCE_AFTER_CREATE_EVIDENCE'],
                $document['operations'],
            );
        }

        $safeSerialization = $manifestJson."\n".$planJson;
        $this->assertDoesNotMatchRegularExpression('/clientHashId|api[_-]?key|customerHashId|walletHashId/i', $safeSerialization);
        $this->assertDoesNotMatchRegularExpression('/5dde122a|6afef4a|9cd49d73/', $safeSerialization);
        $this->assertDoesNotMatchRegularExpression('/passport number|identity number|date of birth|real address/i', $manifestJson);
    }
}
