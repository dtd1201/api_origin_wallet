<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\KycDocument;
use App\Models\KycProfile;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumFileService;
use App\Services\Nium\NiumHkSandboxFileStageRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

require_once __DIR__.'/../../scripts/nium/generate_hk_sandbox_documents.php';

class NiumHkSandboxFileStageRunnerTest extends TestCase
{
    use RefreshDatabase;

    private const ROLES = [
        'corporate_registration' => '68e006d3f97f33b24e5ced1a07aaa4ff970270acba6fcee05e7658814a57822a',
        'applicant_authorized_person_identity' => '310f7f2716bf6945d4591e459e13449df2f41e044487ff4c3f36b97228f397a2',
        'beneficial_owner_stakeholder_identity' => 'd4b5d6945d047f8a892c7cb93694e37c2dd6efb98b8f63e86b18394f0c2ad953',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('kyc_private');
        $this->seedLockedFixture();
    }

    public function test_runner_performs_exactly_one_create_and_one_details_call_per_new_document(): void
    {
        $this->createFixtureDocuments();
        $counter = 0;
        $service = $this->mock(NiumFileService::class, function (MockInterface $mock) use (&$counter): void {
            $mock->shouldReceive('createFile')->times(3)->andReturnUsing(function (KycDocument $document) use (&$counter): array {
                $counter++;
                $id = sprintf('00000000-0000-4000-8000-%012d', $counter);
                $document->forceFill(['metadata' => [
                    ...(array) $document->metadata,
                    'nium_file_id' => $id,
                    'nium_file_state' => 'PROCESSING',
                ]])->save();

                return ['id' => $id, 'state' => 'PROCESSING'];
            });
            $mock->shouldReceive('refreshDocumentState')->times(3)->andReturn(['state' => 'AVAILABLE']);
        });

        $results = (new NiumHkSandboxFileStageRunner($service))->run();

        $this->assertCount(3, $results);
        $this->assertSame(['AVAILABLE', 'AVAILABLE', 'AVAILABLE'], array_column($results, 'file_state'));
        $this->assertSame(3, ApiRequestLog::query()->where('operation', 'customer_create')->count());
    }

    public function test_runner_rejects_historical_document_ids_existing_file_ids_and_hash_mismatch(): void
    {
        foreach ([
            'historical id' => ['ids' => [18, 21, 22]],
            'existing file id' => ['metadata' => ['nium_file_id' => '00000000-0000-4000-8000-000000000001']],
            'hash mismatch' => ['fileHash' => str_repeat('0', 64)],
        ] as $case) {
            $this->clearFixtureDocuments();
            $this->createFixtureDocuments(...$case);
            $service = \Mockery::mock(NiumFileService::class);
            $service->shouldNotReceive('createFile');
            $service->shouldNotReceive('refreshDocumentState');

            try {
                (new NiumHkSandboxFileStageRunner($service))->run();
                $this->fail('Expected the unsafe HK fixture document set to be rejected.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_ambiguous_create_outcome_is_marked_stop_no_retry_and_never_gets_details(): void
    {
        $documents = $this->createFixtureDocuments();
        $service = $this->mock(NiumFileService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createFile')->once()->andThrow(new RuntimeException('timeout'));
            $mock->shouldNotReceive('refreshDocumentState');
        });

        try {
            (new NiumHkSandboxFileStageRunner($service))->run();
            $this->fail('Expected an ambiguous File Create outcome to stop the runner.');
        } catch (RuntimeException $exception) {
            $this->assertSame('HK fixture File Create outcome is ambiguous; stop without retry.', $exception->getMessage());
        }

        $this->assertSame('STOP_NO_RETRY', $documents[0]->fresh()->metadata['file_stage_state']);
        $this->assertSame(3, ApiRequestLog::query()->where('operation', 'customer_create')->count());
    }

    private function seedLockedFixture(): void
    {
        $provider = IntegrationProvider::query()->forceCreate(['id' => 1, 'code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        $protectedUser = User::factory()->create(['id' => 4]);
        $fixtureUser = User::factory()->create(['id' => 9]);
        KycProfile::query()->forceCreate([
            'id' => 9,
            'user_id' => 9,
            'status' => 'approved',
            'applicant_type' => 'business',
            'legal_name' => 'Synthetic Fixture',
            'address_line1' => 'Synthetic',
            'city' => 'Hong Kong',
            'country_code' => 'HK',
        ]);
        UserProviderAccount::query()->forceCreate(['id' => 4, 'user_id' => $protectedUser->id, 'provider_id' => $provider->id]);
        UserProviderAccount::query()->forceCreate(['id' => 7, 'user_id' => $fixtureUser->id, 'provider_id' => $provider->id]);

        for ($index = 0; $index < 56; $index++) {
            ApiRequestLog::query()->create([
                'provider_id' => $provider->id,
                'user_id' => $fixtureUser->id,
                'operation' => $index < 3 ? 'customer_create' : 'safe_diagnostic',
                'request_method' => $index < 3 ? 'POST' : 'GET',
                'request_url' => 'https://sandbox.example.test/safe',
            ]);
        }
    }

    /**
     * @return list<KycDocument>
     */
    private function createFixtureDocuments(
        array $ids = [21, 22, 23],
        array $metadata = [],
        ?string $fileHash = null,
    ): array {
        $directory = Storage::disk('kyc_private')->path('kyc/9/nium-v5-hk');
        $manifest = generateNiumHkSandboxDocuments($directory);
        $documents = [];

        foreach ($manifest['runtime_artifacts'] as $index => $artifact) {
            $role = $artifact['logical_role'];
            $path = 'kyc/9/nium-v5-hk/'.$artifact['artifact_filename'];
            chmod(Storage::disk('kyc_private')->path($path), 0600);
            $documents[] = KycDocument::query()->forceCreate([
                'id' => $ids[$index],
                'kyc_profile_id' => 9,
                'type' => $index === 0 ? 'business_registration' : 'passport_front',
                'status' => 'approved',
                'file_url' => 'private://'.$path,
                'storage_disk' => 'kyc_private',
                'file_path' => $path,
                'original_name' => $artifact['artifact_filename'],
                'mime_type' => 'application/pdf',
                'file_size' => $artifact['byte_size'],
                'file_hash' => $fileHash ?? $artifact['sha256'],
                'issuing_country_code' => 'HK',
                'metadata' => [
                    'fixture_marker' => NiumHkSandboxFileStageRunner::FIXTURE_MARKER,
                    'logical_role' => $role,
                    'expected_sha256' => self::ROLES[$role],
                    'synthetic_test' => true,
                    ...$metadata,
                ],
            ]);
        }

        return $documents;
    }

    private function clearFixtureDocuments(): void
    {
        KycDocument::query()->delete();
    }
}
