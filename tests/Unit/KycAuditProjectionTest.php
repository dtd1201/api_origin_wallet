<?php

namespace Tests\Unit;

use App\Models\KycDocument;
use App\Models\KycProfile;
use App\Models\KycProviderSubmission;
use App\Support\KycAuditProjection;
use Tests\TestCase;

class KycAuditProjectionTest extends TestCase
{
    public function test_profile_projection_never_serializes_sensitive_model_values(): void
    {
        $sentinels = ['SENSITIVE NAME', 'secret@example.test', '+85299998888', 'P1234567', 'BANK-9988', 'nium-file-secret'];
        $profile = new KycProfile(['status' => 'verified', 'legal_name' => $sentinels[0], 'metadata' => ['email' => $sentinels[1], 'bank' => $sentinels[4]]]);
        $profile->id = 41;
        $profile->user_id = 12;
        $document = new KycDocument(['type' => 'passport_front', 'status' => 'approved', 'document_number' => $sentinels[3], 'metadata' => ['nium_file_id' => $sentinels[5]]]);
        $document->id = 91;
        $profile->setRelation('documents', collect([$document]));
        $profile->setRelation('relatedPersons', collect());
        $profile->setRelation('requirements', collect());

        $serialized = json_encode(KycAuditProjection::profile($profile, 'submitted', ['status']), JSON_THROW_ON_ERROR);

        foreach ($sentinels as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $serialized);
        }
        $this->assertStringContainsString('passport_front', $serialized);
        $this->assertStringContainsString('submitted', $serialized);
        $this->assertStringContainsString('verified', $serialized);
    }

    public function test_provider_projection_uses_only_local_record_ids_and_statuses(): void
    {
        $submission = new KycProviderSubmission([
            'status' => 'failed',
            'metadata' => ['external_customer_id' => 'customer-secret', 'nium_file_id' => 'file-secret'],
        ]);
        $submission->id = 7;
        $submission->kyc_profile_id = 8;
        $submission->provider_id = 9;
        $submission->provider_account_id = 10;

        $serialized = json_encode(KycAuditProjection::providerSubmission($submission, 'pending', 'validation_failed'), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('customer-secret', $serialized);
        $this->assertStringNotContainsString('file-secret', $serialized);
    }
}
