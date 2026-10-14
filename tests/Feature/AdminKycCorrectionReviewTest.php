<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\IntegrationProvider;
use App\Models\KycDocumentUpload;
use App\Models\KycProfileCorrection;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Kyc\KycCorrectionApprovedSource;
use App\Services\Nium\NiumHkCorporateCustomerUpdatePayloadFactory;
use App\Services\Nium\NiumCustomerDocumentResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class AdminKycCorrectionReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_uploads_then_explicitly_approves_correction_bound_proof_of_address(): void
    {
        Storage::fake('kyc_private');
        config(['services.kyc.documents_disk' => 'kyc_private']);
        $context = $this->context();
        $context['applicant']->update(['residence_country_code' => 'VN']);
        $context['correction']->update(['status' => 'manual_kyc_evidence_approved', 'active_slot' => null]);
        $token = $this->issueTokenFor($this->legacyAdmin());
        $endpoint = $this->actionEndpoint($context, 'proof-of-address');
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";

        $uploaded = $this->withToken($token)->post($endpoint, [
            'type' => 'proof_of_address',
            'file' => UploadedFile::fake()->createWithContent('address.pdf', $pdf),
            'issued_at' => '2026-09-01',
            'address_line1' => 'Verified residence line',
            'city' => 'Ho Chi Minh City',
            'country_code' => 'VN',
        ])->assertCreated()
            ->assertJsonPath('document.status', 'pending_admin_review')
            ->assertJsonPath('document.verified_residence_address.country_code', 'VN')
            ->json('document');

        $this->assertSame('manual_kyc_evidence_approved', $context['correction']->fresh()->status);
        $this->assertDatabaseHas('kyc_document_uploads', [
            'user_id' => $context['user']->id,
            'kyc_profile_correction_id' => $context['correction']->id,
            'status' => 'referenced',
        ]);
        $this->withToken($token)
            ->postJson($endpoint.'/'.$uploaded['id'].'/approve')
            ->assertOk()->assertJsonPath('document.status', 'approved');

        $document = $context['applicant']->documents()->findOrFail($uploaded['id']);
        $this->assertSame($context['account']->id, (int) data_get($document->metadata, 'user_provider_account_id'));
        $this->assertNull(data_get($document->metadata, 'nium_file_id'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'kyc.correction_proof_of_address_approved']);
    }

    public function test_proof_of_address_approval_rejects_cross_customer_document_binding(): void
    {
        Storage::fake('kyc_private');
        config(['services.kyc.documents_disk' => 'kyc_private']);
        $first = $this->context();
        $second = $this->context();
        $first['correction']->update(['status' => 'manual_kyc_evidence_approved', 'active_slot' => null]);
        $second['correction']->update(['status' => 'manual_kyc_evidence_approved', 'active_slot' => null]);
        $token = $this->issueTokenFor($this->legacyAdmin());
        $uploaded = $this->withToken($token)->post($this->actionEndpoint($first, 'proof-of-address'), [
            'type' => 'proof_of_address',
            'file' => UploadedFile::fake()->create('address.pdf', 120, 'application/pdf'),
        ])->assertCreated()->json('document');

        $this->withToken($token)
            ->postJson($this->actionEndpoint($second, 'proof-of-address').'/'.$uploaded['id'].'/approve')
            ->assertStatus(422);
        $this->assertDatabaseHas('kyc_documents', ['id' => $uploaded['id'], 'status' => 'pending_admin_review']);
    }

    public function test_admin_approves_identity_and_passport_evidence_for_manual_compliance_review_without_customer_update(): void
    {
        Storage::fake('kyc_private');
        config(['services.kyc.documents_disk' => 'kyc_private', 'services.nium.customer_update_enabled' => false]);
        $context = $this->context();
        config(['services.nium.customer_update_enabled' => false]);
        $oldFileId = (string) Str::uuid();
        $oldPassport = $context['applicant']->documents()->create([
            'kyc_profile_id' => $context['profile']->id,
            'type' => 'passport',
            'status' => 'approved',
            'document_number' => 'OLDP123',
            'issuing_country_code' => 'HK',
            'expires_at' => '2099-12-31',
            'file_url' => 'private://old-passport',
            'metadata' => ['nium_document_type' => 'passport', 'nium_file_id' => $oldFileId],
        ]);
        $path = 'kyc/'.$context['user']->id.'/documents/manual-passport.pdf';
        $contents = 'reviewed-passport-evidence';
        Storage::disk('kyc_private')->put($path, $contents);
        $document = [
            'type' => 'passport', 'file_url' => 'private://reviewed-passport',
            'storage_disk' => 'kyc_private', 'file_path' => $path, 'original_name' => 'passport.pdf',
            'mime_type' => 'application/pdf', 'file_size' => strlen($contents),
            'file_hash' => hash('sha256', $contents), 'document_number' => 'P1234567',
            'issuing_country_code' => 'HK', 'expires_at' => '2099-12-31',
        ];
        $context['correction']->update([
            'requested_fields' => ['first_name', 'last_name', 'date_of_birth'],
            'requested_document_types' => ['passport'],
            'proposed_documents' => [$document],
        ]);
        KycDocumentUpload::query()->create([
            'user_id' => $context['user']->id, 'kyc_profile_correction_id' => $context['correction']->id,
            'storage_disk' => 'kyc_private', 'file_path' => $path, 'file_hash' => $document['file_hash'],
            'mime_type' => 'application/pdf', 'file_size' => strlen($contents), 'status' => 'referenced',
            'referenced_at' => now(),
        ]);

        $token = $this->issueTokenFor($this->legacyAdmin());
        $this->withToken($token)
            ->postJson($this->actionEndpoint($context, 'approve'), $this->reviewPayload($context))
            ->assertOk()
            ->assertJsonPath('correction.status', 'manual_kyc_evidence_approved');
        $this->withToken($token)
            ->postJson($this->actionEndpoint($context, 'approve'), $this->reviewPayload($context))
            ->assertOk()->assertJsonPath('idempotent', true);

        $canonical = $context['applicant']->documents()->where('file_path', $path)->sole();
        $this->assertSame('manual_kyc_evidence_approved', $canonical->status);
        $this->assertSame($context['correction']->id, data_get($canonical->metadata, 'source_correction_id'));
        $resolvedPassports = app(NiumCustomerDocumentResolver::class)->relatedPersonDocuments($context['applicant']->fresh('documents'));
        $this->assertFalse($resolvedPassports->contains('id', $canonical->id));
        $this->assertTrue($resolvedPassports->contains('id', $oldPassport->id));
        $this->assertSame($oldFileId, data_get($oldPassport->fresh()->metadata, 'nium_file_id'));
        $this->assertNull($context['correction']->fresh()->active_slot);
        $this->assertSame('OLD GIVEN', $context['applicant']->fresh()->first_name);
        $this->assertSame(1, $context['applicant']->documents()->where('file_path', $path)->count());
        $this->assertDatabaseCount('kyc_correction_sync_attempts', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'kyc.correction_manual_kyc_review_approved']);
        Http::assertNothingSent();
    }

    public function test_authorized_admin_can_view_before_after_and_approve_without_mutating_live_kyc_or_calling_nium(): void
    {
        Http::preventStrayRequests();
        $context = $this->context();
        $admin = $this->legacyAdmin();

        $this->withToken($this->issueTokenFor($admin))
            ->getJson($this->indexEndpoint($context['user']))
            ->assertOk()
            ->assertJsonPath('data.0.before_values.first_name', 'OLD GIVEN')
            ->assertJsonPath('data.0.proposed_values.first_name', 'NEW GIVEN')
            ->assertJsonPath('permissions.approve', true)
            ->assertJsonPath('permissions.request_changes', true);

        $response = $this->withToken($this->issueTokenFor($admin))
            ->postJson($this->actionEndpoint($context, 'approve'), $this->reviewPayload($context))
            ->assertOk()
            ->assertJsonPath('correction.status', 'approved')
            ->assertJsonPath('idempotent', false);

        $this->assertSame('OLD LEGAL NAME', $context['applicant']->fresh()->legal_name);
        $this->assertSame('OLD GIVEN', $context['applicant']->fresh()->first_name);
        $this->assertSame('submitted', $context['submission']->fresh()->status);
        $this->assertSame($context['customer_hash_id'], $context['account']->fresh()->external_customer_id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'kyc.correction_approved',
            'entity_id' => (string) $context['correction']->id,
        ]);
        $this->assertStringNotContainsString('NEW GIVEN', json_encode(
            AuditLog::query()->where('action', 'kyc.correction_approved')->sole()->new_data,
            JSON_THROW_ON_ERROR,
        ));
        Http::assertNothingSent();
        $this->assertNotEmpty($response->json('correction.customer_hash_fingerprint'));
    }

    public function test_request_changes_requires_reason_and_releases_active_slot_for_next_version(): void
    {
        $context = $this->context();
        $admin = $this->legacyAdmin();
        $token = $this->issueTokenFor($admin);

        $this->withToken($token)->postJson($this->actionEndpoint($context, 'request-changes'), $this->reviewPayload($context))
            ->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->withToken($token)->postJson($this->actionEndpoint($context, 'request-changes'), [
            ...$this->reviewPayload($context),
            'reason' => 'Please correct the passport surname.',
        ])->assertOk()->assertJsonPath('correction.status', 'needs_more_info');

        $correction = $context['correction']->fresh();
        $this->assertNull($correction->active_slot);
        $this->assertSame('Please correct the passport surname.', $correction->review_note);
        $this->assertSame('OLD GIVEN', $context['applicant']->fresh()->first_name);
    }

    public function test_request_changes_for_admin_origin_preserves_scope_in_a_new_requested_version(): void
    {
        $context = $this->context();
        $context['correction']->update([
            'request_origin' => 'admin',
            'requested_fields' => ['last_name'],
            'requested_document_types' => ['passport'],
            'request_instruction' => 'Correct surname and passport.',
            'requested_by_user_id' => $this->legacyAdmin()->id,
        ]);
        $admin = $this->legacyAdmin();

        $this->withToken($this->issueTokenFor($admin))->postJson($this->actionEndpoint($context, 'request-changes'), [
            ...$this->reviewPayload($context), 'reason' => 'Passport surname is still inconsistent.',
        ])->assertOk()->assertJsonPath('correction.status', 'needs_more_info');

        $next = KycProfileCorrection::query()->where('kyc_profile_id', $context['profile']->id)
            ->where('active_slot', 1)->sole();
        $this->assertSame(2, $next->correction_version);
        $this->assertSame('requested', $next->status);
        $this->assertSame(['last_name'], $next->requested_fields);
        $this->assertSame(['passport'], $next->requested_document_types);
        $this->assertSame('Passport surname is still inconsistent.', $next->request_instruction);
        $this->assertSame('needs_more_info', $context['correction']->fresh()->status);
        $this->assertDatabaseCount('kyc_profile_corrections', 2);
    }

    public function test_permission_and_cross_customer_boundaries_are_enforced(): void
    {
        $context = $this->context();
        $other = $this->context();
        $support = $this->adminWithRole('support_agent');
        $supportToken = $this->issueTokenFor($support);

        $this->withToken($supportToken)->getJson($this->indexEndpoint($context['user']))
            ->assertOk()->assertJsonPath('permissions.approve', false);
        $this->withToken($supportToken)->postJson($this->actionEndpoint($context, 'approve'), $this->reviewPayload($context))
            ->assertForbidden();

        $adminToken = $this->issueTokenFor($this->legacyAdmin());
        $crossEndpoint = "/api/admin/users/{$other['user']->id}/kyc-profile/corrections/{$context['correction']->id}";
        $this->withToken($adminToken)->getJson($crossEndpoint)->assertNotFound();
        $this->withToken($adminToken)->postJson($crossEndpoint.'/approve', $this->reviewPayload($context))->assertNotFound();
    }

    public function test_stale_source_is_rejected_and_repeated_same_decision_is_idempotent(): void
    {
        $context = $this->context();
        $admin = $this->legacyAdmin();
        $token = $this->issueTokenFor($admin);

        $this->withToken($token)->postJson($this->actionEndpoint($context, 'approve'), [
            ...$this->reviewPayload($context),
            'correction_version' => 999,
        ])->assertUnprocessable()->assertJsonValidationErrors('correction_version');

        $context['applicant']->update(['first_name' => 'CHANGED OUTSIDE CORRECTION']);
        $this->withToken($token)->postJson($this->actionEndpoint($context, 'approve'), $this->reviewPayload($context))
            ->assertUnprocessable()->assertJsonValidationErrors('correction_version');

        $context['applicant']->update(['first_name' => 'OLD GIVEN']);
        $this->withToken($token)->postJson($this->actionEndpoint($context, 'approve'), $this->reviewPayload($context))
            ->assertOk()->assertJsonPath('idempotent', false);
        $this->withToken($token)->postJson($this->actionEndpoint($context, 'approve'), $this->reviewPayload($context))
            ->assertOk()->assertJsonPath('idempotent', true);
        $this->assertSame(1, AuditLog::query()->where('action', 'kyc.correction_approved')->count());

        $this->withToken($token)->postJson($this->actionEndpoint($context, 'request-changes'), [
            ...$this->reviewPayload($context), 'reason' => 'Conflicting second decision',
        ])->assertUnprocessable()->assertJsonValidationErrors('correction');
    }

    public function test_sync_is_feature_gated_and_pending_customer_remains_blocked(): void
    {
        $context = $this->context();
        $admin = $this->legacyAdmin();
        config(['services.nium.customer_update_enabled' => false]);
        $context['correction']->update(['status' => 'approved']);

        $this->withToken($this->issueTokenFor($admin))->postJson($this->actionEndpoint($context, 'sync'))
            ->assertUnprocessable()->assertJsonValidationErrors('correction');

        config(['services.nium.customer_update_enabled' => true]);
        $context['account']->update(['provider_status' => 'pending', 'provider_sub_status' => 'awaiting_kyc']);
        $this->withToken($this->issueTokenFor($admin))->postJson($this->actionEndpoint($context, 'sync'))
            ->assertUnprocessable()->assertJsonValidationErrors('correction');
        Http::assertNothingSent();
        $this->assertSame('approved', $context['correction']->fresh()->status);
    }

    public function test_approval_is_blocked_before_it_can_lock_the_workflow_when_sync_has_no_path(): void
    {
        $context = $this->context();
        $token = $this->issueTokenFor($this->legacyAdmin());

        config(['services.nium.customer_update_enabled' => false]);
        $this->withToken($token)->postJson($this->actionEndpoint($context, 'approve'), $this->reviewPayload($context))
            ->assertUnprocessable()->assertJsonValidationErrors('correction');
        $this->assertSame('submitted', $context['correction']->fresh()->status);
        $this->assertNull($context['correction']->fresh()->approved_source_snapshot);

        config(['services.nium.customer_update_enabled' => true]);
        $context['account']->update(['provider_status' => 'pending', 'provider_sub_status' => 'awaiting_kyc']);
        $this->withToken($token)->postJson($this->actionEndpoint($context, 'approve'), $this->reviewPayload($context))
            ->assertUnprocessable()->assertJsonValidationErrors('correction');
        $this->assertSame('submitted', $context['correction']->fresh()->status);
    }

    public function test_admin_request_customer_submit_and_review_remain_recoverable_while_customer_update_is_disabled(): void
    {
        Http::preventStrayRequests();
        $context = $this->context();
        $admin = $this->legacyAdmin();
        config(['services.nium.customer_update_enabled' => false]);
        $context['account']->update(['provider_status' => 'pending', 'provider_sub_status' => 'awaiting_kyc']);
        $context['correction']->update([
            'status' => 'requested',
            'request_origin' => 'admin',
            'requested_fields' => ['first_name'],
            'requested_document_types' => [],
            'request_instruction' => 'Correct the passport given name.',
            'requested_by_user_id' => $admin->id,
            'proposed_values' => $context['correction']->before_snapshot,
        ]);

        $this->withToken($this->issueTokenFor($context['user']))->postJson(
            "/api/user/users/{$context['user']->id}/kyc-profile/applicant-correction",
            [
                'correction_id' => $context['correction']->id,
                'correction_version' => $context['correction']->correction_version,
                'source_fingerprint' => $context['correction']->source_fingerprint,
                'first_name' => 'CUSTOMER CORRECTED',
            ],
        )->assertCreated()->assertJsonPath('correction.status', 'submitted');

        $adminToken = $this->issueTokenFor($admin);
        $this->withToken($adminToken)->postJson($this->actionEndpoint($context, 'approve'), $this->reviewPayload($context))
            ->assertUnprocessable()->assertJsonValidationErrors('correction');
        $this->withToken($adminToken)->postJson($this->actionEndpoint($context, 'sync'))
            ->assertUnprocessable()->assertJsonValidationErrors('correction');

        $this->withToken($adminToken)->postJson($this->actionEndpoint($context, 'request-changes'), [
            ...$this->reviewPayload($context), 'reason' => 'Provider support is pending; please keep this editable.',
        ])->assertOk()->assertJsonPath('correction.status', 'needs_more_info');

        $next = KycProfileCorrection::query()->where('kyc_profile_id', $context['profile']->id)
            ->where('active_slot', 1)->sole();
        $this->assertSame('requested', $next->status);
        $this->assertSame(['first_name'], $next->requested_fields);
        $this->assertSame('submitted', $context['submission']->fresh()->status);
        $this->assertSame('OLD GIVEN', $context['applicant']->fresh()->first_name);
        $this->assertDatabaseCount('kyc_correction_sync_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_legacy_applicant_external_id_is_preserved_as_an_immutable_binding(): void
    {
        $context = $this->context(true);
        $token = $this->issueTokenFor($this->legacyAdmin());

        $this->withToken($token)->postJson($this->actionEndpoint($context, 'approve'), $this->reviewPayload($context))
            ->assertOk();
        $this->assertSame('origin-wallet-person-'.$context['applicant']->id, $context['correction']->fresh()->applicant_external_id);

        $context['correction']->fresh()->forceFill(['applicant_external_fingerprint' => str_repeat('0', 64)])->save();
        $this->withToken($token)->postJson($this->actionEndpoint($context, 'sync'))
            ->assertUnprocessable()->assertJsonValidationErrors('correction');
        Http::assertNothingSent();
    }

    public function test_approved_full_source_change_is_rejected_before_put(): void
    {
        $context = $this->context();
        $token = $this->issueTokenFor($this->legacyAdmin());
        $this->withToken($token)->postJson($this->actionEndpoint($context, 'approve'), $this->reviewPayload($context))->assertOk();
        $context['profile']->update(['business_name' => 'CHANGED AFTER APPROVAL']);

        $this->withToken($token)->postJson($this->actionEndpoint($context, 'sync'))
            ->assertUnprocessable()->assertJsonValidationErrors('correction');
        $this->assertDatabaseCount('kyc_correction_sync_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_approved_company_director_source_change_is_rejected_before_put(): void
    {
        $context = $this->context();
        $director = $context['profile']->companyDirectors()->create([
            'legal_name' => 'DIRECTOR ORIGINAL',
            'first_name' => 'DIRECTOR',
            'last_name' => 'ORIGINAL',
            'date_of_birth' => '1980-01-01',
            'position' => 'Director',
            'status' => 'approved',
        ]);
        $token = $this->issueTokenFor($this->legacyAdmin());
        $this->withToken($token)->postJson($this->actionEndpoint($context, 'approve'), $this->reviewPayload($context))->assertOk();
        $director->update(['last_name' => 'CHANGED']);

        $this->withToken($token)->postJson($this->actionEndpoint($context, 'sync'))
            ->assertUnprocessable()->assertJsonValidationErrors('correction');
        $this->assertDatabaseCount('kyc_correction_sync_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_put_then_get_verification_updates_live_applicant_once_without_create_or_submit_kyc(): void
    {
        $context = $this->context();
        $admin = $this->legacyAdmin();
        $this->markApproved($context);
        $context['account']->update(['provider_status' => 'rejected', 'provider_sub_status' => null]);
        config([
            'services.nium.customer_update_enabled' => true,
            'services.nium.customer_update_allowed_statuses' => ['clear', 'rejected'],
            'services.nium.base_url' => 'https://api.test',
            'services.nium.client_id' => 'client-hash',
            'services.nium.auth' => ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'secret'],
            'services.nium.webhook.static_header_name' => 'x-partner-key',
            'services.nium.webhook.static_header_value' => 'partner-secret',
        ]);

        $payload = [
            'type' => 'corporate', 'region' => 'HK', 'kycType' => 'full',
            'externalId' => $context['account']->external_reference,
            'applicant' => [
                'externalId' => 'origin-wallet-applicant-'.$context['applicant']->id,
                'referenceId' => $context['reference_id'], 'firstName' => 'NEW GIVEN',
                'lastName' => 'NEW SURNAME', 'dateOfBirth' => '1991-02-03',
            ],
            'stakeholders' => ['individual' => []],
            'documents' => [['type' => 'business_registration_doc', 'fileIds' => [(string) Str::uuid()]]],
        ];
        $factory = Mockery::mock(NiumHkCorporateCustomerUpdatePayloadFactory::class);
        $factory->shouldReceive('build')->once()->andReturn($payload);
        $this->app->instance(NiumHkCorporateCustomerUpdatePayloadFactory::class, $factory);

        Http::fake([
            'https://api.test/api/v5/client/client-hash/customer/'.$context['customer_hash_id'] => Http::sequence()
                ->push(['status' => 'accepted'], 200)
                ->push([
                    'customerHashId' => $context['customer_hash_id'],
                    'externalId' => $context['account']->external_reference,
                    'applicant' => $payload['applicant'],
                    'stakeholders' => ['individual' => []],
                ], 200),
        ]);

        $token = $this->issueTokenFor($admin);
        $this->withToken($token)->postJson($this->actionEndpoint($context, 'sync'))
            ->assertOk()->assertJsonPath('correction.status', 'synced');
        $this->assertSame('NEW LEGAL NAME', $context['applicant']->fresh()->legal_name);
        $this->assertSame('NEW GIVEN', $context['applicant']->fresh()->first_name);
        $this->assertDatabaseHas('kyc_correction_sync_attempts', ['status' => 'synced', 'active_slot' => null]);

        $this->withToken($token)->postJson($this->actionEndpoint($context, 'sync'))
            ->assertUnprocessable()->assertJsonValidationErrors('correction');
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_contains($request->url(), '/customer/'.$context['customer_hash_id'])
            && ! str_contains($request->url(), '/customers')
            && ! str_contains($request->url(), '/submitKyc'));
        $audit = AuditLog::query()->where('action', 'kyc.correction_nium_synced')->sole();
        $this->assertStringNotContainsString('NEW GIVEN', json_encode($audit->new_data, JSON_THROW_ON_ERROR));
    }

    public function test_deterministic_rejection_and_ambiguous_5xx_have_distinct_non_retryable_states(): void
    {
        config([
            'services.nium.customer_update_enabled' => true,
            'services.nium.customer_update_allowed_statuses' => ['rejected'],
            'services.nium.base_url' => 'https://api.test', 'services.nium.client_id' => 'client-hash',
            'services.nium.auth' => ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'secret'],
            'services.nium.webhook.static_header_name' => 'x-partner-key',
            'services.nium.webhook.static_header_value' => 'partner-secret',
        ]);
        $first = $this->context();
        $second = $this->context();
        foreach ([$first, $second] as $context) {
            $this->markApproved($context);
            $context['account']->update(['provider_status' => 'rejected', 'provider_sub_status' => null]);
        }
        $factory = Mockery::mock(NiumHkCorporateCustomerUpdatePayloadFactory::class);
        $factory->shouldReceive('build')->twice()->andReturnUsing(fn (User $customer, KycProfileCorrection $correction): array => [
            'type' => 'corporate', 'region' => 'HK', 'kycType' => 'full',
            'externalId' => $correction->providerAccount->external_reference,
            'applicant' => ['externalId' => 'origin-wallet-applicant-'.$correction->applicant_related_person_id],
            'stakeholders' => ['individual' => []],
        ]);
        $this->app->instance(NiumHkCorporateCustomerUpdatePayloadFactory::class, $factory);
        Http::fakeSequence()->push(['message' => 'invalid'], 422)->push(['message' => 'temporary'], 503);
        $token = $this->issueTokenFor($this->legacyAdmin());

        $this->withToken($token)->postJson($this->actionEndpoint($first, 'sync'))->assertUnprocessable();
        $this->assertSame('sync_failed', $first['correction']->fresh()->status);
        $this->assertSame('sync_failed', $first['correction']->syncAttempts()->sole()->status);

        $this->withToken($token)->postJson($this->actionEndpoint($second, 'sync'))->assertUnprocessable();
        $this->assertSame('unknown', $second['correction']->fresh()->status);
        $this->assertSame('unknown', $second['correction']->syncAttempts()->sole()->status);
        $this->withToken($token)->postJson($this->actionEndpoint($second, 'sync'))
            ->assertUnprocessable()->assertJsonValidationErrors('correction');
        Http::assertSentCount(2);
    }

    public function test_operator_can_close_only_a_verified_deterministic_failure_for_a_new_version(): void
    {
        $context = $this->context();
        $this->markApproved($context);
        $attempt = $context['correction']->syncAttempts()->create([
            'correction_version' => 1, 'request_id' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('d', 64), 'status' => 'sync_failed', 'active_slot' => 1,
            'http_status' => 422, 'failure_code' => 'provider_rejected',
            'started_by_user_id' => $this->legacyAdmin()->id, 'started_at' => now(), 'resolved_at' => now(),
        ]);
        $context['correction']->update(['status' => 'sync_failed']);
        $token = $this->issueTokenFor($this->legacyAdmin());

        $this->withToken($token)->postJson($this->actionEndpoint($context, 'resolve-failure'), [
            'request_id' => $attempt->request_id,
            'request_fingerprint' => $attempt->request_fingerprint,
            'reason' => 'Correct the rejected passport data and submit a new version.',
        ])->assertOk()->assertJsonPath('correction.status', 'needs_more_info');

        $this->assertNull($context['correction']->fresh()->active_slot);
        $this->assertNull($attempt->fresh()->active_slot);
        $this->assertSame('Correct the rejected passport data and submit a new version.', $context['correction']->fresh()->review_note);
    }

    public function test_failure_resolution_rejects_stale_attempt_evidence_and_unknown_outcomes(): void
    {
        $context = $this->context();
        $this->markApproved($context);
        $attempt = $context['correction']->syncAttempts()->create([
            'correction_version' => 1, 'request_id' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('e', 64), 'status' => 'sync_failed', 'active_slot' => 1,
            'http_status' => 422, 'failure_code' => 'provider_rejected',
            'started_by_user_id' => $this->legacyAdmin()->id, 'started_at' => now(), 'resolved_at' => now(),
        ]);
        $context['correction']->update(['status' => 'sync_failed']);
        $token = $this->issueTokenFor($this->legacyAdmin());

        $this->withToken($token)->postJson($this->actionEndpoint($context, 'resolve-failure'), [
            'request_id' => (string) Str::uuid(),
            'request_fingerprint' => $attempt->request_fingerprint,
            'reason' => 'Retry with corrected data.',
        ])->assertUnprocessable()->assertJsonValidationErrors('sync_attempt');
        $this->assertSame(1, $attempt->fresh()->active_slot);

        $attempt->update(['status' => 'unknown', 'http_status' => 503]);
        $context['correction']->update(['status' => 'unknown']);
        $this->withToken($token)->postJson($this->actionEndpoint($context, 'resolve-failure'), [
            'request_id' => $attempt->request_id,
            'request_fingerprint' => $attempt->request_fingerprint,
            'reason' => 'Retry with corrected data.',
        ])->assertUnprocessable()->assertJsonValidationErrors('correction');

        $this->assertSame('unknown', $context['correction']->fresh()->status);
        $this->assertSame(1, $attempt->fresh()->active_slot);
        Http::assertNothingSent();
    }

    private function context(bool $legacyExternalId = false): array
    {
        config(['services.nium.customer_update_enabled' => true]);
        $user = User::factory()->create(['status' => 'active', 'kyc_status' => 'verified']);
        $profile = $user->kycProfile()->create([
            'status' => 'verified', 'applicant_type' => 'business', 'legal_name' => 'TEST COMPANY',
            'business_name' => 'TEST COMPANY', 'address_line1' => '1 Test Road', 'city' => 'Hong Kong',
            'country_code' => 'HK', 'registered_country_code' => 'HK',
            'metadata' => ['nium_region' => 'HK', 'nium_kyc_type' => 'full'],
        ]);
        $applicant = $profile->relatedPersons()->create([
            'relationship_type' => 'applicant', 'status' => 'approved', 'legal_name' => 'OLD LEGAL NAME',
            'first_name' => 'OLD GIVEN', 'last_name' => 'OLD SURNAME', 'date_of_birth' => '1990-01-01',
            'ownership_percentage' => 25, 'metadata' => ['passport_name_fields_version' => 1],
        ]);
        $provider = IntegrationProvider::query()->firstOrCreate(['code' => 'nium'], ['name' => 'Nium', 'status' => 'active']);
        $customerHashId = (string) Str::uuid();
        $referenceId = (string) Str::uuid();
        $externalId = 'origin-wallet-'.($legacyExternalId ? 'person' : 'applicant').'-'.$applicant->id;
        $account = UserProviderAccount::query()->create([
            'user_id' => $user->id, 'provider_id' => $provider->id,
            'external_customer_id' => $customerHashId, 'external_reference' => (string) Str::uuid(),
            'status' => 'submitted', 'provider_status' => 'rejected', 'provider_sub_status' => null,
            'customer_id_verified_at' => now(), 'reconciliation_status' => 'reconciled',
            'metadata' => ['nium_entity_kyc_states' => ['ref_applicant' => [
                'entity_type' => 'applicant', 'kyc_status' => 'retry', 'kyc_mode' => 'biometric_kyc',
                'external_id' => $externalId, 'provider_reference_id' => $referenceId,
            ]]],
        ]);
        $submission = $profile->providerSubmissions()->create([
            'user_id' => $user->id, 'provider_id' => $provider->id, 'provider_account_id' => $account->id,
            'status' => 'submitted', 'submitted_at' => now(),
        ]);
        $before = ['legal_name' => 'OLD LEGAL NAME', 'first_name' => 'OLD GIVEN', 'last_name' => 'OLD SURNAME', 'date_of_birth' => '1990-01-01'];
        $fingerprint = hash('sha256', json_encode([
            'profile_id' => $profile->id, 'applicant_id' => $applicant->id, 'account_id' => $account->id,
            'customer_hash_id' => $customerHashId, 'applicant_reference_id' => $referenceId,
            'external_id' => $externalId, 'values' => $before,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $correction = $profile->corrections()->create([
            'applicant_related_person_id' => $applicant->id, 'user_provider_account_id' => $account->id,
            'correction_version' => 1, 'source_fingerprint' => $fingerprint,
            'customer_hash_id' => $customerHashId, 'applicant_reference_id' => $referenceId,
            'applicant_external_id' => $externalId,
            'customer_hash_fingerprint' => hash('sha256', $customerHashId),
            'applicant_reference_fingerprint' => hash('sha256', $referenceId),
            'applicant_external_fingerprint' => hash('sha256', $externalId),
            'before_snapshot' => $before,
            'proposed_values' => ['legal_name' => 'NEW LEGAL NAME', 'first_name' => 'NEW GIVEN', 'last_name' => 'NEW SURNAME', 'date_of_birth' => '1991-02-03'],
            'status' => 'submitted', 'active_slot' => 1, 'created_by_user_id' => $user->id, 'submitted_at' => now(),
        ]);

        return compact('user', 'profile', 'applicant', 'provider', 'account', 'submission', 'correction', 'fingerprint') + [
            'customer_hash_id' => $customerHashId, 'reference_id' => $referenceId,
        ];
    }

    private function markApproved(array $context): void
    {
        $source = app(KycCorrectionApprovedSource::class);
        $snapshot = $source->snapshot($context['profile']);
        $context['correction']->update([
            'status' => 'approved',
            'approved_source_snapshot' => $snapshot,
            'approved_source_fingerprint' => $source->fingerprint($snapshot),
        ]);
    }

    private function reviewPayload(array $context): array
    {
        return ['correction_version' => 1, 'source_fingerprint' => $context['fingerprint']];
    }

    private function indexEndpoint(User $user): string
    {
        return "/api/admin/users/{$user->id}/kyc-profile/corrections";
    }

    private function actionEndpoint(array $context, string $action): string
    {
        return $this->indexEndpoint($context['user']).'/'.$context['correction']->id.'/'.$action;
    }

    private function legacyAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->create(['role_code' => 'admin']);

        return $admin;
    }

    private function adminWithRole(string $code): User
    {
        $admin = User::factory()->create();
        $role = Role::query()->where('code', $code)->firstOrFail();
        $admin->roles()->create(['role_id' => $role->id, 'role_code' => $role->code]);

        return $admin;
    }

    private function issueTokenFor(User $user): string
    {
        $plainToken = Str::random(80);
        ApiToken::query()->create([
            'user_id' => $user->id, 'name' => 'test-token', 'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        return $plainToken;
    }
}
