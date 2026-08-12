<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\KycDocument;
use App\Models\IntegrationProvider;
use App\Models\KycProfile;
use App\Models\KycRelatedPerson;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use App\Services\Nium\NiumHkFactualPersonalDocumentPreparationService;
use App\Services\Nium\NiumHkFactualPersonalFileOneShotRunner;
use App\Services\Nium\NiumHkManualKycDocumentResolver;
use App\Services\Nium\NiumHkStakeholderSubmitKycRetryOneShotRunner;
use App\Services\Nium\NiumHkSubmitKycPayloadFactory;
use App\Services\Nium\NiumService;
use App\Services\Nium\NiumFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class NiumHkFactualPersonalFileStageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kyc_private');
        User::factory()->create(['id' => 4]);
        User::factory()->create(['id' => 8]);
        User::factory()->create(['id' => 9]);
        IntegrationProvider::query()->forceCreate(['id' => 1, 'code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        KycProfile::query()->forceCreate([
            'id' => 9, 'user_id' => 9, 'status' => 'approved', 'applicant_type' => 'business',
            'legal_name' => 'Fixture Company', 'address_line1' => 'Fixture Address', 'city' => 'Hong Kong', 'country_code' => 'HK',
        ]);
        KycRelatedPerson::query()->forceCreate([
            'id' => 14, 'kyc_profile_id' => 9, 'relationship_type' => 'beneficial_owner',
            'status' => 'approved', 'legal_name' => 'Fixture Person',
            'metadata' => ['nium_biometric_identity' => [
                'type' => 'passport', 'identification_number' => 'FACTUAL-PASSPORT-NUMBER',
                'issuance_country' => 'VN', 'expiry_date' => '2099-12-31', 'factual' => true,
                'synthetic' => false, 'source' => 'operator_verified_factual_identity_v1',
            ]],
        ]);
        KycProfile::query()->forceCreate(['id' => 8, 'user_id' => 8, 'status' => 'approved', 'applicant_type' => 'business', 'legal_name' => 'Other Company', 'address_line1' => 'Other Address', 'city' => 'Hong Kong', 'country_code' => 'HK']);
        KycRelatedPerson::query()->forceCreate(['id' => 15, 'kyc_profile_id' => 9, 'relationship_type' => 'beneficial_owner', 'status' => 'approved', 'legal_name' => 'Other Person']);
        UserProviderAccount::query()->forceCreate(['id' => 4, 'user_id' => 4, 'provider_id' => 1]);
        UserProviderAccount::query()->forceCreate(['id' => 7, 'user_id' => 9, 'provider_id' => 1, 'external_customer_id' => 'customer-safe-id', 'external_account_id' => 'wallet-safe-id', 'reconciliation_status' => 'reconciled', 'metadata' => [
            'nium_submit_kyc_attempts' => [
                $this->attemptKey('c620e0e9-ab0a-43bd-aa10-44f573db723a') => ['state' => 'provider_accepted_200_sandbox_review'],
                $this->attemptKey('7609d9d1-9d37-4e08-9197-602d792f7a2e') => ['state' => 'rejected'],
            ],
        ]]);
        foreach ([[104, 'c620e0e9-ab0a-43bd-aa10-44f573db723a', 200], [106, '7609d9d1-9d37-4e08-9197-602d792f7a2e', 400]] as [$id, $reference, $status]) {
            ApiRequestLog::query()->forceCreate(['id' => $id, 'provider_id' => 1, 'user_id' => 9, 'operation' => 'submit_kyc', 'external_reference' => $reference, 'request_method' => 'POST', 'request_url' => '/safe/submitKyc', 'response_status' => $status, 'transport_outcome' => 'response_received', 'is_success' => $status === 200, 'response_body' => $id === 106 ? ['error_code' => 'invalid_input', 'error_items' => [['error_code' => 'invalid_input', 'error_field' => 'entityType', 'error_field_fingerprint' => 'b4753588f3f6ef2b']]] : []]);
        }
        WebhookEvent::query()->forceCreate(['provider_id' => 1, 'event_id' => 'customer-awaiting-kyc', 'event_type' => 'CUSTOMER_STATUS_WEBHOOK', 'external_resource_id' => 'customer-safe-id', 'payload' => ['status' => 'pending', 'subStatus' => 'awaiting_kyc'], 'processing_status' => 'processed', 'processed_at' => now()]);
    }

    public function test_prepares_identity_and_poa_with_factual_metadata(): void
    {
        $identity = $this->prepare('stakeholder_identity', 'person14/passport.pdf', 'application/pdf', 'passport_front');
        $poa = $this->prepare('stakeholder_proof_of_address', 'person14/statement.pdf', 'application/pdf', 'bank_statement');

        $this->assertSame('pending', $identity->status);
        $this->assertSame('FACTUAL_FILE_PREPARED', $identity->metadata['file_stage_state']);
        $this->assertSame('passport', $identity->metadata['document_purpose']);
        $this->assertSame('bank_statement', $poa->metadata['document_purpose']);
        $this->assertArrayNotHasKey('nium_file_id', $identity->metadata);
    }

    public function test_duplicate_hash_and_path_are_rejected(): void
    {
        $this->prepare('stakeholder_identity', 'person14/passport.pdf', 'application/pdf', 'passport_front');

        $this->expectException(RuntimeException::class);
        $this->prepare('stakeholder_identity', 'person14/passport.pdf', 'application/pdf', 'passport_front');
    }

    public function test_absolute_traversal_and_disallowed_mime_are_rejected(): void
    {
        foreach (['/absolute.pdf', '../traversal.pdf'] as $path) {
            try {
                app(NiumHkFactualPersonalDocumentPreparationService::class)->prepare('stakeholder_identity', $path, str_repeat('a', 64), 'passport_front');
                $this->fail('Unsafe path was accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        Storage::disk('kyc_private')->put('person14/file.txt', 'plain text');
        chmod(Storage::disk('kyc_private')->path('person14/file.txt'), 0600);
        $this->expectException(RuntimeException::class);
        app(NiumHkFactualPersonalDocumentPreparationService::class)->prepare('stakeholder_identity', 'person14/file.txt', hash('sha256', 'plain text'), 'passport_front');
    }

    public function test_audit_is_read_only_and_run_requires_approval(): void
    {
        $document = $this->prepare('stakeholder_identity', 'person14/passport.pdf', 'application/pdf', 'passport_front');
        $this->mock(NiumFileService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('createFile')->shouldNotReceive('refreshDocumentState'));
        $before = KycDocument::query()->findOrFail($document->id)->getRawOriginal();

        $result = app(NiumHkFactualPersonalFileOneShotRunner::class)->audit($document->id, 'stakeholder_identity');
        $this->assertSame('READY_FOR_SEPARATE_HUMAN_APPROVAL', $result['terminal']);
        $after = $document->fresh()->getRawOriginal();
        ksort($before);
        ksort($after);
        $this->assertSame($before, $after);

        $this->expectException(RuntimeException::class);
        app(NiumHkFactualPersonalFileOneShotRunner::class)->run($document->id, 'stakeholder_identity');
    }

    public function test_invalid_prepared_metadata_and_integrity_are_rejected(): void
    {
        foreach ([
            ['kyc_profile_id', 8], ['kyc_related_person_id', 15], ['metadata.synthetic_only', true],
            ['metadata.historical_only', true], ['metadata.superseded', true], ['metadata.nium_file_id', '30000000-0000-4000-8000-000000000001'],
            ['metadata.file_stage_execution_marker', 'claimed'], ['file_size', 999], ['file_hash', str_repeat('a', 64)],
        ] as [$field, $value]) {
            $document = $this->prepare('stakeholder_identity', 'person14/'.str_replace('.', '-', $field).'.pdf', 'application/pdf', 'passport_front');
            if (str_starts_with($field, 'metadata.')) {
                $metadata = $document->metadata;
                data_set($metadata, substr($field, 9), $value);
                $document->forceFill(['metadata' => $metadata])->save();
            } else {
                $document->forceFill([$field => $value])->save();
            }
            try {
                app(NiumHkFactualPersonalFileOneShotRunner::class)->audit($document->id, 'stakeholder_identity');
                $this->fail("Invalid {$field} was accepted.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
            $document->delete();
        }
    }

    public function test_claim_precedes_create_and_second_run_cannot_post(): void
    {
        $document = $this->prepare('stakeholder_identity', 'person14/claim.pdf', 'application/pdf', 'passport_front');
        $this->mock(NiumFileService::class, function (MockInterface $mock) use ($document): void {
            $mock->shouldReceive('createFile')->once()->andReturnUsing(function () use ($document): array {
                $this->assertSame('CREATE_SUBMITTING', KycDocument::query()->findOrFail($document->id)->metadata['file_stage_state']);
                throw new RuntimeException('ambiguous');
            });
        });
        $runner = app(NiumHkFactualPersonalFileOneShotRunner::class);
        $this->assertSame('STOP_CREATE_OUTCOME_UNKNOWN_NO_RETRY', $runner->run($document->id, 'stakeholder_identity', true)['terminal']);
        $this->expectException(RuntimeException::class);
        $runner->run($document->id, 'stakeholder_identity', true);
    }

    public function test_available_and_processing_details_transitions(): void
    {
        foreach (['AVAILABLE', 'PROCESSING'] as $state) {
            $document = $this->prepare('stakeholder_identity', "person14/{$state}.pdf", 'application/pdf', 'passport_front');
            $fileId = '30000000-0000-4000-8000-'.str_pad((string) $document->id, 12, '0', STR_PAD_LEFT);
            $this->mock(NiumFileService::class, function (MockInterface $mock) use ($document, $fileId, $state): void {
                $mock->shouldReceive('createFile')->once()->andReturnUsing(function (KycDocument $model) use ($fileId): array {
                    $model->forceFill(['metadata' => [...$model->metadata, 'nium_file_id' => $fileId, 'nium_file_state' => 'PROCESSING']])->save();
                    return ['id' => $fileId, 'state' => 'PROCESSING'];
                });
                $mock->shouldReceive('refreshDocumentState')->once()->andReturnUsing(function (KycDocument $model) use ($fileId, $state): array {
                    $metadata = [...$model->metadata, 'nium_file_id' => $fileId, 'nium_file_state' => $state];
                    if ($state === 'AVAILABLE') $metadata['nium_available_at'] = now()->toISOString();
                    $model->forceFill(['metadata' => $metadata])->save();
                    return ['id' => $fileId, 'state' => $state];
                });
            });
            $result = app(NiumHkFactualPersonalFileOneShotRunner::class)->run($document->id, 'stakeholder_identity', true);
            $fresh = $document->fresh();
            $this->assertSame($state === 'AVAILABLE' ? 'PASS_FILE_AVAILABLE' : 'HOLD_FILE_NOT_AVAILABLE', $result['terminal']);
            $this->assertSame($state === 'AVAILABLE' ? 'approved' : 'pending', $fresh->status);
            $this->assertSame($state === 'AVAILABLE' ? 'FILE_AVAILABLE' : 'HOLD_FILE_NOT_AVAILABLE', $fresh->metadata['file_stage_state']);
            $fresh->delete();
        }
    }

    public function test_definite_create_rejections_are_no_retry(): void
    {
        foreach ([400, 422, 500] as $status) {
            $document = $this->prepare('stakeholder_identity', "person14/rejected-{$status}.pdf", 'application/pdf', 'passport_front');
            $this->mock(NiumFileService::class, function (MockInterface $mock) use ($document, $status): void {
                $mock->shouldReceive('createFile')->once()->andReturnUsing(function () use ($document, $status): never {
                    $this->fileLog($document->id, 'POST', $status);
                    throw new RuntimeException("rejected {$status}");
                });
                $mock->shouldNotReceive('refreshDocumentState');
            });
            $runner = app(NiumHkFactualPersonalFileOneShotRunner::class);
            $result = $runner->run($document->id, 'stakeholder_identity', true);
            $this->assertSame('STOP_CREATE_REJECTED_NO_RETRY', $result['terminal']);
            $this->assertSame(1, $this->documentLogs($document->id)->where('request_method', 'POST')->count());
            try { $runner->run($document->id, 'stakeholder_identity', true); $this->fail('Rejected Create retried.'); } catch (RuntimeException) { $this->addToAssertionCount(1); }
            $document->delete();
        }
    }

    public function test_invalid_success_file_ids_fail_closed(): void
    {
        foreach ([null, '', 'not-a-uuid'] as $index => $fileId) {
            $document = $this->prepare('stakeholder_identity', "person14/invalid-id-{$index}.pdf", 'application/pdf', 'passport_front');
            $this->mock(NiumFileService::class, function (MockInterface $mock) use ($document, $fileId): void {
                $mock->shouldReceive('createFile')->once()->andReturnUsing(function () use ($document, $fileId): array {
                    $this->fileLog($document->id, 'POST', 200);
                    return $fileId === null ? ['state' => 'PROCESSING'] : ['id' => $fileId, 'state' => 'PROCESSING'];
                });
                $mock->shouldNotReceive('refreshDocumentState');
            });
            $result = app(NiumHkFactualPersonalFileOneShotRunner::class)->run($document->id, 'stakeholder_identity', true);
            $this->assertSame('STOP_CREATE_OUTCOME_UNKNOWN_NO_RETRY', $result['terminal']);
            $this->assertArrayNotHasKey('nium_file_id', $document->fresh()->metadata);
            $document->delete();
        }
    }

    public function test_historical_scoped_create_blocks_audit_and_run(): void
    {
        $document = $this->prepare('stakeholder_identity', 'person14/historical.pdf', 'application/pdf', 'passport_front');
        $this->fileLog($document->id, 'POST', 500);
        $this->mock(NiumFileService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('createFile'));
        foreach (['audit', 'run'] as $method) {
            try {
                $method === 'audit' ? app(NiumHkFactualPersonalFileOneShotRunner::class)->audit($document->id, 'stakeholder_identity') : app(NiumHkFactualPersonalFileOneShotRunner::class)->run($document->id, 'stakeholder_identity', true);
                $this->fail('Historical Create was ignored.');
            } catch (RuntimeException) { $this->addToAssertionCount(1); }
        }
        $this->assertSame(1, $this->documentLogs($document->id)->where('request_method', 'POST')->count());
    }

    public function test_continuation_available_non_available_errors_and_file_id_immutability(): void
    {
        foreach (['AVAILABLE', 'PENDING', 'ERROR', 'MISMATCH'] as $state) {
            $document = $this->continuationDocument("person14/continue-{$state}.pdf");
            $fileId = $document->metadata['nium_file_id'];
            $this->mock(NiumFileService::class, function (MockInterface $mock) use ($document, $fileId, $state): void {
                $mock->shouldNotReceive('createFile');
                $mock->shouldReceive('refreshDocumentState')->once()->andReturnUsing(function (KycDocument $model) use ($document, $fileId, $state): array {
                    $this->fileLog($document->id, 'GET', $state === 'ERROR' ? 500 : 200);
                    if ($state === 'ERROR') throw new RuntimeException('details error');
                    $returnedId = $state === 'MISMATCH' ? '40000000-0000-4000-8000-000000000001' : $fileId;
                    $metadata = [...$model->metadata, 'nium_file_state' => $state];
                    if ($state === 'AVAILABLE') $metadata['nium_available_at'] = now()->toISOString();
                    $model->forceFill(['metadata' => $metadata])->save();
                    return ['id' => $returnedId, 'state' => $state];
                });
            });
            try {
                $result = app(NiumHkFactualPersonalFileOneShotRunner::class)->continueDetails($document->id, 'stakeholder_identity');
                if ($state === 'MISMATCH') $this->fail('Mismatching File ID was accepted.');
                $this->assertSame($state === 'AVAILABLE' ? 'PASS_FILE_AVAILABLE' : ($state === 'ERROR' ? 'HOLD_DETAILS_OUTCOME_UNKNOWN' : 'HOLD_FILE_NOT_AVAILABLE'), $result['terminal']);
            } catch (RuntimeException) {
                $this->assertSame('MISMATCH', $state);
            }
            $fresh = $document->fresh();
            $this->assertSame($fileId, $fresh->metadata['nium_file_id']);
            $this->assertSame(1, $this->documentLogs($document->id)->where('request_method', 'POST')->count());
            $this->assertSame(1, $this->documentLogs($document->id)->where('request_method', 'GET')->count());
            if ($state === 'AVAILABLE') {
                $this->assertSame('approved', $fresh->status);
                $this->assertSame('FILE_AVAILABLE', $fresh->metadata['file_stage_state']);
            }
            $document->delete();
        }
    }

    public function test_available_identity_and_poa_resolve_offline(): void
    {
        $identity = $this->prepare('stakeholder_identity', 'person14/integration-passport.pdf', 'application/pdf', 'passport_front');
        $poa = $this->prepare('stakeholder_proof_of_address', 'person14/integration-statement.pdf', 'application/pdf', 'bank_statement');
        foreach ([$identity, $poa] as $index => $document) {
            $fileId = '50000000-0000-4000-8000-'.str_pad((string) ($index + 1), 12, '0', STR_PAD_LEFT);
            $document->forceFill(['status' => 'approved', 'metadata' => [...$document->metadata, 'nium_file_id' => $fileId, 'nium_file_state' => 'AVAILABLE', 'nium_available_at' => now()->toISOString(), 'file_stage_state' => 'FILE_AVAILABLE']])->save();
        }
        $resolved = app(NiumHkManualKycDocumentResolver::class)->resolve(KycRelatedPerson::query()->findOrFail(14));
        $this->assertSame($identity->id, $resolved['identity']->id);
        $this->assertSame($poa->id, $resolved['proof_of_address']->id);
        $this->assertDatabaseCount('api_request_logs', 2);
        $this->assertSame(0, ApiRequestLog::query()->whereIn('operation', ['onboarding_simulation_submit_kyc', 'assign_payment_id'])->count());
    }

    public function test_transport_exception_continuation_is_single_get_no_create(): void
    {
        $document = $this->continuationDocument('person14/transport.pdf');
        $fileId = $document->metadata['nium_file_id'];
        $this->mock(NiumFileService::class, function (MockInterface $mock) use ($document): void {
            $mock->shouldNotReceive('createFile');
            $mock->shouldReceive('refreshDocumentState')->once()->andReturnUsing(function () use ($document): never {
                $this->fileLog($document->id, 'GET', 0);
                throw new ConnectionException('timeout');
            });
        });
        $result = app(NiumHkFactualPersonalFileOneShotRunner::class)->continueDetails($document->id, 'stakeholder_identity');
        $fresh = $document->fresh();
        $this->assertSame('HOLD_DETAILS_OUTCOME_UNKNOWN', $result['terminal']);
        $this->assertSame($fileId, $fresh->metadata['nium_file_id']);
        $this->assertSame('pending', $fresh->status);
        $this->assertNotSame('AVAILABLE', $fresh->metadata['nium_file_state']);
        $this->assertSame(1, $this->documentLogs($document->id)->where('request_method', 'POST')->count());
        $this->assertSame(1, $this->documentLogs($document->id)->where('request_method', 'GET')->count());
        $this->assertLockedEvidenceUnchanged($this->lockedSnapshots());
        $this->assertZeroDownstreamWrites();
    }

    public function test_locked_104_106_unchanged_for_available_rejected_ambiguous_and_continuation(): void
    {
        foreach (['available', 'rejected', 'ambiguous', 'continuation'] as $outcome) {
            $snapshots = $this->lockedSnapshots();
            $document = $outcome === 'continuation'
                ? $this->continuationDocument('person14/snapshot-continuation.pdf')
                : $this->prepare('stakeholder_identity', "person14/snapshot-{$outcome}.pdf", 'application/pdf', 'passport_front');
            $fileId = '60000000-0000-4000-8000-'.str_pad((string) $document->id, 12, '0', STR_PAD_LEFT);
            $this->mock(NiumFileService::class, function (MockInterface $mock) use ($document, $fileId, $outcome): void {
                if ($outcome === 'continuation') {
                    $mock->shouldNotReceive('createFile');
                    $mock->shouldReceive('refreshDocumentState')->once()->andReturnUsing(function (KycDocument $model) use ($document): array {
                        $id = $model->metadata['nium_file_id']; $this->fileLog($document->id, 'GET', 200);
                        $model->forceFill(['metadata' => [...$model->metadata, 'nium_file_state' => 'AVAILABLE', 'nium_available_at' => now()->toISOString()]])->save();
                        return ['id' => $id, 'state' => 'AVAILABLE'];
                    }); return;
                }
                $mock->shouldReceive('createFile')->once()->andReturnUsing(function (KycDocument $model) use ($document, $fileId, $outcome): array {
                    $this->fileLog($document->id, 'POST', $outcome === 'rejected' ? 422 : 200);
                    if ($outcome !== 'available') throw new RuntimeException($outcome);
                    $model->forceFill(['metadata' => [...$model->metadata, 'nium_file_id' => $fileId, 'nium_file_state' => 'PROCESSING']])->save(); return ['id' => $fileId];
                });
                if ($outcome === 'available') $mock->shouldReceive('refreshDocumentState')->once()->andReturnUsing(function (KycDocument $model) use ($document, $fileId): array { $this->fileLog($document->id, 'GET', 200); $model->forceFill(['metadata' => [...$model->metadata, 'nium_file_state' => 'AVAILABLE', 'nium_available_at' => now()->toISOString()]])->save(); return ['id' => $fileId, 'state' => 'AVAILABLE']; });
            });
            $outcome === 'continuation'
                ? app(NiumHkFactualPersonalFileOneShotRunner::class)->continueDetails($document->id, 'stakeholder_identity')
                : app(NiumHkFactualPersonalFileOneShotRunner::class)->run($document->id, 'stakeholder_identity', true);
            $this->assertLockedEvidenceUnchanged($snapshots);
            $this->assertZeroDownstreamWrites();
            $document->delete();
        }
    }

    public function test_real_identity_only_file_stage_to_g2_audit(): void
    {
        $identity = $this->runAvailableStage('stakeholder_identity', 'person14/g2-identity.pdf', 'passport_front', '70000000-0000-4000-8000-000000000001');
        $resolved = app(NiumHkManualKycDocumentResolver::class)->resolve(KycRelatedPerson::query()->findOrFail(14));
        $this->assertSame($identity->id, $resolved['identity']->id);
        $this->assertNull($resolved['proof_of_address']);
        $this->mock(NiumService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('post'));
        try { app(NiumHkStakeholderSubmitKycRetryOneShotRunner::class)->audit(); $this->fail('RFI acknowledgement was not required.'); } catch (RuntimeException $e) { $this->assertSame('HOLD_RFI_ACKNOWLEDGEMENT_REQUIRED', $e->getMessage()); }
        $result = app(NiumHkStakeholderSubmitKycRetryOneShotRunner::class)->audit(rfiAcknowledged: true);
        $this->assertSame('READY_FOR_SEPARATE_HUMAN_APPROVAL', $result['terminal']);
        $this->assertSame('POA_MISSING_RFI_EXPECTED', $result['proof_of_address_status']);
        $this->assertZeroDownstreamWrites();
    }

    public function test_real_identity_and_poa_file_stages_build_manual_g2_payload(): void
    {
        $identity = $this->runAvailableStage('stakeholder_identity', 'person14/g2-passport.pdf', 'passport_front', '70000000-0000-4000-8000-000000000002');
        $poa = $this->runAvailableStage('stakeholder_proof_of_address', 'person14/g2-statement.pdf', 'bank_statement', '70000000-0000-4000-8000-000000000003');
        $person = KycRelatedPerson::query()->findOrFail(14);
        $resolved = app(NiumHkManualKycDocumentResolver::class)->resolve($person);
        $payload = app(NiumHkSubmitKycPayloadFactory::class)->buildManual($person, '7609d9d1-9d37-4e08-9197-602d792f7a2e', $resolved['identity'], $resolved['proof_of_address']);
        $this->assertSame($identity->id, $resolved['identity']->id);
        $this->assertSame($poa->id, $resolved['proof_of_address']->id);
        $this->assertSame('INDIVIDUAL_STAKEHOLDER', $payload['entityType']);
        $this->assertSame('MANUAL_KYC', $payload['kycMode']);
        $this->assertSame('HK', $payload['region']);
        $this->assertSame('7609d9d1-9d37-4e08-9197-602d792f7a2e', $payload['entityReferenceId']);
        $this->mock(NiumService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('post'));
        $this->assertSame('READY_FOR_SEPARATE_HUMAN_APPROVAL', app(NiumHkStakeholderSubmitKycRetryOneShotRunner::class)->audit()['terminal']);
        $this->assertZeroDownstreamWrites();
    }

    private function prepare(string $role, string $path, string $mime, string $type): KycDocument
    {
        $bytes = $mime === 'application/pdf' ? "%PDF-1.4\n{$path}\n%%EOF" : "fixture-{$path}";
        Storage::disk('kyc_private')->put($path, $bytes);
        chmod(Storage::disk('kyc_private')->path($path), 0600);

        return app(NiumHkFactualPersonalDocumentPreparationService::class)->prepare($role, $path, hash('sha256', $bytes), $type);
    }

    private function attemptKey(string $reference): string
    {
        return 'ref_'.substr(hash('sha256', $reference), 0, 16);
    }

    private function continuationDocument(string $path): KycDocument
    {
        $document = $this->prepare('stakeholder_identity', $path, 'application/pdf', 'passport_front');
        $fileId = '30000000-0000-4000-8000-'.str_pad((string) $document->id, 12, '0', STR_PAD_LEFT);
        $document->forceFill(['metadata' => [...$document->metadata, 'nium_file_id' => $fileId, 'nium_file_state' => 'PROCESSING', 'file_stage_execution_marker' => 'nium-hk-factual-person14-identity-file-create-v1', 'file_stage_state' => 'HOLD_FILE_NOT_AVAILABLE']])->save();
        $this->fileLog($document->id, 'POST', 200);
        return $document->fresh();
    }

    private function fileLog(int $documentId, string $method, int $status): ApiRequestLog
    {
        return ApiRequestLog::query()->forceCreate(['provider_id' => 1, 'user_id' => 9, 'operation' => $method === 'POST' ? 'file_create' : 'file_details', 'request_method' => $method, 'request_url' => '/safe/file', 'request_body' => ['kyc_document_id' => $documentId], 'response_status' => $status, 'transport_outcome' => 'response_received', 'is_success' => $status < 400]);
    }

    private function documentLogs(int $documentId)
    {
        return ApiRequestLog::query()->get()->filter(fn (ApiRequestLog $log): bool => (int) data_get($log->request_body, 'kyc_document_id') === $documentId);
    }

    private function lockedSnapshots(): array { return [104 => ApiRequestLog::query()->findOrFail(104)->getRawOriginal(), 106 => ApiRequestLog::query()->findOrFail(106)->getRawOriginal()]; }
    private function assertLockedEvidenceUnchanged(array $before): void { foreach ([104, 106] as $id) { $after = ApiRequestLog::query()->findOrFail($id)->getRawOriginal(); ksort($before[$id]); ksort($after); $this->assertSame($before[$id], $after); } }
    private function assertZeroDownstreamWrites(): void { foreach (['submit_kyc', 'onboarding_simulation_submit_kyc', 'assign_payment_id', 'beneficiary_create', 'beneficiary_update', 'transfer_create'] as $operation) { $query = ApiRequestLog::query()->where('operation', $operation)->where('request_method', 'POST'); if ($operation === 'submit_kyc') $query->where('id', '>', 106); $this->assertSame(0, $query->count(), $operation); } }
    private function runAvailableStage(string $role, string $path, string $type, string $fileId): KycDocument { $document = $this->prepare($role, $path, 'application/pdf', $type); $this->mock(NiumFileService::class, function (MockInterface $mock) use ($document, $fileId): void { $mock->shouldReceive('createFile')->once()->andReturnUsing(function (KycDocument $model) use ($document, $fileId): array { $this->fileLog($document->id, 'POST', 200); $model->forceFill(['metadata' => [...$model->metadata, 'nium_file_id' => $fileId, 'nium_file_state' => 'PROCESSING']])->save(); return ['id' => $fileId]; }); $mock->shouldReceive('refreshDocumentState')->once()->andReturnUsing(function (KycDocument $model) use ($document, $fileId): array { $this->fileLog($document->id, 'GET', 200); $model->forceFill(['metadata' => [...$model->metadata, 'nium_file_state' => 'AVAILABLE', 'nium_available_at' => now()->toISOString()]])->save(); return ['id' => $fileId, 'state' => 'AVAILABLE']; }); }); app(NiumHkFactualPersonalFileOneShotRunner::class)->run($document->id, $role, true); return $document->fresh(); }
}
