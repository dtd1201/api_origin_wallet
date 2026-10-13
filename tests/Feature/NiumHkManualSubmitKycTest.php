<?php

namespace Tests\Feature;

use App\Jobs\Nium\ContinueNiumHkManualKycJob;
use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\IntegrationProvider;
use App\Models\KycProfile;
use App\Models\KycProfileCorrection;
use App\Models\NiumManualKycContinuation;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use App\Services\Nium\NiumCurrentBiometricKyc;
use App\Services\Nium\NiumCustomerDocumentPreparationService;
use App\Services\Nium\NiumHkManualSubmitKycService;
use App\Services\Nium\NiumHkSubmitKycService;
use App\Services\Nium\NiumSafeValueProjector;
use App\Services\Nium\NiumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

final class NiumHkManualSubmitKycTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_permission_is_required(): void
    {
        $customer = User::factory()->create();
        $normalUser = User::factory()->create();

        $this->withToken($this->issueTokenFor($normalUser))
            ->postJson("/api/admin/users/{$customer->id}/kyc-profile/submit-kyc")
            ->assertForbidden();
    }

    public function test_manual_submit_uses_metadata_applicant_without_entity_webhook_and_persists_real_biometric_url(): void
    {
        $context = $this->context();
        $biometricUrl = 'https://verify.example.test/real-nium-session-token';
        $calls = $this->mockResponse($context, [
            'biometricUrl' => $biometricUrl,
            'customerHashId' => $context['account']->external_customer_id,
            'entityType' => 'APPLICANT',
            'externalId' => $context['external_id'],
            'kycMode' => 'BIOMETRIC_KYC',
            'kycStatus' => 'initiated',
            'referenceId' => $context['provider_reference_id'],
        ]);

        $result = app(NiumHkManualSubmitKycService::class)->submit($context['user']);

        $this->assertDatabaseMissing('webhook_events', ['event_type' => 'CUSTOMER_ENTITY_KYC_STATUS']);
        $this->assertSame(1, $calls->count);
        $this->assertStringContainsString('/submitKyc', $calls->path);
        $this->assertSame('applicant', $calls->payload['entityType']);
        $this->assertSame('biometric_kyc', $calls->payload['kycMode']);
        $this->assertSame($context['provider_reference_id'], $calls->payload['entityReferenceId']);
        $this->assertStringStartsNotWith('origin-wallet-manual-', $calls->payload['entityReferenceId']);
        $this->assertSame($biometricUrl, $result['biometric_url']);
        $this->assertSame($context['provider_reference_id'], $result['provider_reference_id']);
        $this->assertSame('accepted', $result['state']);
        $this->assertSame('applicant', $result['entity_type']);
        $this->assertSame($context['external_id'], $result['external_id']);
        $this->assertSame('biometric_kyc', $result['kyc_mode']);

        $attempts = $context['account']->fresh()->metadata['nium_submit_kyc_attempts'];
        $attempt = collect($attempts)->sole();
        $this->assertTrue($attempt['manual_admin_action']);
        $this->assertStringStartsWith('origin-wallet-manual-', $attempt['manual_reference_id']);
        $this->assertNotSame($calls->payload['entityReferenceId'], $calls->externalReference);
        $this->assertSame($attempt['manual_reference_id'], $calls->externalReference);
        $this->assertSame($biometricUrl, $attempt['biometric_url']);
        $this->assertSame(substr(hash('sha256', $biometricUrl), 0, 16), $attempt['biometric_url_fingerprint']);
        $this->assertStringNotContainsString($biometricUrl, json_encode($context['account']->fresh()->toArray(), JSON_THROW_ON_ERROR));

        $serializedLogs = ApiRequestLog::query()->get()->toJson();
        $this->assertStringNotContainsString($biometricUrl, $serializedLogs);
        $this->assertDatabaseCount('user_provider_accounts', 1);
    }

    public function test_manual_submit_recovers_initiated_applicant_url_without_submit_or_customer_creation(): void
    {
        $context = $this->context();
        $context['account']->forceFill(['metadata' => []])->save();
        $biometricUrl = 'https://idv.nium.test/prod/jumio/start?referenceNumber=recovered';
        $calls = new class
        {
            public int $getCount = 0;
        };
        config()->set(
            'services.nium.customer_get_endpoint',
            '/api/v5/client/{clientHashId}/customer/{customerHashId}',
        );
        $this->mock(NiumService::class, function (MockInterface $mock) use ($calls, $context, $biometricUrl): void {
            $mock->shouldReceive('clientId')->once()->andReturn('client-id');
            $mock->shouldReceive('path')->once()->andReturnUsing(function (string $template, array $values) use ($context): string {
                $this->assertSame($context['account']->external_customer_id, $values['customer']);

                return "/api/v5/client/{$values['client']}/customer/{$values['customer']}";
            });
            $mock->shouldReceive('get')->once()->andReturnUsing(function () use ($calls, $context, $biometricUrl): Response {
                $calls->getCount++;

                return new Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                    'customerHashId' => $context['account']->external_customer_id,
                    'status' => 'pending',
                    'subStatus' => 'awaiting_kyc',
                    'applicant' => [
                        'entityType' => 'applicant',
                        'externalId' => $context['external_id'],
                        'referenceId' => $context['provider_reference_id'],
                        'kycMode' => 'biometric_kyc',
                        'kycStatus' => 'initiated',
                        'biometricUrl' => $biometricUrl,
                    ],
                ], JSON_THROW_ON_ERROR)));
            });
            $mock->shouldNotReceive('post');
        });

        $result = app(NiumHkManualSubmitKycService::class)->submit($context['user']);

        $this->assertSame(1, $calls->getCount);
        $this->assertSame('accepted', $result['state']);
        $this->assertSame('initiated', $result['kyc_status']);
        $this->assertSame($context['external_id'], $result['external_id']);
        $this->assertSame($context['provider_reference_id'], $result['provider_reference_id']);
        $this->assertSame($biometricUrl, $result['biometric_url']);
        $this->assertSame(substr(hash('sha256', $biometricUrl), 0, 16), $result['biometric_url_fingerprint']);
        $this->assertStringStartsWith('origin-wallet-manual-', $result['manual_reference_id']);
        $this->assertDatabaseCount('api_request_logs', 0);
        $this->assertDatabaseCount('user_provider_accounts', 1);
    }

    public function test_redirect_url_is_used_as_fallback(): void
    {
        $context = $this->context();
        $redirectUrl = 'https://verify.example.test/redirect-session-token';
        $this->mockResponse($context, [
            'redirectUrl' => $redirectUrl,
            'entityType' => 'applicant',
            'externalId' => $context['external_id'],
            'kycMode' => 'biometric_kyc',
            'kycStatus' => 'initiated',
            'referenceId' => 'nium-reference-redirect',
        ]);

        $result = app(NiumHkManualSubmitKycService::class)->submit($context['user']);

        $this->assertSame($redirectUrl, $result['biometric_url']);
    }

    public function test_retry_submits_existing_applicant_once_and_preserves_the_previous_attempt(): void
    {
        $context = $this->context();
        $oldAttempt = [
            'state' => 'accepted',
            'kyc_status' => 'retry',
            'kyc_mode' => 'biometric_kyc',
            'entity_type' => 'applicant',
            'external_id' => $context['external_id'],
            'manual_reference_id' => 'origin-wallet-manual-old-attempt',
            'provider_reference_id' => $context['provider_reference_id'],
            'entity_kyc_status' => 'retry',
            'biometric_url' => 'https://verify.example.test/old-session',
            'updated_at' => now()->subMinute()->toISOString(),
        ];
        $oldAttemptKey = 'ref_'.substr(hash('sha256', $context['provider_reference_id']), 0, 16);
        $metadata = $context['account']->metadata;
        $metadata['nium_entity_kyc_states']['ref_applicant']['kyc_status'] = 'unknown';
        $metadata['nium_submit_kyc_attempts'][$oldAttemptKey] = $oldAttempt;
        $context['account']->forceFill(['metadata' => $metadata])->save();
        WebhookEvent::query()->create([
            'provider_id' => $context['provider']->id,
            'event_id' => (string) Str::uuid(),
            'event_type' => 'CUSTOMER_ENTITY_KYC_STATUS',
            'external_resource_id' => $context['account']->external_customer_id,
            'payload' => [
                'customerHashId' => $context['account']->external_customer_id,
                'entityType' => 'applicant',
                'externalId' => $context['external_id'],
                'referenceId' => $context['provider_reference_id'],
                'kycMode' => 'biometric_kyc',
                'kycStatus' => 'retry',
            ],
            'processing_status' => 'processed',
            'processed_at' => now(),
        ]);

        $newUrl = 'https://verify.example.test/new-retry-session';
        $calls = $this->mockResponse($context, [
            'biometricUrl' => $newUrl,
            'entityType' => 'applicant',
            'externalId' => $context['external_id'],
            'kycMode' => 'biometric_kyc',
            'kycStatus' => 'initiated',
            'referenceId' => $context['provider_reference_id'],
        ]);

        $first = app(NiumHkManualSubmitKycService::class)->submit($context['user'], 'admin-request-1');
        $second = app(NiumHkManualSubmitKycService::class)->submit($context['user'], 'admin-request-1');

        $this->assertSame(1, $calls->count);
        $this->assertSame($first, $second);
        $this->assertSame('applicant', $calls->payload['entityType']);
        $this->assertSame('biometric_kyc', $calls->payload['kycMode']);
        $this->assertSame($context['provider_reference_id'], $calls->payload['entityReferenceId']);
        $this->assertSame('passport', $calls->payload['proofOfIdentityDocument'][0]['type']);
        $this->assertSame('P123', $calls->payload['proofOfIdentityDocument'][0]['identificationNumber']);
        $this->assertSame($newUrl, $first['biometric_url']);
        $this->assertNotSame($oldAttempt['manual_reference_id'], $first['manual_reference_id']);

        $attempts = $context['account']->fresh()->metadata['nium_submit_kyc_attempts'];
        $this->assertCount(2, $attempts);
        $this->assertSame($oldAttempt, $attempts[$oldAttemptKey]);
        $this->assertSame($newUrl, app(NiumCurrentBiometricKyc::class)->forUser($context['user'])['url']);
        $this->assertDatabaseCount('user_provider_accounts', 1);
    }

    public function test_retry_evidence_with_a_mismatched_reference_fails_closed(): void
    {
        $context = $this->context();
        $metadata = $context['account']->metadata;
        $metadata['nium_entity_kyc_states']['ref_applicant']['kyc_status'] = 'unknown';
        $metadata['nium_submit_kyc_attempts']['manual_old'] = [
            'state' => 'accepted',
            'kyc_mode' => 'biometric_kyc',
            'entity_type' => 'applicant',
            'external_id' => $context['external_id'],
            'provider_reference_id' => $context['provider_reference_id'],
            'entity_kyc_status' => 'retry',
        ];
        $context['account']->forceFill(['metadata' => $metadata])->save();
        WebhookEvent::query()->create([
            'provider_id' => $context['provider']->id,
            'event_id' => (string) Str::uuid(),
            'event_type' => 'CUSTOMER_ENTITY_KYC_STATUS',
            'external_resource_id' => $context['account']->external_customer_id,
            'payload' => [
                'entityType' => 'applicant',
                'externalId' => $context['external_id'],
                'referenceId' => (string) Str::uuid(),
                'kycMode' => 'biometric_kyc',
                'kycStatus' => 'retry',
            ],
            'processing_status' => 'processed',
            'processed_at' => now(),
        ]);
        $this->mock(NiumService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('post'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No unique eligible Nium applicant entity');

        app(NiumHkManualSubmitKycService::class)->submit($context['user']);
    }

    public function test_retry_rejects_ambiguous_applicant_entities(): void
    {
        $context = $this->context();
        $metadata = $context['account']->metadata;
        $metadata['nium_entity_kyc_states']['ref_applicant']['kyc_status'] = 'retry';
        $metadata['nium_entity_kyc_states']['ref_ambiguous'] = [
            ...$metadata['nium_entity_kyc_states']['ref_applicant'],
            'provider_reference_id' => (string) Str::uuid(),
        ];
        $context['account']->forceFill(['metadata' => $metadata])->save();

        $this->mock(NiumService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('post'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No unique eligible Nium applicant entity');

        app(NiumHkManualSubmitKycService::class)->submit($context['user']);
    }

    public function test_success_without_url_is_preserved_without_fabricating_one(): void
    {
        $context = $this->context();
        $this->mockResponse($context, [
            'entityType' => 'applicant',
            'externalId' => $context['external_id'],
            'kycMode' => 'biometric_kyc',
            'kycStatus' => 'initiated',
            'referenceId' => 'nium-reference-without-url',
        ]);

        $result = app(NiumHkManualSubmitKycService::class)->submit($context['user']);

        $this->assertSame('accepted', $result['state']);
        $this->assertArrayNotHasKey('biometric_url', $result);
        $attempt = collect($context['account']->fresh()->metadata['nium_submit_kyc_attempts'])->sole();
        $this->assertArrayNotHasKey('biometric_url', $attempt);
    }

    public function test_failed_manual_submit_attempt_is_recorded_with_internal_reference(): void
    {
        $context = $this->context();
        $calls = $this->mockResponse($context, [
            'errorCode' => 'invalid_input',
            'errors' => [['code' => 'invalid_input', 'field' => 'entityReferenceId']],
        ], 400);

        $result = app(NiumHkManualSubmitKycService::class)->submit($context['user']);

        $this->assertSame($context['provider_reference_id'], $calls->payload['entityReferenceId']);
        $this->assertStringStartsWith('origin-wallet-manual-', $calls->externalReference);
        $this->assertSame('rejected', $result['state']);
        $this->assertSame(400, $result['provider_http_status']);

        $attempt = collect($context['account']->fresh()->metadata['nium_submit_kyc_attempts'])->sole();
        $this->assertSame('rejected', $attempt['state']);
        $this->assertSame(400, $attempt['provider_http_status']);
        $this->assertSame($calls->externalReference, $attempt['manual_reference_id']);
        $this->assertArrayNotHasKey('biometric_url', $attempt);
    }

    public function test_submitted_without_changes_and_needs_more_info_corrections_do_not_block_manual_submit(): void
    {
        foreach (['submitted', 'needs_more_info'] as $status) {
            $context = $this->context();
            $this->correction($context, $status);
            $calls = $this->mockResponse($context, [
                'biometricUrl' => 'https://verify.example.test/'.$status,
                'entityType' => 'applicant',
                'externalId' => $context['external_id'],
                'kycMode' => 'biometric_kyc',
                'kycStatus' => 'initiated',
                'referenceId' => $context['provider_reference_id'],
            ]);

            $result = app(NiumHkManualSubmitKycService::class)->submit($context['user']);

            $this->assertSame('accepted', $result['state']);
            $this->assertSame(1, $calls->count);
            $this->app->forgetInstance(NiumService::class);
        }
    }

    public function test_submitted_field_correction_blocks_manual_submit_without_nium_call(): void
    {
        $context = $this->context();
        $correction = $this->correction($context, 'submitted');
        $proposed = $correction->proposed_values;
        $proposed['first_name'] = 'Updated';
        $correction->update([
            'requested_fields' => ['first_name'],
            'proposed_values' => $proposed,
        ]);
        $this->mock(NiumService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('post'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not applied to the verified Nium customer');

        app(NiumHkManualSubmitKycService::class)->submit($context['user']);
    }

    public function test_submitted_passport_correction_blocks_manual_submit_without_nium_call(): void
    {
        $context = $this->context();
        $correction = $this->correction($context, 'submitted');
        $correction->update([
            'requested_document_types' => ['passport'],
            'proposed_documents' => [[
                'type' => 'passport',
                'storage_disk' => 'kyc_private',
                'file_path' => 'kyc/'.$context['user']->id.'/documents/replacement-passport.pdf',
            ]],
        ]);
        $this->mock(NiumService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('post'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not applied to the verified Nium customer');

        app(NiumHkManualSubmitKycService::class)->submit($context['user']);
    }

    public function test_approved_and_unresolved_sync_corrections_block_manual_submit_without_nium_call(): void
    {
        foreach (['approved', 'sync_failed', 'unknown'] as $status) {
            $context = $this->context();
            $this->correction($context, $status);
            $this->mock(NiumService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('post'));

            try {
                app(NiumHkManualSubmitKycService::class)->submit($context['user']);
                $this->fail("Manual Submit KYC was not blocked for {$status}.");
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('must be verified as synced', $exception->getMessage());
            }

            $this->app->forgetInstance(NiumService::class);
        }
    }

    public function test_reviewed_correction_submits_hk_applicant_manual_kyc_once_with_existing_bindings_and_file_id(): void
    {
        $context = $this->context();
        $correction = $this->correction($context, 'manual_kyc_evidence_approved');
        $correction->update([
            'requested_fields' => ['first_name', 'last_name', 'date_of_birth'],
            'requested_document_types' => ['passport'],
            'proposed_values' => [
                'legal_name' => 'Correct Person', 'first_name' => 'Correct',
                'last_name' => 'Person', 'date_of_birth' => '1991-02-03',
            ],
        ]);
        $fileId = (string) Str::uuid();
        $context['person']->documents()->create([
            'kyc_profile_id' => $context['profile']->id, 'type' => 'passport', 'status' => 'manual_kyc_evidence_approved',
            'document_number' => 'NEWP123', 'issuing_country_code' => 'HK', 'expires_at' => '2099-12-31',
            'file_url' => 'private://reviewed-passport',
            'metadata' => [
                'source' => 'admin_reviewed_correction', 'source_correction_id' => $correction->id,
                'nium_document_type' => 'passport', 'nium_file_id' => $fileId, 'nium_file_state' => 'AVAILABLE',
            ],
        ]);
        $calls = $this->mockResponse($context, [
            'entityType' => 'applicant', 'externalId' => $context['external_id'],
            'kycMode' => 'manual_kyc', 'kycStatus' => 'initiated',
            'referenceId' => $context['provider_reference_id'],
        ]);

        $first = app(NiumHkManualSubmitKycService::class)->submit($context['user'], 'first-click');
        $second = app(NiumHkManualSubmitKycService::class)->submit($context['user'], 'second-click');

        $this->assertSame(1, $calls->count);
        $this->assertSame($first, $second);
        $this->assertSame('HK', $calls->payload['region']);
        $this->assertSame('applicant', $calls->payload['entityType']);
        $this->assertSame('manual_kyc', $calls->payload['kycMode']);
        $this->assertSame($context['provider_reference_id'], $calls->payload['entityReferenceId']);
        $this->assertArrayNotHasKey('isResident', $calls->payload);
        $this->assertArrayNotHasKey('firstName', $calls->payload);
        $this->assertArrayNotHasKey('lastName', $calls->payload);
        $this->assertArrayNotHasKey('dateOfBirth', $calls->payload);
        $this->assertSame([$fileId], $calls->payload['proofOfIdentityDocument'][0]['fileIds']);
        $this->assertSame('NEWP123', $calls->payload['proofOfIdentityDocument'][0]['identificationNumber']);
        $this->assertSame('Test Person', $context['person']->fresh()->legal_name);
        $this->assertSame('manual_kyc', $first['kyc_mode']);
        $this->assertSame($correction->id, $first['kyc_profile_correction_id']);
        $this->assertSame('manual_kyc_compliance_review', $correction->fresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'kyc.manual_submission_pending_nium_compliance_review')->count());

        $event = WebhookEvent::query()->create([
            'provider_id' => $context['provider']->id, 'event_id' => (string) Str::uuid(),
            'event_type' => 'CUSTOMER_ENTITY_KYC_STATUS',
            'external_resource_id' => $context['account']->external_customer_id,
            'payload' => [
                'customerHashId' => $context['account']->external_customer_id,
                'entityType' => 'applicant', 'externalId' => $context['external_id'],
                'referenceId' => $context['provider_reference_id'], 'kycMode' => 'manual_kyc',
                'kycStatus' => 'submitted',
            ],
            'processing_status' => 'processed', 'processed_at' => now(),
        ]);
        app(NiumHkSubmitKycService::class)->reconcileEntityWebhook($event);

        $this->assertSame('manual_kyc_compliance_review', $correction->fresh()->status);
        $this->assertCount(1, $context['account']->fresh()->metadata['nium_submit_kyc_attempts']);
        $this->assertSame('submitted', collect($context['account']->fresh()->metadata['nium_submit_kyc_attempts'])->sole()['entity_kyc_status']);
    }

    public function test_processing_manual_passport_persists_and_dispatches_a_delayed_continuation_without_submit_post(): void
    {
        Queue::fake();
        $context = $this->context();
        $correction = $this->correction($context, 'manual_kyc_evidence_approved');
        $document = $context['person']->documents()->create([
            'kyc_profile_id' => $context['profile']->id,
            'type' => 'passport',
            'status' => 'manual_kyc_evidence_approved',
            'document_number' => 'NEWP123',
            'issuing_country_code' => 'VN',
            'expires_at' => '2099-12-31',
            'file_url' => 'private://reviewed-passport',
            'metadata' => [
                'source' => 'admin_reviewed_correction',
                'source_correction_id' => $correction->id,
                'nium_document_type' => 'passport',
                'nium_file_id' => (string) Str::uuid(),
                'nium_file_state' => 'PROCESSING',
            ],
        ]);
        $this->mock(NiumCustomerDocumentPreparationService::class, function (MockInterface $mock) use ($document, $context): void {
            $mock->shouldReceive('prepareManualCorrectionDocument')
                ->once()->withArgs(fn ($candidate, $user): bool => $candidate->is($document) && $user->is($context['user']))
                ->andReturn('PROCESSING');
        });
        $this->mock(NiumService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('post'));

        $first = app(NiumHkManualSubmitKycService::class)->submit($context['user']);
        $second = app(NiumHkManualSubmitKycService::class)->submit($context['user']);

        $this->assertSame('file_processing', $first['state']);
        $this->assertSame($first['continuation_state'], $second['continuation_state']);
        $this->assertDatabaseHas('nium_manual_kyc_continuations', [
            'kyc_profile_correction_id' => $correction->id,
            'kyc_document_id' => $document->id,
            'state' => NiumManualKycContinuation::FILE_PROCESSING,
        ]);
        Queue::assertPushed(ContinueNiumHkManualKycJob::class, 1);
    }

    public function test_available_continuation_submits_once_and_duplicate_jobs_do_not_repost(): void
    {
        Queue::fake();
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.retry_after', 90);
        $context = $this->context();
        $correction = $this->correction($context, 'manual_kyc_evidence_approved');
        $fileId = (string) Str::uuid();
        $document = $context['person']->documents()->create([
            'kyc_profile_id' => $context['profile']->id,
            'type' => 'passport',
            'status' => 'manual_kyc_evidence_approved',
            'document_number' => 'NEWP123',
            'issuing_country_code' => 'VN',
            'expires_at' => '2099-12-31',
            'file_url' => 'private://reviewed-passport',
            'metadata' => [
                'source' => 'admin_reviewed_correction',
                'source_correction_id' => $correction->id,
                'nium_document_type' => 'passport',
                'nium_file_id' => $fileId,
                'nium_file_state' => 'PROCESSING',
            ],
        ]);
        $this->mock(NiumCustomerDocumentPreparationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('prepareManualCorrectionDocument')->once()->andReturn('PROCESSING');
        });
        $pending = app(NiumHkManualSubmitKycService::class)->submit($context['user']);
        $continuation = NiumManualKycContinuation::query()->sole();

        $metadata = $document->metadata;
        $metadata['nium_file_state'] = 'AVAILABLE';
        $document->forceFill(['metadata' => $metadata])->save();
        $this->app->forgetInstance(NiumCustomerDocumentPreparationService::class);
        $this->app->forgetInstance(NiumHkManualSubmitKycService::class);
        $this->mock(NiumCustomerDocumentPreparationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('prepareManualCorrectionDocument')->once()->andReturn('AVAILABLE');
        });
        $calls = $this->mockResponse($context, [
            'entityType' => 'applicant',
            'externalId' => $context['external_id'],
            'kycMode' => 'manual_kyc',
            'kycStatus' => 'initiated',
            'referenceId' => $context['provider_reference_id'],
        ]);

        $this->assertSame('file_processing', $pending['state']);
        $this->assertNull(app(NiumHkManualSubmitKycService::class)->continueManualKyc($continuation->id));
        $this->assertNull(app(NiumHkManualSubmitKycService::class)->continueManualKyc($continuation->id));
        $this->assertSame(1, $calls->count);
        $this->assertSame([$fileId], $calls->payload['proofOfIdentityDocument'][0]['fileIds']);
        $this->assertSame(NiumManualKycContinuation::COMPLIANCE_REVIEW, $continuation->fresh()->state);
        $this->assertSame('manual_kyc_compliance_review', $correction->fresh()->status);
    }

    public function test_duplicate_job_does_not_quarantine_an_active_submitting_claim(): void
    {
        $fixture = $this->manualContinuationFixture(NiumManualKycContinuation::SUBMITTING, [
            'claim_token' => (string) Str::uuid(),
            'claimed_at' => now(),
            'submit_started_at' => now(),
            'submit_claim_expires_at' => now()->addMinutes(5),
        ]);
        $this->mock(NiumService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('post'));

        $delay = app(NiumHkManualSubmitKycService::class)->continueManualKyc($fixture['continuation']->id);
        app(NiumHkManualSubmitKycService::class)->holdFailedContinuation(
            $fixture['continuation']->id,
            'duplicate_failed',
            'Synthetic duplicate failure.',
        );

        $this->assertGreaterThan(0, $delay);
        $this->assertSame(NiumManualKycContinuation::SUBMITTING, $fixture['continuation']->fresh()->state);
        $this->assertNull($fixture['continuation']->fresh()->completed_at);
    }

    public function test_expired_submitting_claim_becomes_unknown_without_reposting(): void
    {
        $fixture = $this->manualContinuationFixture(NiumManualKycContinuation::SUBMITTING, [
            'claim_token' => (string) Str::uuid(),
            'claimed_at' => now()->subMinutes(10),
            'submit_started_at' => now()->subMinutes(10),
            'submit_claim_expires_at' => now()->subSecond(),
        ]);
        $this->mock(NiumService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('post'));

        $this->assertNull(app(NiumHkManualSubmitKycService::class)->continueManualKyc($fixture['continuation']->id));

        $continuation = $fixture['continuation']->fresh();
        $this->assertSame(NiumManualKycContinuation::UNKNOWN, $continuation->state);
        $this->assertSame('submit_claim_expired', $continuation->hold_code);
        $this->assertNotNull($continuation->completed_at);
    }

    public function test_processing_file_get_timeout_moves_continuation_to_hold_without_post(): void
    {
        $fixture = $this->manualContinuationFixture();
        config()->set('cache.default', 'array');
        config()->set('services.nium.file_base_url', 'https://files.nium.test');
        config()->set('services.nium.client_id', 'client-id');
        config()->set('services.nium.auth.header_name', 'x-api-key');
        config()->set('services.nium.auth.header_value', 'secret');
        Http::fake(['*' => Http::failedConnection('synthetic timeout')]);
        $this->mock(NiumService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('post'));

        $this->assertNull(app(NiumHkManualSubmitKycService::class)->continueManualKyc($fixture['continuation']->id));

        $this->assertSame(NiumManualKycContinuation::HOLD, $fixture['continuation']->fresh()->state);
        Http::assertSentCount(1);
    }

    public function test_malformed_file_details_response_moves_continuation_to_hold_without_post(): void
    {
        $fixture = $this->manualContinuationFixture();
        config()->set('cache.default', 'array');
        config()->set('services.nium.file_base_url', 'https://files.nium.test');
        config()->set('services.nium.client_id', 'client-id');
        config()->set('services.nium.auth.header_name', 'x-api-key');
        config()->set('services.nium.auth.header_value', 'secret');
        Http::fake(['*' => Http::response(['state' => 'PROCESSING'], 200)]);
        $this->mock(NiumService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('post'));

        $this->assertNull(app(NiumHkManualSubmitKycService::class)->continueManualKyc($fixture['continuation']->id));

        $this->assertSame(NiumManualKycContinuation::HOLD, $fixture['continuation']->fresh()->state);
        Http::assertSentCount(1);
    }

    public function test_continuation_never_reuploads_when_bound_file_id_is_missing_or_changed(): void
    {
        foreach (['missing', 'changed'] as $scenario) {
            $fixture = $this->manualContinuationFixture();
            $metadata = $fixture['document']->metadata;
            if ($scenario === 'missing') {
                unset($metadata['nium_file_id']);
            } else {
                $metadata['nium_file_id'] = (string) Str::uuid();
            }
            $fixture['document']->forceFill(['metadata' => $metadata])->save();
            $this->mock(NiumCustomerDocumentPreparationService::class, function (MockInterface $mock): void {
                $mock->shouldNotReceive('prepareManualCorrectionDocument');
            });
            $this->mock(NiumService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('post'));

            $this->assertNull(app(NiumHkManualSubmitKycService::class)->continueManualKyc($fixture['continuation']->id));
            $this->assertSame(NiumManualKycContinuation::HOLD, $fixture['continuation']->fresh()->state, $scenario);
            $this->app->forgetInstance(NiumHkManualSubmitKycService::class);
            $this->app->forgetInstance(NiumCustomerDocumentPreparationService::class);
            $this->app->forgetInstance(NiumService::class);
        }
    }

    public function test_processing_reschedules_within_bounds_and_holds_at_deadline(): void
    {
        $fixture = $this->manualContinuationFixture();
        $this->mock(NiumCustomerDocumentPreparationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('prepareManualCorrectionDocument')->once()->andReturn('PROCESSING');
        });

        $delay = app(NiumHkManualSubmitKycService::class)->continueManualKyc($fixture['continuation']->id);

        $this->assertGreaterThan(0, $delay);
        $this->assertSame(NiumManualKycContinuation::FILE_PROCESSING, $fixture['continuation']->fresh()->state);
        $this->assertNull($fixture['continuation']->fresh()->claim_token);
        $this->assertNotNull($fixture['continuation']->fresh()->next_attempt_at);

        $fixture['continuation']->forceFill([
            'deadline_at' => now()->subSecond(),
            'next_attempt_at' => null,
        ])->save();
        $this->app->forgetInstance(NiumHkManualSubmitKycService::class);
        $this->assertNull(app(NiumHkManualSubmitKycService::class)->continueManualKyc($fixture['continuation']->id));
        $this->assertSame(NiumManualKycContinuation::HOLD, $fixture['continuation']->fresh()->state);
    }

    public function test_correction_approval_change_before_post_holds_without_submit(): void
    {
        $fixture = $this->manualContinuationFixture();
        $fixture['correction']->update(['status' => 'rejected']);
        $this->mock(NiumService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('post'));

        $this->assertNull(app(NiumHkManualSubmitKycService::class)->continueManualKyc($fixture['continuation']->id));

        $this->assertSame(NiumManualKycContinuation::HOLD, $fixture['continuation']->fresh()->state);
    }

    public function test_ambiguous_submit_response_becomes_unknown_and_is_never_reposted(): void
    {
        Queue::fake();
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.retry_after', 90);
        $fixture = $this->manualContinuationFixture();
        $metadata = $fixture['document']->metadata;
        $metadata['nium_file_state'] = 'AVAILABLE';
        $fixture['document']->forceFill(['metadata' => $metadata])->save();
        $this->mock(NiumCustomerDocumentPreparationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('prepareManualCorrectionDocument')->once()->andReturn('AVAILABLE');
        });
        $calls = $this->mockResponse($fixture['context'], [
            'entityType' => 'individual_stakeholder',
            'externalId' => $fixture['context']['external_id'],
            'kycMode' => 'manual_kyc',
            'kycStatus' => 'initiated',
            'referenceId' => $fixture['context']['provider_reference_id'],
        ]);

        $this->assertNull(app(NiumHkManualSubmitKycService::class)->continueManualKyc($fixture['continuation']->id));
        $this->assertNull(app(NiumHkManualSubmitKycService::class)->continueManualKyc($fixture['continuation']->id));

        $this->assertSame(1, $calls->count);
        $this->assertSame(NiumManualKycContinuation::UNKNOWN, $fixture['continuation']->fresh()->state);
        $this->assertSame('submit_response_review', $fixture['continuation']->fresh()->hold_code);
    }

    public function test_terminal_continuation_states_are_immutable_against_duplicate_jobs(): void
    {
        foreach ([
            NiumManualKycContinuation::UNKNOWN,
            NiumManualKycContinuation::HOLD,
            NiumManualKycContinuation::COMPLIANCE_REVIEW,
        ] as $state) {
            $fixture = $this->manualContinuationFixture($state, ['completed_at' => now()]);
            $this->assertNull(app(NiumHkManualSubmitKycService::class)->continueManualKyc($fixture['continuation']->id));
            app(NiumHkManualSubmitKycService::class)->holdFailedContinuation($fixture['continuation']->id, 'late_failure', 'Late duplicate failure.');
            $this->assertSame($state, $fixture['continuation']->fresh()->state);
        }
    }

    public function test_admin_sees_pending_as_202_and_terminal_unknown_as_failure(): void
    {
        $fixture = $this->manualContinuationFixture();
        $admin = User::factory()->create();
        $admin->roles()->create(['role_code' => 'admin']);
        $token = $this->issueTokenFor($admin);

        $this->withToken($token)
            ->postJson("/api/admin/users/{$fixture['context']['user']->id}/kyc-profile/submit-kyc")
            ->assertStatus(202)
            ->assertJsonPath('continuation_state', NiumManualKycContinuation::FILE_PROCESSING);

        $fixture['continuation']->forceFill([
            'state' => NiumManualKycContinuation::UNKNOWN,
            'completed_at' => now(),
            'hold_code' => 'submit_claim_expired',
        ])->save();

        $this->withToken($token)
            ->postJson("/api/admin/users/{$fixture['context']['user']->id}/kyc-profile/submit-kyc")
            ->assertStatus(502)
            ->assertJsonPath('continuation_state', NiumManualKycContinuation::UNKNOWN)
            ->assertJsonPath('hold_code', 'submit_claim_expired')
            ->assertJsonPath('compliance_review_pending', false);
    }

    public function test_reviewed_correction_accepts_strict_processed_retry_webhook_and_attempt_evidence(): void
    {
        $context = $this->context();
        $evidence = $this->manualCorrectionRetryEvidence($context);
        $historicAttempt = $context['account']->fresh()->metadata['nium_submit_kyc_attempts']['historic_biometric_applicant'];
        $this->assertArrayNotHasKey('webhook_id', $historicAttempt);
        $this->assertTrue(
            $evidence['submitted_event']->processed_at->greaterThan($historicAttempt['webhook_processed_at']),
        );
        $this->assertTrue(
            $evidence['retry_event']->processed_at->greaterThan($historicAttempt['entity_status_updated_at']),
        );
        $calls = $this->mockResponse($context, [
            'entityType' => 'applicant',
            'externalId' => $context['external_id'],
            'kycMode' => 'manual_kyc',
            'kycStatus' => 'initiated',
            'referenceId' => $context['provider_reference_id'],
        ]);

        $result = app(NiumHkManualSubmitKycService::class)->submit($context['user']);

        $this->assertSame(1, $calls->count);
        $this->assertSame('accepted', $result['state']);
        $this->assertSame('manual_kyc', $calls->payload['kycMode']);
        $this->assertSame($context['provider_reference_id'], $calls->payload['entityReferenceId']);
        $this->assertSame('manual_kyc_compliance_review', $evidence['correction']->fresh()->status);
    }

    public function test_reviewed_correction_retry_fallback_rejects_incomplete_or_contradictory_evidence(): void
    {
        $scenarios = [
            'missing webhook' => function (array $context, array $evidence): void {
                $evidence['retry_event']->delete();
            },
            'unprocessed webhook' => function (array $context, array $evidence): void {
                $evidence['retry_event']->update(['processing_status' => 'received', 'processed_at' => null]);
            },
            'different customer hash' => function (array $context, array $evidence): void {
                $payload = $evidence['retry_event']->payload;
                $payload['customerHashId'] = (string) Str::uuid();
                $evidence['retry_event']->update(['payload' => $payload]);
            },
            'different applicant external id' => function (array $context, array $evidence): void {
                $payload = $evidence['retry_event']->payload;
                $payload['externalId'] = 'origin-wallet-applicant-999999';
                $evidence['retry_event']->update(['payload' => $payload]);
            },
            'different applicant reference id' => function (array $context, array $evidence): void {
                $payload = $evidence['retry_event']->payload;
                $payload['referenceId'] = (string) Str::uuid();
                $evidence['retry_event']->update(['payload' => $payload]);
            },
            'stakeholder webhook' => function (array $context, array $evidence): void {
                $payload = $evidence['retry_event']->payload;
                $payload['entityType'] = 'individual_stakeholder';
                $evidence['retry_event']->update(['payload' => $payload]);
            },
            'newer contradictory applicant state' => function (array $context, array $evidence): void {
                $this->entityWebhook($context, 'rejected', now()->addSecond());
            },
            'multiple retry applicants' => function (array $context, array $evidence): void {
                $this->entityWebhook($context, 'retry', now()->addSecond(), [
                    'externalId' => 'origin-wallet-applicant-999999',
                    'referenceId' => (string) Str::uuid(),
                ]);
            },
            'multiple matching retry webhooks' => function (array $context, array $evidence): void {
                $this->entityWebhook($context, 'retry', now()->addSecond());
            },
            'multiple matching attempts' => function (array $context, array $evidence): void {
                $metadata = $context['account']->fresh()->metadata;
                $metadata['nium_submit_kyc_attempts']['duplicate'] = $metadata['nium_submit_kyc_attempts']['historic_biometric_applicant'];
                $context['account']->forceFill(['metadata' => $metadata])->save();
            },
            'nonexistent webhook id' => function (array $context, array $evidence): void {
                $metadata = $context['account']->fresh()->metadata;
                $metadata['nium_submit_kyc_attempts']['historic_biometric_applicant']['webhook_id'] = 999999;
                $context['account']->forceFill(['metadata' => $metadata])->save();
            },
            'contradictory webhook id' => function (array $context, array $evidence): void {
                $contradictory = $this->entityWebhook(
                    $context,
                    'submitted',
                    now()->subMinutes(5)->startOfSecond(),
                    ['referenceId' => (string) Str::uuid()],
                );
                $metadata = $context['account']->fresh()->metadata;
                $metadata['nium_submit_kyc_attempts']['historic_biometric_applicant']['webhook_id'] = $contradictory->id;
                $metadata['nium_submit_kyc_attempts']['historic_biometric_applicant']['webhook_processed_at'] = $contradictory->processed_at->toISOString();
                $context['account']->forceFill(['metadata' => $metadata])->save();
            },
            'inconsistent reconciliation timestamp' => function (array $context, array $evidence): void {
                $metadata = $context['account']->fresh()->metadata;
                $metadata['nium_submit_kyc_attempts']['historic_biometric_applicant']['entity_status_updated_at'] = now()->addMinute()->toISOString();
                $context['account']->forceFill(['metadata' => $metadata])->save();
            },
        ];
        $this->mock(NiumService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('post'));

        foreach ($scenarios as $name => $mutate) {
            $context = $this->context();
            $evidence = $this->manualCorrectionRetryEvidence($context);
            $mutate($context, $evidence);

            try {
                app(NiumHkManualSubmitKycService::class)->submit($context['user']);
                $this->fail("Manual KYC retry fallback did not reject {$name}.");
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString(
                    'No unique eligible Nium applicant entity matches',
                    $exception->getMessage(),
                    $name,
                );
            }
        }
    }

    public function test_reviewed_correction_retry_fallback_strictly_accepts_a_matching_webhook_id(): void
    {
        $context = $this->context();
        $evidence = $this->manualCorrectionRetryEvidence($context);
        $metadata = $context['account']->fresh()->metadata;
        $metadata['nium_submit_kyc_attempts']['historic_biometric_applicant']['webhook_id'] = $evidence['origin_event']->id;
        $context['account']->forceFill(['metadata' => $metadata])->save();
        $calls = $this->mockResponse($context, [
            'entityType' => 'applicant',
            'externalId' => $context['external_id'],
            'kycMode' => 'manual_kyc',
            'kycStatus' => 'initiated',
            'referenceId' => $context['provider_reference_id'],
        ]);

        $result = app(NiumHkManualSubmitKycService::class)->submit($context['user']);

        $this->assertSame(1, $calls->count);
        $this->assertSame('accepted', $result['state']);
    }

    public function test_reviewed_correction_retry_fallback_keeps_document_isolation(): void
    {
        $context = $this->context();
        $evidence = $this->manualCorrectionRetryEvidence($context);
        $document = $context['person']->documents()
            ->where('status', 'manual_kyc_evidence_approved')
            ->sole();
        $metadata = $document->metadata;
        $metadata['source_correction_id'] = $evidence['correction']->id + 1;
        $document->update(['metadata' => $metadata]);
        $this->mock(NiumService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('post'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('reviewed correction passport is not available');

        app(NiumHkManualSubmitKycService::class)->submit($context['user']);
    }

    public function test_manual_kyc_wrong_success_contract_stays_evidence_approved_and_is_not_reported_as_compliance_pending(): void
    {
        $context = $this->context();
        $correction = $this->correction($context, 'manual_kyc_evidence_approved');
        $fileId = (string) Str::uuid();
        $context['person']->documents()->create([
            'kyc_profile_id' => $context['profile']->id,
            'type' => 'passport',
            'status' => 'manual_kyc_evidence_approved',
            'document_number' => 'NEWP123',
            'issuing_country_code' => 'HK',
            'expires_at' => '2099-12-31',
            'file_url' => 'private://reviewed-passport',
            'metadata' => [
                'source' => 'admin_reviewed_correction',
                'source_correction_id' => $correction->id,
                'nium_document_type' => 'passport',
                'nium_file_id' => $fileId,
                'nium_file_state' => 'AVAILABLE',
            ],
        ]);
        $calls = $this->mockResponse($context, [
            'entityType' => 'individual_stakeholder',
            'externalId' => $context['external_id'],
            'kycMode' => 'manual_kyc',
            'kycStatus' => 'initiated',
            'referenceId' => $context['provider_reference_id'],
        ]);

        $first = app(NiumHkManualSubmitKycService::class)->submit($context['user']);
        $second = app(NiumHkManualSubmitKycService::class)->submit($context['user']);

        $this->assertSame('response_review', $first['state']);
        $this->assertSame($first, $second);
        $this->assertSame(1, $calls->count);
        $this->assertSame('manual_kyc_evidence_approved', $correction->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'kyc.manual_submission_pending_nium_compliance_review',
            'entity_id' => (string) $correction->id,
        ]);
    }

    public function test_manual_kyc_unknown_submit_outcome_is_not_retried_or_marked_waiting_for_compliance(): void
    {
        $context = $this->context();
        $correction = $this->correction($context, 'manual_kyc_evidence_approved');
        $fileId = (string) Str::uuid();
        $context['person']->documents()->create([
            'kyc_profile_id' => $context['profile']->id,
            'type' => 'passport',
            'status' => 'manual_kyc_evidence_approved',
            'document_number' => 'NEWP123',
            'issuing_country_code' => 'HK',
            'expires_at' => '2099-12-31',
            'file_url' => 'private://reviewed-passport',
            'metadata' => [
                'source' => 'admin_reviewed_correction',
                'source_correction_id' => $correction->id,
                'nium_document_type' => 'passport',
                'nium_file_id' => $fileId,
                'nium_file_state' => 'AVAILABLE',
            ],
        ]);
        $calls = $this->mockResponse($context, ['message' => 'temporary provider failure'], 503);

        $first = app(NiumHkManualSubmitKycService::class)->submit($context['user']);
        $second = app(NiumHkManualSubmitKycService::class)->submit($context['user']);

        $this->assertSame('unknown', $first['state']);
        $this->assertSame($first, $second);
        $this->assertSame(1, $calls->count);
        $this->assertSame('manual_kyc_evidence_approved', $correction->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'kyc.manual_submission_pending_nium_compliance_review',
            'entity_id' => (string) $correction->id,
        ]);
    }

    private function context(): array
    {
        $provider = IntegrationProvider::query()->firstOrCreate(
            ['code' => 'nium'],
            ['name' => 'Nium', 'status' => 'active'],
        );
        $user = User::factory()->create();
        $profile = KycProfile::query()->create([
            'user_id' => $user->id,
            'status' => 'approved',
            'applicant_type' => 'business',
            'legal_name' => 'HK Test Ltd',
            'address_line1' => '1 Test Road',
            'city' => 'Hong Kong',
            'country_code' => 'HK',
            'metadata' => ['nium_region' => 'HK', 'nium_kyc_type' => 'full'],
        ]);
        $person = $profile->relatedPersons()->create([
            'relationship_type' => 'applicant',
            'status' => 'approved',
            'legal_name' => 'Test Person',
            'ownership_percentage' => 100,
            'residence_country_code' => 'VN',
        ]);
        $person->documents()->create([
            'kyc_profile_id' => $profile->id,
            'type' => 'passport',
            'status' => 'approved',
            'document_number' => 'P123',
            'issuing_country_code' => 'HK',
            'expires_at' => '2099-12-31',
            'file_url' => 'private://passport',
        ]);
        $account = UserProviderAccount::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'external_customer_id' => 'ed61adf3-ad32-4a05-96a1-909318194002',
            'customer_id_verified_at' => now(),
            'reconciliation_status' => 'reconciled',
            'status' => 'pending',
            'metadata' => [],
        ]);
        $externalId = 'origin-wallet-applicant-'.$person->id;
        $providerReferenceId = '7205cfe0-bf31-415e-b07d-e911b7e9d58e';
        $account->forceFill(['metadata' => [
            'nium_entity_kyc_states' => [
                'ref_applicant' => [
                    'entity_type' => 'applicant',
                    'external_id' => $externalId,
                    'kyc_status' => 'kyc_required',
                    'kyc_mode' => 'biometric_kyc',
                    'provider_reference_id' => $providerReferenceId,
                    'updated_at' => now()->toISOString(),
                ],
            ],
        ]])->save();
        WebhookEvent::query()->create([
            'provider_id' => $provider->id,
            'event_id' => (string) Str::uuid(),
            'event_type' => 'CUSTOMER_STATUS_WEBHOOK',
            'external_resource_id' => $account->external_customer_id,
            'payload' => [
                'customerHashId' => $account->external_customer_id,
                'status' => 'pending',
                'subStatus' => 'awaiting_kyc',
            ],
            'processing_status' => 'processed',
            'processed_at' => now(),
        ]);

        return compact('provider', 'user', 'profile', 'person', 'account', 'externalId') + [
            'external_id' => $externalId,
            'provider_reference_id' => $providerReferenceId,
        ];
    }

    private function mockResponse(array $context, array $body, int $status = 200): object
    {
        $needsCustomerGet = ($body['kycMode'] ?? null) !== 'manual_kyc'
            && $status >= 200 && $status < 300
            && ! filled($body['biometricUrl'] ?? null)
            && ! filled($body['redirectUrl'] ?? null);
        $calls = new class
        {
            public int $count = 0;

            public string $path = '';

            public array $payload = [];

            public string $externalReference = '';
        };

        $this->mock(NiumService::class, function (MockInterface $mock) use ($calls, $context, $body, $status, $needsCustomerGet): void {
            $mock->shouldReceive('clientId')->times($needsCustomerGet ? 2 : 1)->andReturn('client-id');
            $mock->shouldReceive('path')->times($needsCustomerGet ? 2 : 1)->andReturnUsing(function (string $template, array $values) use ($context): string {
                $this->assertSame($context['account']->external_customer_id, $values['customer']);

                return str_contains($template, 'submitKyc')
                    ? "/api/v5/client/{$values['client']}/customer/{$values['customer']}/submitKyc"
                    : "/api/v5/client/{$values['client']}/customer/{$values['customer']}";
            });
            $mock->shouldReceive('post')->once()->andReturnUsing(function (...$arguments) use ($calls, $context, $body, $status): Response {
                $calls->count++;
                $calls->path = $arguments[0];
                $calls->payload = $arguments[1];
                $calls->externalReference = $arguments[5];
                ApiRequestLog::query()->create([
                    'provider_id' => $context['provider']->id,
                    'user_id' => $context['user']->id,
                    'operation' => 'submit_kyc',
                    'external_reference' => $arguments[5],
                    'request_method' => 'POST',
                    'request_url' => '/safe/submitKyc',
                    'response_status' => $status,
                    'response_body' => app(NiumSafeValueProjector::class)->apiResponseBody($body, $status),
                    'is_success' => $status >= 200 && $status < 300,
                ]);

                return new Response(new \GuzzleHttp\Psr7\Response($status, [], json_encode($body, JSON_THROW_ON_ERROR)));
            });
            if ($needsCustomerGet) {
                $mock->shouldReceive('get')->once()->andReturn(new Response(
                    new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                        'customerHashId' => $context['account']->external_customer_id,
                        'status' => 'pending',
                        'subStatus' => 'awaiting_kyc',
                        'applicant' => [
                            'externalId' => 'origin-wallet-applicant-other',
                            'referenceId' => 'other-reference',
                            'biometricUrl' => 'https://verify.example.test/other-applicant',
                        ],
                    ], JSON_THROW_ON_ERROR)),
                ));
            }
        });

        return $calls;
    }

    private function manualContinuationFixture(
        string $state = NiumManualKycContinuation::FILE_PROCESSING,
        array $overrides = [],
    ): array {
        $context = $this->context();
        $correction = $this->correction($context, 'manual_kyc_evidence_approved');
        $fileId = (string) Str::uuid();
        $document = $context['person']->documents()->create([
            'kyc_profile_id' => $context['profile']->id,
            'type' => 'passport',
            'status' => 'manual_kyc_evidence_approved',
            'document_number' => 'NEWP123',
            'issuing_country_code' => 'VN',
            'expires_at' => '2099-12-31',
            'file_url' => 'private://reviewed-passport',
            'metadata' => [
                'source' => 'admin_reviewed_correction',
                'source_correction_id' => $correction->id,
                'nium_document_type' => 'passport',
                'nium_file_id' => $fileId,
                'nium_file_state' => 'PROCESSING',
            ],
        ]);
        $continuation = NiumManualKycContinuation::query()->create(array_merge([
            'kyc_profile_correction_id' => $correction->id,
            'kyc_document_id' => $document->id,
            'user_provider_account_id' => $context['account']->id,
            'correction_version' => $correction->correction_version,
            'file_id_fingerprint' => hash('sha256', $fileId),
            'state' => $state,
            'poll_attempts' => 0,
            'next_attempt_at' => now(),
            'deadline_at' => now()->addHour(),
        ], $overrides));

        return compact('context', 'correction', 'document', 'continuation', 'fileId');
    }

    private function correction(array $context, string $status): KycProfileCorrection
    {
        $values = [
            'legal_name' => 'Test Person',
            'first_name' => 'Test',
            'last_name' => 'Person',
            'date_of_birth' => '1990-01-01',
        ];

        return $context['profile']->corrections()->create([
            'applicant_related_person_id' => $context['person']->id,
            'user_provider_account_id' => $context['account']->id,
            'correction_version' => 1,
            'source_fingerprint' => hash('sha256', json_encode($values, JSON_THROW_ON_ERROR)),
            'customer_hash_id' => $context['account']->external_customer_id,
            'applicant_reference_id' => $context['provider_reference_id'],
            'applicant_external_id' => $context['external_id'],
            'customer_hash_fingerprint' => hash('sha256', $context['account']->external_customer_id),
            'applicant_reference_fingerprint' => hash('sha256', $context['provider_reference_id']),
            'applicant_external_fingerprint' => hash('sha256', $context['external_id']),
            'before_snapshot' => $values,
            'proposed_values' => $values,
            'status' => $status,
            'active_slot' => in_array($status, [
                'needs_more_info', 'synced', 'manual_kyc_evidence_approved', 'manual_kyc_compliance_review',
            ], true) ? null : 1,
            'created_by_user_id' => $context['user']->id,
            'submitted_at' => now(),
        ]);
    }

    private function manualCorrectionRetryEvidence(array $context): array
    {
        $correction = $this->correction($context, 'manual_kyc_evidence_approved');
        $fileId = (string) Str::uuid();
        $context['person']->documents()->create([
            'kyc_profile_id' => $context['profile']->id,
            'type' => 'passport',
            'status' => 'manual_kyc_evidence_approved',
            'document_number' => 'NEWP123',
            'issuing_country_code' => 'VN',
            'expires_at' => '2099-12-31',
            'file_url' => 'private://reviewed-passport',
            'metadata' => [
                'source' => 'admin_reviewed_correction',
                'source_correction_id' => $correction->id,
                'nium_document_type' => 'passport',
                'nium_file_id' => $fileId,
                'nium_file_state' => 'AVAILABLE',
            ],
        ]);

        $originProcessedAt = now()->subMinutes(17)->startOfSecond();
        $submittedProcessedAt = now()->subSeconds(10)->startOfSecond();
        $entityStatusUpdatedAt = now()->subSecond()->startOfSecond();
        $originEvent = $this->entityWebhook($context, 'kyc_required', $originProcessedAt);
        $submittedEvent = $this->entityWebhook($context, 'submitted', $submittedProcessedAt);
        $retryEvent = $this->entityWebhook($context, 'retry', $entityStatusUpdatedAt->copy()->addSecond());
        $context['account']->forceFill(['metadata' => [
            'nium_entity_kyc_states' => [
                'unknown_applicant' => [
                    'entity_type' => 'applicant',
                    'kyc_status' => 'unknown',
                    'kyc_mode' => 'biometric_kyc',
                ],
            ],
            'nium_submit_kyc_attempts' => [
                'historic_biometric_applicant' => [
                    'state' => 'accepted',
                    'provider_http_status' => 200,
                    'kyc_mode' => 'biometric_kyc',
                    'entity_type' => 'applicant',
                    'external_id' => $context['external_id'],
                    'provider_reference_id' => $context['provider_reference_id'],
                    'entity_kyc_status' => 'retry',
                    'webhook_processed_at' => $originProcessedAt->toISOString(),
                    'entity_status_updated_at' => $entityStatusUpdatedAt->toISOString(),
                ],
            ],
        ]])->save();

        return [
            'correction' => $correction,
            'origin_event' => $originEvent,
            'submitted_event' => $submittedEvent,
            'retry_event' => $retryEvent,
        ];
    }

    private function entityWebhook(
        array $context,
        string $status,
        \DateTimeInterface $processedAt,
        array $payloadOverrides = [],
    ): WebhookEvent {
        return WebhookEvent::query()->create([
            'provider_id' => $context['provider']->id,
            'event_id' => (string) Str::uuid(),
            'event_type' => 'CUSTOMER_ENTITY_KYC_STATUS',
            'external_resource_id' => $context['account']->external_customer_id,
            'payload' => array_merge([
                'customerHashId' => $context['account']->external_customer_id,
                'entityType' => 'applicant',
                'externalId' => $context['external_id'],
                'referenceId' => $context['provider_reference_id'],
                'kycMode' => 'biometric_kyc',
                'kycStatus' => $status,
            ], $payloadOverrides),
            'processing_status' => 'processed',
            'processed_at' => $processedAt,
        ]);
    }

    private function issueTokenFor(User $user): string
    {
        $plainText = Str::random(48);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'test',
            'token_hash' => hash('sha256', $plainText),
            'expires_at' => now()->addDay(),
        ]);

        return $plainText;
    }
}
