<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\KycDocument;
use App\Models\KycProfile;
use App\Models\KycRelatedPerson;
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
        $historicalFingerprint = $this->createHistoricalDocuments();
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
                $this->logFileOperation($document, 'POST', 201);

                return ['id' => $id, 'state' => 'PROCESSING'];
            });
            $mock->shouldReceive('refreshDocumentState')->times(3)->andReturnUsing(function (KycDocument $document): array {
                $document->forceFill(['metadata' => [
                    ...(array) $document->metadata,
                    'nium_file_state' => 'AVAILABLE',
                ]])->save();
                $this->logFileOperation($document, 'GET', 200);

                return ['state' => 'AVAILABLE'];
            });
        });

        $results = (new NiumHkSandboxFileStageRunner($service))->run();

        $this->assertCount(3, $results);
        $this->assertSame(['AVAILABLE', 'AVAILABLE', 'AVAILABLE'], array_column($results, 'file_state'));
        $this->assertSame(5, ApiRequestLog::query()->where('operation', 'customer_create')->count());
        $this->assertSame(3, $this->fixtureCustomerPostCount());
        $this->assertSame(62, ApiRequestLog::query()->count());
        $this->assertSame(3, KycDocument::query()->whereIn('id', [21, 22, 23])->get()
            ->pluck('metadata')->pluck('nium_file_id')->unique()->count());
        $this->assertSame($historicalFingerprint, $this->historicalFingerprint());
    }

    public function test_runner_rejects_historical_document_ids_existing_file_ids_and_hash_mismatch(): void
    {
        foreach ([
            'historical id' => ['ids' => [18, 21, 22]],
            'existing file id' => ['metadata' => ['nium_file_id' => '00000000-0000-4000-8000-000000000001']],
            'existing execution marker' => ['metadata' => ['file_stage_execution_marker' => 'already-claimed']],
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
            $this->assertSame('HK fixture File Create outcome is unknown; stop without retry.', $exception->getMessage());
        }

        $this->assertSame('STOP_CREATE_OUTCOME_UNKNOWN_NO_RETRY', $documents[0]->fresh()->metadata['file_stage_state']);
        $this->assertSame(3, $this->fixtureCustomerPostCount());
    }

    public function test_definite_provider_rejection_is_classified_without_retry(): void
    {
        $documents = $this->createFixtureDocuments();
        $service = $this->mock(NiumFileService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createFile')->once()->andReturnUsing(function (KycDocument $document): never {
                $this->logFileOperation($document, 'POST', 400);

                throw new RuntimeException('HTTP 400');
            });
            $mock->shouldNotReceive('refreshDocumentState');
        });

        try {
            (new NiumHkSandboxFileStageRunner($service))->run();
            $this->fail('Expected a definite provider rejection to stop the runner.');
        } catch (RuntimeException $exception) {
            $this->assertSame('HK fixture File Create was rejected; stop without retry.', $exception->getMessage());
        }

        $this->assertSame('STOP_CREATE_REJECTED_NO_RETRY', $documents[0]->fresh()->metadata['file_stage_state']);
    }

    public function test_fourth_fixture_customer_create_fails_preflight_before_http(): void
    {
        ApiRequestLog::query()->where('operation', 'safe_diagnostic')->firstOrFail()->delete();
        ApiRequestLog::query()->create([
            'provider_id' => IntegrationProvider::query()->where('code', 'nium')->sole()->id,
            'user_id' => 9,
            'operation' => 'customer_create',
            'request_method' => 'POST',
            'request_url' => 'https://sandbox.example.test/safe',
        ]);
        $this->createFixtureDocuments();
        $service = \Mockery::mock(NiumFileService::class);
        $service->shouldNotReceive('createFile');
        $service->shouldNotReceive('refreshDocumentState');

        $this->expectExceptionMessage('Fixture V4 Nium Customer Create POST count is not the locked value 3.');

        (new NiumHkSandboxFileStageRunner($service))->run();
    }

    public function test_wrong_provider_customer_create_does_not_count_for_fixture_preflight(): void
    {
        ApiRequestLog::query()->where('operation', 'safe_diagnostic')->firstOrFail()->delete();
        ApiRequestLog::query()->create([
            'provider_id' => IntegrationProvider::query()->where('code', 'other')->sole()->id,
            'user_id' => 9,
            'operation' => 'customer_create',
            'request_method' => 'POST',
            'request_url' => 'https://sandbox.example.test/safe',
        ]);
        $service = \Mockery::mock(NiumFileService::class);
        $service->shouldNotReceive('createFile');
        $service->shouldNotReceive('refreshDocumentState');

        try {
            (new NiumHkSandboxFileStageRunner($service))->run();
            $this->fail('Expected the missing fixture documents to stop the runner.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Exactly three isolated HK fixture documents are required.', $exception->getMessage());
        }
    }

    public function test_atomic_claim_is_committed_before_http_and_blocks_reentrant_runner(): void
    {
        $this->createFixtureDocuments();
        $counter = 0;
        $transactionLevel = \DB::transactionLevel();
        $service = $this->mock(NiumFileService::class, function (MockInterface $mock) use (&$counter, $transactionLevel): void {
            $mock->shouldReceive('createFile')->times(3)->andReturnUsing(function (KycDocument $document) use (&$counter, $transactionLevel): array {
                $this->assertSame($transactionLevel, \DB::transactionLevel());

                if ($counter === 0) {
                    $losingService = \Mockery::mock(NiumFileService::class);
                    $losingService->shouldNotReceive('createFile');
                    $losingService->shouldNotReceive('refreshDocumentState');

                    try {
                        (new NiumHkSandboxFileStageRunner($losingService))->run();
                        $this->fail('Expected the second runner to lose the durable claim.');
                    } catch (RuntimeException) {
                        $this->addToAssertionCount(1);
                    }
                }

                $counter++;
                $id = sprintf('10000000-0000-4000-8000-%012d', $counter);
                $document->forceFill(['metadata' => [
                    ...(array) $document->metadata,
                    'nium_file_id' => $id,
                    'nium_file_state' => 'PROCESSING',
                ]])->save();
                $this->logFileOperation($document, 'POST', 201);

                return ['id' => $id, 'state' => 'PROCESSING'];
            });
            $mock->shouldReceive('refreshDocumentState')->times(3)->andReturnUsing(function (KycDocument $document): array {
                $document->forceFill(['metadata' => [
                    ...(array) $document->metadata,
                    'nium_file_state' => 'AVAILABLE',
                ]])->save();
                $this->logFileOperation($document, 'GET', 200);

                return ['state' => 'AVAILABLE'];
            });
        });

        (new NiumHkSandboxFileStageRunner($service))->run();
    }

    public function test_role_binding_mismatches_fail_before_http(): void
    {
        foreach ([
            'applicant without related person' => [null, null, 32],
            'applicant attached to stakeholder' => [null, 32, 32],
            'stakeholder attached to applicant' => [null, 31, 31],
            'profile document attached to person' => [31, 31, 32],
        ] as $relatedPersonIds) {
            $this->clearFixtureDocuments();
            $this->createFixtureDocuments(relatedPersonIds: $relatedPersonIds);
            $service = \Mockery::mock(NiumFileService::class);
            $service->shouldNotReceive('createFile');
            $service->shouldNotReceive('refreshDocumentState');

            try {
                (new NiumHkSandboxFileStageRunner($service))->run();
                $this->fail('Expected role-to-related-person mismatch to fail before HTTP.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_path_mime_and_permission_mismatches_fail_before_http(): void
    {
        foreach (['path', 'mime', 'permission'] as $case) {
            $this->clearFixtureDocuments();
            $documents = $this->createFixtureDocuments();
            $document = $documents[0];

            match ($case) {
                'path' => $document->forceFill(['file_path' => '../escape.pdf'])->save(),
                'mime' => file_put_contents(Storage::disk('kyc_private')->path($document->file_path), 'not-a-pdf'),
                'permission' => chmod(Storage::disk('kyc_private')->path($document->file_path), 0644),
            };
            $service = \Mockery::mock(NiumFileService::class);
            $service->shouldNotReceive('createFile');
            $service->shouldNotReceive('refreshDocumentState');

            try {
                (new NiumHkSandboxFileStageRunner($service))->run();
                $this->fail('Expected unsafe artifact storage evidence to fail before HTTP.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_non_available_details_stops_before_next_create(): void
    {
        foreach (['PROCESSING', 'REJECTED'] as $state) {
            $this->clearFixtureDocuments();
            ApiRequestLog::query()->where('id', '>', 56)->delete();
            $documents = $this->createFixtureDocuments();
            $service = \Mockery::mock(NiumFileService::class);
            $service->shouldReceive('createFile')->once()->andReturnUsing(function (KycDocument $document): array {
                $id = '20000000-0000-4000-8000-000000000001';
                $document->forceFill(['metadata' => [
                    ...(array) $document->metadata,
                    'nium_file_id' => $id,
                    'nium_file_state' => 'PROCESSING',
                ]])->save();
                $this->logFileOperation($document, 'POST', 201);

                return ['id' => $id, 'state' => 'PROCESSING'];
            });
            $service->shouldReceive('refreshDocumentState')->once()->andReturnUsing(function (KycDocument $document) use ($state): array {
                $document->forceFill(['metadata' => [
                    ...(array) $document->metadata,
                    'nium_file_state' => $state,
                ]])->save();
                $this->logFileOperation($document, 'GET', 200);

                return ['state' => $state];
            });

            try {
                (new NiumHkSandboxFileStageRunner($service))->run();
                $this->fail('Expected a non-AVAILABLE details state to stop the runner.');
            } catch (RuntimeException $exception) {
                $this->assertSame('HK fixture File Details is not AVAILABLE; hold without retry.', $exception->getMessage());
            }

            $this->assertSame('HOLD_FILE_NOT_AVAILABLE', $documents[0]->fresh()->metadata['file_stage_state']);
            $this->assertArrayNotHasKey('file_stage_execution_marker', $documents[1]->fresh()->metadata);
        }
    }

    private function seedLockedFixture(): void
    {
        $provider = IntegrationProvider::query()->forceCreate(['id' => 1, 'code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        IntegrationProvider::query()->forceCreate(['id' => 2, 'code' => 'other', 'name' => 'Other', 'status' => 'active']);
        $protectedUser = User::factory()->create(['id' => 4]);
        $historicalUser = User::factory()->create(['id' => 8]);
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
        KycRelatedPerson::query()->forceCreate([
            'id' => 31,
            'kyc_profile_id' => 9,
            'relationship_type' => 'applicant',
            'status' => 'approved',
            'legal_name' => 'Synthetic Applicant',
        ]);
        KycRelatedPerson::query()->forceCreate([
            'id' => 32,
            'kyc_profile_id' => 9,
            'relationship_type' => 'beneficial_owner',
            'status' => 'approved',
            'legal_name' => 'Synthetic Stakeholder',
        ]);
        UserProviderAccount::query()->forceCreate(['id' => 4, 'user_id' => $protectedUser->id, 'provider_id' => $provider->id]);
        UserProviderAccount::query()->forceCreate(['id' => 7, 'user_id' => $fixtureUser->id, 'provider_id' => $provider->id]);

        for ($index = 0; $index < 56; $index++) {
            $isHistoricalCustomerPost = $index < 2;
            $isFixtureCustomerPost = $index >= 2 && $index < 5;
            ApiRequestLog::query()->create([
                'provider_id' => $provider->id,
                'user_id' => $isHistoricalCustomerPost ? $historicalUser->id : $fixtureUser->id,
                'operation' => ($isHistoricalCustomerPost || $isFixtureCustomerPost)
                    ? 'customer_create'
                    : 'safe_diagnostic',
                'request_method' => ($isHistoricalCustomerPost || $isFixtureCustomerPost)
                    ? 'POST'
                    : 'GET',
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
        array $relatedPersonIds = [null, 31, 32],
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
                'kyc_related_person_id' => $relatedPersonIds[$index],
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

    private function createHistoricalDocuments(): string
    {
        foreach ([18, 19, 20] as $id) {
            KycDocument::query()->forceCreate([
                'id' => $id,
                'kyc_profile_id' => 9,
                'type' => $id === 18 ? 'business_registration' : 'passport_front',
                'status' => 'approved',
                'file_url' => 'private://historical/'.$id,
                'issuing_country_code' => 'SG',
                'metadata' => ['historical_only' => true],
            ]);
        }

        return $this->historicalFingerprint();
    }

    private function historicalFingerprint(): string
    {
        return hash('sha256', KycDocument::query()->whereIn('id', [18, 19, 20])->orderBy('id')->get()
            ->map(fn (KycDocument $document): array => $document->getRawOriginal())
            ->toJson(JSON_THROW_ON_ERROR));
    }

    private function logFileOperation(KycDocument $document, string $method, int $status): void
    {
        ApiRequestLog::query()->create([
            'provider_id' => 1,
            'user_id' => 9,
            'request_method' => $method,
            'request_url' => '/api/v1/client/[REDACTED]/files',
            'request_body' => ['kyc_document_id' => $document->id],
            'response_status' => $status,
            'is_success' => $status >= 200 && $status < 300,
        ]);
    }

    private function fixtureCustomerPostCount(): int
    {
        return ApiRequestLog::query()
            ->where('provider_id', IntegrationProvider::query()->where('code', 'nium')->sole()->id)
            ->where('user_id', 9)
            ->where('operation', 'customer_create')
            ->where('request_method', 'POST')
            ->count();
    }
}
