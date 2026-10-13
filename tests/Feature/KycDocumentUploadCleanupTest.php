<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\KycDocumentUpload;
use App\Models\User;
use App\Services\Kyc\KycDocumentUploadCleanupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KycDocumentUploadCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_but_does_not_delete_an_expired_unreferenced_upload(): void
    {
        Storage::fake('kyc_private');
        $upload = $this->upload(now()->subMinute());
        $result = app(KycDocumentUploadCleanupService::class)->cleanup();

        $this->assertSame(1, $result['eligible']);
        Storage::disk('kyc_private')->assertExists($upload->file_path);
        $this->assertSame('uploaded', $upload->fresh()->status);
        $serialized = json_encode(AuditLog::query()->where('action', 'kyc.document_upload_cleanup')->sole()->new_data, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($upload->file_path, $serialized);
        $this->assertStringNotContainsString($upload->file_hash, $serialized);
    }

    public function test_execute_deletes_only_expired_unreferenced_registry_files(): void
    {
        Storage::fake('kyc_private');
        $stale = $this->upload(now()->subMinute());
        $fresh = $this->upload(now()->addDay());
        $this->artisan('kyc:cleanup-document-uploads --execute')->assertSuccessful();

        Storage::disk('kyc_private')->assertMissing($stale->file_path);
        Storage::disk('kyc_private')->assertExists($fresh->file_path);
        $this->assertSame('deleted', $stale->fresh()->status);
        $this->assertSame('uploaded', $fresh->fresh()->status);
    }

    public function test_live_kyc_and_registry_references_are_never_deleted(): void
    {
        Storage::fake('kyc_private');
        $live = $this->upload(now()->subMinute());
        $profile = User::query()->findOrFail($live->user_id)->kycProfile()->create([
            'status' => 'draft', 'applicant_type' => 'individual', 'legal_name' => 'Cleanup Test User',
            'address_line1' => '1 Test Road', 'city' => 'Test City', 'country_code' => 'HK',
        ]);
        DB::table('kyc_documents')->insert([
            'kyc_profile_id' => $profile->id, 'type' => 'passport', 'file_url' => 'private',
            'storage_disk' => $live->storage_disk, 'file_path' => $live->file_path,
            'file_hash' => $live->file_hash, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $referenced = $this->upload(now()->subMinute());
        $referenced->update(['status' => 'referenced', 'expires_at' => null, 'referenced_at' => now()]);

        $result = app(KycDocumentUploadCleanupService::class)->cleanup(false);

        $this->assertSame(1, $result['retained']);
        Storage::disk('kyc_private')->assertExists($live->file_path);
        Storage::disk('kyc_private')->assertExists($referenced->file_path);
        $this->assertSame('referenced', $live->fresh()->status);
    }

    public function test_non_uploaded_row_is_a_concurrency_barrier_and_cross_user_paths_remain_isolated(): void
    {
        Storage::fake('kyc_private');
        $deleting = $this->upload(now()->subMinute());
        $deleting->update(['status' => 'deleting']);
        $other = $this->upload(now()->subMinute(), User::factory()->create());
        $result = app(KycDocumentUploadCleanupService::class)->cleanup(false);

        Storage::disk('kyc_private')->assertExists($deleting->file_path);
        Storage::disk('kyc_private')->assertMissing($other->file_path);
        $this->assertSame(1, $result['deleted']);
        $this->assertStringContainsString("kyc/{$other->user_id}/documents/", $other->file_path);
    }

    public function test_stale_incomplete_upload_is_cleaned_but_an_active_upload_is_not_selected(): void
    {
        Storage::fake('kyc_private');
        $stale = $this->upload(now()->subMinute());
        $stale->update(['status' => 'uploading']);
        $active = $this->upload(now()->addDay());
        $active->update(['status' => 'uploading']);

        $result = app(KycDocumentUploadCleanupService::class)->cleanup(false);

        $this->assertSame(1, $result['deleted']);
        Storage::disk('kyc_private')->assertMissing($stale->file_path);
        Storage::disk('kyc_private')->assertExists($active->file_path);
        $this->assertSame('uploading', $active->fresh()->status);
    }

    public function test_registry_integrity_mismatch_is_retained_when_safety_cannot_be_proven(): void
    {
        Storage::fake('kyc_private');
        $upload = $this->upload(now()->subMinute());
        Storage::disk('kyc_private')->put($upload->file_path, 'replacement content');

        $result = app(KycDocumentUploadCleanupService::class)->cleanup(false);

        $this->assertSame(1, $result['retained']);
        Storage::disk('kyc_private')->assertExists($upload->file_path);
        $this->assertSame('retained', $upload->fresh()->status);
    }

    public function test_submit_claim_prevents_cleanup_from_deleting_the_same_upload(): void
    {
        Storage::fake('kyc_private');
        $upload = $this->upload(now()->subMinute());
        $upload->update([
            'status' => 'referenced', 'referenced_at' => now(), 'expires_at' => null,
        ]);

        $result = app(KycDocumentUploadCleanupService::class)->cleanup(false);

        $this->assertSame(0, $result['examined']);
        Storage::disk('kyc_private')->assertExists($upload->file_path);
    }

    private function upload($expiresAt, ?User $user = null): KycDocumentUpload
    {
        $user ??= User::factory()->create();
        $contents = "%PDF-1.4\n{$user->id}-".uniqid('', true)."\n%%EOF";
        $hash = hash('sha256', $contents);
        $path = "kyc/{$user->id}/documents/{$hash}-passport.pdf";
        Storage::disk('kyc_private')->put($path, $contents);

        return KycDocumentUpload::query()->create([
            'user_id' => $user->id, 'storage_disk' => 'kyc_private', 'file_path' => $path,
            'file_hash' => $hash, 'mime_type' => 'application/pdf', 'file_size' => strlen($contents),
            'status' => 'uploaded', 'expires_at' => $expiresAt,
        ]);
    }
}
