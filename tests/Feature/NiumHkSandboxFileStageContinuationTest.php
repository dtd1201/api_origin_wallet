<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\KycDocument;
use App\Models\KycProfile;
use App\Models\User;
use App\Services\Nium\NiumFileService;
use App\Services\Nium\NiumHkSandboxFileStageContinuation;
use App\Services\Nium\NiumHkSandboxFileStageRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

require_once __DIR__.'/../../scripts/nium/generate_hk_sandbox_documents.php';

class NiumHkSandboxFileStageContinuationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kyc_private');
        $this->seedCheckpoint();
    }

    public function test_processing_details_gets_once_never_creates_and_leaves_later_documents_untouched(): void
    {
        $service = $this->mock(NiumFileService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('createFile');
            $mock->shouldReceive('refreshDocumentState')->once()->andReturnUsing(function (KycDocument $document): array {
                $this->log($document, 'GET');

                return ['state' => 'PROCESSING'];
            });
        });

        $status = (new NiumHkSandboxFileStageContinuation($service))->continueDocument21();

        $this->assertSame('HOLD_FILE_NOT_AVAILABLE', $status);
        $this->assertSame('HOLD_FILE_NOT_AVAILABLE', KycDocument::findOrFail(21)->metadata['file_stage_state']);
        $this->assertUnclaimed(22);
        $this->assertUnclaimed(23);
        $this->assertSame(3, $this->customerPosts());
    }

    public function test_available_details_gets_once_and_marks_file_available(): void
    {
        $service = $this->mock(NiumFileService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('createFile');
            $mock->shouldReceive('refreshDocumentState')->once()->andReturnUsing(function (KycDocument $document): array {
                $document->forceFill(['metadata' => [...$document->metadata, 'nium_file_state' => 'AVAILABLE']])->save();
                $this->log($document, 'GET');

                return ['state' => 'AVAILABLE'];
            });
        });

        $this->assertSame('PASS_FILE_AVAILABLE', (new NiumHkSandboxFileStageContinuation($service))->continueDocument21());
        $this->assertSame('FILE_AVAILABLE', KycDocument::findOrFail(21)->metadata['file_stage_state']);
    }

    public function test_unknown_details_outcome_has_no_retry_and_preserves_hold(): void
    {
        $service = $this->mock(NiumFileService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('createFile');
            $mock->shouldReceive('refreshDocumentState')->once()->andThrow(new RuntimeException('timeout'));
        });

        $this->assertSame('HOLD_DETAILS_OUTCOME_UNKNOWN', (new NiumHkSandboxFileStageContinuation($service))->continueDocument21());
        $this->assertSame('HOLD_FILE_NOT_AVAILABLE', KycDocument::findOrFail(21)->metadata['file_stage_state']);
        $this->assertUnclaimed(22);
        $this->assertUnclaimed(23);
    }

    public function test_resume_cannot_post_document22_until_document21_is_available(): void
    {
        $this->log(KycDocument::findOrFail(21), 'GET');
        $service = \Mockery::mock(NiumFileService::class);
        $service->shouldNotReceive('createFile');
        $service->shouldNotReceive('refreshDocumentState');

        $this->expectExceptionMessage('Document 21 must be AVAILABLE before resuming document 22.');
        (new NiumHkSandboxFileStageContinuation($service))->resumeNextDocument();
    }

    public function test_resume_skips_document21_and_processes_only_document22(): void
    {
        ApiRequestLog::query()->create([
            'provider_id' => 1, 'user_id' => 9, 'request_method' => 'GET', 'request_url' => '/files/21',
            'request_body' => ['kyc_document_id' => 21],
        ]);
        $document21 = KycDocument::findOrFail(21);
        $document21->forceFill(['metadata' => [
            ...$document21->metadata,
            'nium_file_state' => 'AVAILABLE',
            'file_stage_state' => 'FILE_AVAILABLE',
        ]])->save();
        $service = $this->mock(NiumFileService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createFile')->once()->withArgs(fn (KycDocument $document): bool => $document->id === 22)
                ->andReturnUsing(function (KycDocument $document): array {
                    $document->forceFill(['metadata' => [
                        ...$document->metadata,
                        'nium_file_id' => '22222222-2222-4222-8222-222222222222',
                        'nium_file_state' => 'PROCESSING',
                    ]])->save();
                    $this->log($document, 'POST');

                    return ['state' => 'PROCESSING'];
                });
            $mock->shouldReceive('refreshDocumentState')->once()->withArgs(fn (KycDocument $document): bool => $document->id === 22)
                ->andReturnUsing(function (KycDocument $document): array {
                    $document->forceFill(['metadata' => [...$document->metadata, 'nium_file_state' => 'AVAILABLE']])->save();
                    $this->log($document, 'GET');

                    return ['state' => 'AVAILABLE'];
                });
        });

        $this->assertSame('PASS_DOCUMENT_AVAILABLE', (new NiumHkSandboxFileStageContinuation($service))->resumeNextDocument());
        $this->assertSame('FILE_AVAILABLE', KycDocument::findOrFail(22)->metadata['file_stage_state']);
        $this->assertUnclaimed(23);
        $this->assertSame(1, ApiRequestLog::query()->where('request_method', 'POST')
            ->get()->filter(fn (ApiRequestLog $log): bool => data_get($log->request_body, 'kyc_document_id') === 22)->count());
        $this->assertSame(3, $this->customerPosts());
    }

    public function test_document22_continuation_is_one_details_only_and_leaves_document23_unclaimed(): void
    {
        $this->advanceToDocument22Checkpoint();
        $service = $this->mock(NiumFileService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('createFile');
            $mock->shouldReceive('refreshDocumentState')->once()->withArgs(fn (KycDocument $document): bool => $document->id === 22)
                ->andReturnUsing(function (KycDocument $document): array {
                    $this->log($document, 'GET');

                    return ['state' => 'PROCESSING'];
                });
        });

        $this->assertSame('HOLD_FILE_NOT_AVAILABLE', (new NiumHkSandboxFileStageContinuation($service))->continueDocument22());
        $this->assertUnclaimed(23);
        $this->assertSame(62, ApiRequestLog::query()->count());
        $this->assertSame(3, $this->customerPosts());
    }

    public function test_document22_available_outcome_is_a_single_attempt(): void
    {
        $this->advanceToDocument22Checkpoint();
        $service = $this->mock(NiumFileService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('createFile');
            $mock->shouldReceive('refreshDocumentState')->once()->andReturnUsing(function (KycDocument $document): array {
                $document->forceFill(['metadata' => [...$document->metadata, 'nium_file_state' => 'AVAILABLE']])->save();
                $this->log($document, 'GET');

                return ['state' => 'AVAILABLE'];
            });
        });

        $this->assertSame('PASS_FILE_AVAILABLE', (new NiumHkSandboxFileStageContinuation($service))->continueDocument22());
    }

    public function test_document22_unknown_outcome_has_no_retry(): void
    {
        $this->advanceToDocument22Checkpoint();
        $service = $this->mock(NiumFileService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('createFile');
            $mock->shouldReceive('refreshDocumentState')->once()->andThrow(new RuntimeException('timeout'));
        });

        $this->assertSame('HOLD_DETAILS_OUTCOME_UNKNOWN', (new NiumHkSandboxFileStageContinuation($service))->continueDocument22());
    }

    public function test_document23_requires_both_prior_documents_available_and_atomic_claim_blocks_duplicate_create(): void
    {
        $this->advanceToDocument22Checkpoint();
        $this->log(KycDocument::findOrFail(22), 'GET');
        $service = \Mockery::mock(NiumFileService::class);
        $service->shouldNotReceive('createFile');
        $service->shouldNotReceive('refreshDocumentState');

        try {
            (new NiumHkSandboxFileStageContinuation($service))->resumeDocument23();
            $this->fail('Expected document 22 PROCESSING to block document 23.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Document 22 must be AVAILABLE.', $exception->getMessage());
        }

        $document22 = KycDocument::findOrFail(22);
        $document22->forceFill(['metadata' => [
            ...$document22->metadata, 'nium_file_state' => 'AVAILABLE', 'file_stage_state' => 'FILE_AVAILABLE',
        ]])->save();
        $counter = 0;
        $service = $this->mock(NiumFileService::class, function (MockInterface $mock) use (&$counter): void {
            $mock->shouldReceive('createFile')->once()->andReturnUsing(function (KycDocument $document) use (&$counter): array {
                $loser = \Mockery::mock(NiumFileService::class);
                $loser->shouldNotReceive('createFile');
                $loser->shouldNotReceive('refreshDocumentState');
                try {
                    (new NiumHkSandboxFileStageContinuation($loser))->resumeDocument23();
                    $this->fail('Expected the committed document 23 claim to block duplicate Create.');
                } catch (RuntimeException) {
                    $this->addToAssertionCount(1);
                }
                $counter++;
                $document->forceFill(['metadata' => [
                    ...$document->metadata,
                    'nium_file_id' => '33333333-3333-4333-8333-333333333333',
                    'nium_file_state' => 'PROCESSING',
                ]])->save();
                $this->log($document, 'POST');

                return ['state' => 'PROCESSING'];
            });
            $mock->shouldReceive('refreshDocumentState')->once()->andReturnUsing(function (KycDocument $document): array {
                $this->log($document, 'GET');

                return ['state' => 'PROCESSING'];
            });
        });

        $this->assertSame('HOLD_FILE_NOT_AVAILABLE', (new NiumHkSandboxFileStageContinuation($service))->resumeDocument23());
        $this->assertSame(1, $counter);
        $this->assertSame(64, ApiRequestLog::query()->count());
    }

    public function test_document23_details_continuation_never_creates_and_supports_async_history(): void
    {
        $this->advanceToDocument22Checkpoint();
        $this->makeDocument22Available();
        $document23 = KycDocument::findOrFail(23);
        $document23->forceFill(['metadata' => [
            ...$document23->metadata,
            'file_stage_execution_marker' => 'nium-v5-hk-file-beneficial_owner_stakeholder_identity-create-v1',
            'file_stage_state' => 'HOLD_FILE_NOT_AVAILABLE',
            'nium_file_id' => '33333333-3333-4333-8333-333333333333',
            'nium_file_state' => 'PROCESSING',
        ]])->save();
        $this->log($document23, 'POST');
        $this->log($document23, 'GET');
        $service = $this->mock(NiumFileService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('createFile');
            $mock->shouldReceive('refreshDocumentState')->once()->andReturnUsing(function (KycDocument $document): array {
                $document->forceFill(['metadata' => [...$document->metadata, 'nium_file_state' => 'AVAILABLE']])->save();
                $this->log($document, 'GET');

                return ['state' => 'AVAILABLE'];
            });
        });

        $this->assertSame('PASS_FILE_AVAILABLE', (new NiumHkSandboxFileStageContinuation($service))->continueDocument23());
        $this->assertSame(65, ApiRequestLog::query()->count());
    }

    private function seedCheckpoint(): void
    {
        $provider = IntegrationProvider::query()->forceCreate(['id' => 1, 'code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        User::factory()->create(['id' => 8]);
        $user = User::factory()->create(['id' => 9]);
        KycProfile::query()->forceCreate([
            'id' => 9, 'user_id' => $user->id, 'status' => 'approved', 'applicant_type' => 'business',
            'legal_name' => 'Fixture', 'address_line1' => 'Fixture', 'city' => 'Hong Kong', 'country_code' => 'HK',
        ]);

        for ($index = 0; $index < 56; $index++) {
            ApiRequestLog::query()->create([
                'provider_id' => $provider->id,
                'user_id' => $index < 2 ? 8 : 9,
                'operation' => $index < 5 ? 'customer_create' : 'safe_diagnostic',
                'request_method' => $index < 5 ? 'POST' : 'GET',
                'request_url' => '/safe',
            ]);
        }

        $manifest = generateNiumHkSandboxDocuments(Storage::disk('kyc_private')->path('kyc/9/nium-v5-hk'));
        foreach ($manifest['runtime_artifacts'] as $index => $artifact) {
            $id = 21 + $index;
            $path = 'kyc/9/nium-v5-hk/'.$artifact['artifact_filename'];
            KycDocument::query()->forceCreate([
                'id' => $id, 'kyc_profile_id' => 9, 'type' => $index === 0 ? 'business_registration' : 'passport_front',
                'status' => 'approved', 'file_url' => 'private://'.$path, 'storage_disk' => 'kyc_private', 'file_path' => $path,
                'original_name' => $artifact['artifact_filename'], 'mime_type' => 'application/pdf', 'file_size' => $artifact['byte_size'],
                'file_hash' => $artifact['sha256'], 'issuing_country_code' => 'HK',
                'metadata' => ['fixture_marker' => NiumHkSandboxFileStageRunner::FIXTURE_MARKER, 'logical_role' => $artifact['logical_role'], 'expected_sha256' => $artifact['sha256']],
            ]);
        }
        $document21 = KycDocument::findOrFail(21);
        $document21->forceFill(['metadata' => [
            ...$document21->metadata,
            'file_stage_execution_marker' => 'nium-v5-hk-file-corporate_registration-create-v1',
            'file_stage_state' => 'HOLD_FILE_NOT_AVAILABLE',
            'nium_file_id' => '11111111-1111-4111-8111-111111111111',
            'nium_file_state' => 'PROCESSING',
        ]])->save();
        $this->log($document21, 'POST');
        $this->log($document21, 'GET');
    }

    private function log(KycDocument $document, string $method): void
    {
        ApiRequestLog::query()->create([
            'provider_id' => 1, 'user_id' => 9, 'request_method' => $method, 'request_url' => '/files',
            'request_body' => ['kyc_document_id' => $document->id],
        ]);
    }

    private function advanceToDocument22Checkpoint(): void
    {
        $document21 = KycDocument::findOrFail(21);
        $document21->forceFill(['metadata' => [
            ...$document21->metadata, 'nium_file_state' => 'AVAILABLE', 'file_stage_state' => 'FILE_AVAILABLE',
        ]])->save();
        $this->log($document21, 'GET');
        $document22 = KycDocument::findOrFail(22);
        $document22->forceFill(['metadata' => [
            ...$document22->metadata,
            'file_stage_execution_marker' => 'nium-v5-hk-file-applicant_authorized_person_identity-create-v1',
            'file_stage_state' => 'HOLD_FILE_NOT_AVAILABLE',
            'nium_file_id' => '22222222-2222-4222-8222-222222222222',
            'nium_file_state' => 'PROCESSING',
        ]])->save();
        $this->log($document22, 'POST');
        $this->log($document22, 'GET');
    }

    private function makeDocument22Available(): void
    {
        $document22 = KycDocument::findOrFail(22);
        $document22->forceFill(['metadata' => [
            ...$document22->metadata, 'nium_file_state' => 'AVAILABLE', 'file_stage_state' => 'FILE_AVAILABLE',
        ]])->save();
        $this->log($document22, 'GET');
    }

    private function assertUnclaimed(int $id): void
    {
        $metadata = KycDocument::findOrFail($id)->metadata;
        $this->assertArrayNotHasKey('nium_file_id', $metadata);
        $this->assertArrayNotHasKey('file_stage_execution_marker', $metadata);
    }

    private function customerPosts(): int
    {
        return ApiRequestLog::query()->where('provider_id', 1)->where('user_id', 9)
            ->where('operation', 'customer_create')->where('request_method', 'POST')->count();
    }
}
