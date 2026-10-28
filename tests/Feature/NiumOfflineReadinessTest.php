<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Models\IntegrationProvider;
use App\Models\KycDocument;
use App\Models\NiumComplianceEvent;
use App\Models\NiumRfiCase;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Nium\NiumBeneficiaryPreflightValidator;
use App\Services\Nium\NiumBeneficiaryRequirementsResult;
use App\Services\Nium\NiumBeneficiaryRequirementsService;
use App\Services\Nium\NiumHkPaymentIdOneShotRunner;
use App\Services\Nium\NiumHkSandboxReadinessService;
use App\Services\Nium\NiumPaymentIdService;
use App\Services\Nium\NiumPayoutReadinessService;
use App\Services\Nium\NiumRfiWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class NiumOfflineReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_sandbox_review_and_incomplete_overall_kyc_are_hold(): void
    {
        [$provider, $user, $account] = $this->account(7, false);
        $metadata = ['nium_submit_kyc_attempts' => [
            'ref_'.substr(hash('sha256', 'c620e0e9-ab0a-43bd-aa10-44f573db723a'), 0, 16) => ['state' => 'provider_accepted_200_sandbox_review'],
            'ref_'.substr(hash('sha256', '7609d9d1-9d37-4e08-9197-602d792f7a2e'), 0, 16) => ['state' => 'rejected'],
        ]];
        $account->update(['metadata' => $metadata]);
        $report = app(NiumHkSandboxReadinessService::class)->report($account->fresh());
        $this->assertSame('HOLD', $report['applicant_kyc']['status']);
        $this->assertSame('HOLD', $report['stakeholder_kyc']['status']);
        $this->assertSame('HOLD', $report['customer_kyc']['status']);
        $this->assertSame('NOT_STARTED', $report['van']['status']);
    }

    public function test_van_blocks_awaiting_kyc_unverified_ids_compliance_and_unresolved_rfi(): void
    {
        [$provider, $user, $account] = $this->account(7, false);
        foreach ([
            fn () => $account->forceFill(['provider_sub_status' => 'awaiting_kyc']),
            fn () => $account->forceFill(['provider_sub_status' => null, 'customer_id_verified_at' => null]),
            fn () => $account->forceFill(['customer_id_verified_at' => now(), 'compliance_status' => null]),
        ] as $mutation) {
            $mutation()->save();
            try { app(NiumHkPaymentIdOneShotRunner::class)->preflight($account->fresh(), true); $this->fail('Expected VAN HOLD.'); }
            catch (RuntimeException $exception) { $this->assertStringContainsString('VAN HOLD', $exception->getMessage()); }
        }
        $account->update(['compliance_status' => 'completed']);
        $user->update(['kyc_status' => 'verified']);
        $account->update(['status' => 'active', 'provider_status' => 'clear', 'provider_sub_status' => null]);
        NiumRfiCase::query()->create($this->rfiAttributes($provider, $account, 'unresolved'));
        $this->expectExceptionMessage('RFI remains outstanding');
        app(NiumHkPaymentIdOneShotRunner::class)->preflight($account->fresh(), true);
    }

    public function test_van_holds_ambiguous_history_and_protects_account_four(): void
    {
        [$provider, $user, $account] = $this->account(7, true);
        $this->vanConfig();
        ApiRequestLog::query()->create(['provider_id' => $provider->id, 'user_id' => $user->id, 'request_method' => 'POST',
            'request_url' => 'https://safe.invalid/paymentId', 'endpoint_path' => '/paymentId', 'request_headers' => [], 'request_body' => [], 'request_started_at' => now()]);
        $this->expectExceptionMessage('historical Assign Payment ID evidence is ambiguous');
        app(NiumHkPaymentIdOneShotRunner::class)->preflight($account, true);
    }

    public function test_account_four_is_always_protected(): void
    {
        [$provider, $user, $account] = $this->account(4, true);
        $this->expectExceptionMessage('Account 4 is protected');
        app(NiumHkPaymentIdOneShotRunner::class)->preflight($account, true);
    }

    public function test_automatic_allocation_and_missing_human_approval_always_hold_without_http(): void
    {
        [$provider, $user, $account] = $this->account(7, true);
        Http::fake();
        config()->set('services.nium.hk_van_allocation_mode', 'automatic');
        try { app(NiumHkPaymentIdOneShotRunner::class)->preflight($account, true); $this->fail(); }
        catch (RuntimeException $e) { $this->assertStringContainsString('never permits', $e->getMessage()); }
        $this->vanConfig();
        try { app(NiumHkPaymentIdOneShotRunner::class)->preflight($account, false); $this->fail(); }
        catch (RuntimeException $e) { $this->assertStringContainsString('human approval', $e->getMessage()); }
        Http::assertNothingSent();
    }

    public function test_assign_payment_id_uses_canonical_operation(): void
    {
        // This assignment now requires a committed target before HTTP. Leave the
        // unrelated readiness tests' RefreshDatabase transaction untouched.
        $originalConnection = DB::getDefaultConnection();
        config(['database.connections.nium_readiness_assignment_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('nium_readiness_assignment_test');
        try {
            $this->artisan('migrate', ['--database' => 'nium_readiness_assignment_test', '--force' => true])->assertSuccessful();
            [$provider, $user, $account] = $this->account(null, true);
            Http::fake(['*' => Http::response(['uniquePaymentId' => 'VA-CANONICAL', 'currencyCode' => 'HKD'])]);
            app(NiumPaymentIdService::class)->assign($account, 'HKD', 'COLLECTION_ACCOUNT', 'LOCAL', 'Confirmed Bank', 'offline-readiness-assignment');
            $this->assertDatabaseHas('api_request_logs', ['user_id' => $user->id, 'operation' => 'assign_payment_id']);
        } finally {
            DB::purge('nium_readiness_assignment_test');
            DB::setDefaultConnection($originalConnection);
        }
    }

    public function test_rfi_list_redacts_answers_and_raw_file_ids_and_detail_requires_admin(): void
    {
        [$provider, $user, $account] = $this->account();
        $case = NiumRfiCase::query()->create([...$this->rfiAttributes($provider, $account, 'pii-list'),
            'response_draft' => [['questionId' => 'passport', 'answer' => 'P123 accountNumber address identificationNumber']],
            'supporting_file_ids' => [['provider_file_id' => '11111111-1111-4111-8111-111111111111']]]);
        $this->getJson("/api/admin/nium-rfi-cases/{$case->id}")->assertUnauthorized();
        $admin = User::factory()->create(); $admin->roles()->create(['role_code' => 'admin']);
        $json = $this->withToken($this->issueTokenFor($admin))->getJson('/api/admin/nium-rfi-cases')->assertOk()->getContent();
        foreach (['P123', 'accountNumber', 'address', 'identificationNumber', '11111111-1111-4111-8111-111111111111'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_rfi_file_provenance_rejects_arbitrary_cross_account_synthetic_and_superseded(): void
    {
        [$provider, $reviewer, $account] = $this->account();
        [$otherProvider, $otherUser, $otherAccount] = $this->account();
        $case = NiumRfiCase::query()->create($this->rfiAttributes($provider, $account, 'files'));
        $service = app(NiumRfiWorkflowService::class);
        foreach ([
            '99999999-9999-4999-8999-999999999999',
            $this->document($otherUser, 'approved', true, false)->metadata['nium_file_id'],
            $this->document($reviewer, 'approved', true, true)->metadata['nium_file_id'],
            $this->document($reviewer, 'superseded', true, false)->metadata['nium_file_id'],
        ] as $fileId) {
            try { $service->saveFactualDraft($case, [['questionId' => 'q', 'answer' => 'human fact']], [$fileId], $reviewer->id); $this->fail('Expected file rejection.'); }
            catch (RuntimeException $exception) { $this->assertStringContainsString('not approved factual AVAILABLE', $exception->getMessage()); }
        }
    }

    public function test_customer_rfi_draft_reuses_owned_document_across_cases_and_rejects_cross_tenant_access(): void
    {
        [$provider, $user, $account] = $this->account();
        [, $otherUser] = $this->account();
        $cases = collect(['personal-sow', 'personal-sof'])->map(fn (string $reference) => NiumRfiCase::query()->create([
            ...$this->rfiAttributes($provider, $account, $reference),
            'origin' => 'authoritative_fetch', 'submission_state' => 'sent_to_user',
            'evidence' => ['rfiHashId' => $reference, 'templateId' => "template-{$reference}",
                'template' => ['name' => 'otherDocument', 'documentType' => 'document', 'type' => 'document', 'rfiType' => 'corporate',
                    'requiredFields' => [['fieldLabel' => $reference, 'fieldValue' => 'document', 'type' => 'document']]],
                'remarks' => 'Provide the requested evidence.', 'rfiStatus' => 'RFI_REQUESTED'],
        ]));
        $document = $user->kycProfile->documents()->create([
            'type' => 'corporate_rfi_supporting_document', 'status' => 'pending_admin_review',
            'file_url' => '/api/kyc-documents/test', 'storage_disk' => 'kyc_private', 'file_path' => 'kyc/test/bank.pdf',
            'original_name' => 'bank-statement.pdf', 'mime_type' => 'application/pdf', 'file_size' => 1024,
            'file_hash' => hash('sha256', 'bank-statement'), 'metadata' => ['factual' => true, 'synthetic' => false],
        ]);
        $workflow = app(NiumRfiWorkflowService::class);

        foreach ($cases as $case) {
            $workflow->saveUserDraft($case, $user->id, [$document->id], 'Shared bank statement.');
            $workflow->submitByUser($case->fresh(), $user->id);
        }

        $this->assertDatabaseCount('nium_rfi_case_documents', 2);
        $this->assertSame(['user_submitted', 'user_submitted'], $cases->map(fn (NiumRfiCase $case) => $case->fresh()->submission_state)->all());
        $this->expectExceptionMessage('not available for a customer draft');
        $workflow->saveUserDraft($cases->first()->fresh(), $otherUser->id, [$document->id], 'Not mine.');
    }

    public function test_admin_user_admin_rfi_workflow_enforces_authorization_notifications_reuse_and_contract_gate(): void
    {
        Queue::fake();
        Storage::fake('kyc_private');
        [$provider, $customer, $account] = $this->account();
        [, $otherCustomer] = $this->account(provider: $provider);
        $admin = User::factory()->create();
        $admin->roles()->create(['role_code' => 'admin']);
        $adminToken = $this->issueTokenFor($admin);
        $superAdmin = User::factory()->create();
        $superAdmin->roles()->create(['role_code' => 'super_admin']);
        $superAdminToken = $this->issueTokenFor($superAdmin);
        $customerToken = $this->issueTokenFor($customer);
        $otherToken = $this->issueTokenFor($otherCustomer);
        $cases = collect(['personal-sow', 'personal-sof'])->map(fn (string $identity) => NiumRfiCase::query()->create([
            ...$this->rfiAttributes($provider, $account, $identity),
            'origin' => 'authoritative_fetch', 'submission_state' => 'new',
            'evidence' => $this->authoritativeRfi($identity, 'RFI_REQUESTED'),
        ]));

        foreach ($cases as $case) {
            $this->withToken($adminToken)->postJson("/api/admin/nium-rfi-cases/{$case->id}/send-to-user")->assertOk()
                ->assertJsonPath('submission_state', 'sent_to_user');
        }
        $this->assertSame(2, \App\Models\KycNotificationEvent::query()->where('event_type', 'rfi')->count());

        $upload = $this->withToken($customerToken)->post("/api/user/users/{$customer->id}/nium-rfi-documents", [
            'file' => UploadedFile::fake()->createWithContent(
                'bank-statement.pdf',
                "%PDF-1.4\nFactual Corporate RFI bank statement fixture\n%%EOF",
            ),
        ])->assertCreated();
        $documentId = (int) $upload->json('document.id');

        $this->withToken($otherToken)->putJson("/api/user/users/{$customer->id}/nium-rfi-cases/{$cases->first()->id}/draft", [
            'document_ids' => [$documentId], 'explanation' => 'Cross tenant attempt.',
        ])->assertForbidden();

        foreach ($cases as $case) {
            $this->withToken($customerToken)->putJson("/api/user/users/{$customer->id}/nium-rfi-cases/{$case->id}/draft", [
                'document_ids' => [$documentId], 'explanation' => 'The same factual bank statement supports both requests.',
            ])->assertOk()->assertJsonPath('submission_state', 'user_draft');
            $this->withToken($customerToken)->postJson("/api/user/users/{$customer->id}/nium-rfi-cases/{$case->id}/submit")
                ->assertOk()->assertJsonPath('submission_state', 'user_submitted');
        }
        $this->assertDatabaseCount('nium_rfi_case_documents', 2);

        $first = $cases->first();
        $this->withToken($adminToken)->postJson("/api/admin/nium-rfi-cases/{$first->id}/request-revision", [
            'reason' => 'Please provide the complete statement period.',
        ])->assertOk()->assertJsonPath('submission_state', 'revision_requested');
        $this->withToken($customerToken)->putJson("/api/user/users/{$customer->id}/nium-rfi-cases/{$first->id}/draft", [
            'document_ids' => [$documentId], 'explanation' => 'Complete statement period confirmed.',
        ])->assertOk();
        $this->withToken($customerToken)->postJson("/api/user/users/{$customer->id}/nium-rfi-cases/{$first->id}/submit")->assertOk();

        Http::fake();
        foreach ($cases as $case) {
            $this->withToken($adminToken)->postJson("/api/admin/nium-rfi-cases/{$case->id}/approve-user-submission")
                ->assertOk()->assertJsonPath('submission_state', 'admin_approved')
                ->assertJsonPath('contract_gate', 'provider_contract_unconfirmed');

            $this->withToken($adminToken)->postJson("/api/admin/nium-rfi-cases/{$case->id}/submit")
                ->assertForbidden();
            $this->withToken($superAdminToken)->postJson("/api/admin/nium-rfi-cases/{$case->id}/submit")
                ->assertUnprocessable()
                ->assertJsonValidationErrors([
                    'case_id', 'confirmation', 'provider_reference_fingerprint', 'template_fingerprint',
                ]);
            $this->assertSame('admin_approved', $case->fresh()->submission_state);
            $this->assertNull($case->fresh()->claimed_at);

            // Enable only the fake test path so the unconfirmed contract gate is the next guard evaluated.
            config()->set('services.nium.customer_rfi_authoritative_submission_enabled', true);
            $this->withToken($superAdminToken)->postJson("/api/admin/nium-rfi-cases/{$case->id}/submit", [
                'case_id' => $case->id,
                'confirmation' => 'SUBMIT '.$case->id,
                'provider_reference_fingerprint' => $case->provider_reference_fingerprint,
                'template_fingerprint' => hash('sha256', (string) data_get($case->evidence, 'templateId')),
            ])
                ->assertUnprocessable()
                ->assertJsonPath('message', 'NIUM_RFI_PROVIDER_CONTRACT_GATE: this environment, endpoint, version, template, and required-field shape are not verified for live submission.');
            $this->assertSame('admin_approved', $case->fresh()->submission_state);
            $this->assertNull($case->fresh()->claimed_at);
        }
        Http::assertNothingSent();
    }

    public function test_owned_factual_available_file_and_human_answer_provenance_are_accepted(): void
    {
        [$provider, $reviewer, $account] = $this->account();
        $case = NiumRfiCase::query()->create($this->rfiAttributes($provider, $account, 'accepted-file'));
        $fileId = $this->document($reviewer, 'approved', true, false)->metadata['nium_file_id'];
        $draft = app(NiumRfiWorkflowService::class)->saveFactualDraft($case, [['questionId' => 'q', 'answer' => 'reviewer supplied']], [$fileId], $reviewer->id);
        $this->assertSame('human_supplied', data_get($draft->response_draft, '0.provenance.source'));
        $this->assertSame($reviewer->id, data_get($draft->response_draft, '0.provenance.reviewer_id'));
    }

    public function test_admin_rfi_detail_exposes_only_bounded_submission_evidence(): void
    {
        [$provider, $customer, $account] = $this->account();
        $admin = User::factory()->create();
        $admin->roles()->create(['role_code' => 'admin']);
        $adminToken = $this->issueTokenFor($admin);
        $customerToken = $this->issueTokenFor($customer);
        $case = NiumRfiCase::query()->create([
            ...$this->rfiAttributes($provider, $account, 'safe-admin-history'),
            'origin' => 'authoritative_fetch',
            'submission_state' => 'responded',
            'status' => 'responded_under_review',
            'evidence' => $this->authoritativeRfi('safe-admin-history', 'RFI_RESPONDED'),
            'provider_response_evidence' => [
                'http_status' => 200,
                'outcome' => 'RECONCILED',
                'manual_reconciliation_required' => false,
                'x_request_id' => 'secret-request-id',
                'rfi_hash_id' => 'secret-rfi-hash',
                'raw_request_body' => ['document' => base64_encode('secret-document')],
                'private_file_path' => 'private://secret/document.pdf',
            ],
        ]);

        $this->withToken($customerToken)->getJson("/api/admin/nium-rfi-cases/{$case->id}")->assertForbidden();
        $response = $this->withToken($adminToken)->getJson("/api/admin/nium-rfi-cases/{$case->id}")
            ->assertOk()
            ->assertJsonPath('submission_http_status', 200)
            ->assertJsonPath('submission_outcome', 'RECONCILED')
            ->assertJsonPath('reconciliation_required', false)
            ->assertJsonMissingPath('provider_response_evidence');
        $this->assertStringNotContainsString('secret-request-id', $response->getContent());
        $this->assertStringNotContainsString('secret-rfi-hash', $response->getContent());
        $this->assertStringNotContainsString(base64_encode('secret-document'), $response->getContent());
        $this->assertStringNotContainsString('private://secret/document.pdf', $response->getContent());

        foreach ([400, 422] as $httpStatus) {
            $case->update(['provider_response_evidence' => [
                'http_status' => $httpStatus,
                'outcome' => 'REJECTED',
                'manual_reconciliation_required' => false,
            ]]);
            $this->withToken($adminToken)->getJson("/api/admin/nium-rfi-cases/{$case->id}")
                ->assertOk()
                ->assertJsonPath('submission_http_status', $httpStatus)
                ->assertJsonPath('submission_outcome', 'REJECTED')
                ->assertJsonPath('reconciliation_required', false);
        }

        foreach ([
            null,
            ['http_status' => '200', 'outcome' => 'reconciled', 'manual_reconciliation_required' => 0],
            ['http_status' => 99, 'outcome' => 'SUCCESS', 'manual_reconciliation_required' => 'false'],
            ['http_status' => 600, 'outcome' => ['RECONCILED'], 'manual_reconciliation_required' => []],
        ] as $invalidEvidence) {
            $case->update(['provider_response_evidence' => $invalidEvidence]);
            $this->withToken($adminToken)->getJson("/api/admin/nium-rfi-cases/{$case->id}")
                ->assertOk()
                ->assertJsonPath('submission_http_status', null)
                ->assertJsonPath('submission_outcome', null)
                ->assertJsonPath('reconciliation_required', null);
        }
    }

    public function test_same_rfi_reference_does_not_collide_across_accounts(): void
    {
        [$provider, $userA, $accountA] = $this->account();
        [$sameProvider, $userB, $accountB] = $this->account(provider: $provider);
        $service = app(NiumRfiWorkflowService::class);
        $payload = ['eventId' => 'same-reference', 'subStatus' => 'RFI_REQUESTED'];
        $this->assertNotSame($service->ingestCustomerEvidence($provider, $accountA, $payload)->id,
            $service->ingestCustomerEvidence($provider, $accountB, $payload)->id);
    }

    public function test_authoritative_reconciliation_resolves_clear_and_keeps_rfi_outstanding(): void
    {
        [$provider, $user, $account] = $this->account();
        $service = app(NiumRfiWorkflowService::class);
        $case = $service->ingestCustomerEvidence($provider, $account, ['eventId' => 'reconcile', 'subStatus' => 'RFI_REQUESTED']);
        $account->update(['rfi_status' => null, 'provider_status' => 'clear', 'provider_sub_status' => null]); $service->reconcileCustomerEvidence($account->fresh());
        $this->assertSame('resolved_authoritative_clear', $case->fresh()->status);
        $case->refresh()->update(['status' => 'provisional']); $account->update(['rfi_status' => 'requested', 'provider_sub_status' => 'rfi_requested']);
        $service->reconcileCustomerEvidence($account->fresh(), [$this->authoritativeRfi('reconcile-provider-id', 'RFI_REQUESTED')]);
        $this->assertSame('historical_webhook_evidence', $case->fresh()->status);
        $this->assertDatabaseHas('nium_rfi_cases', [
            'user_provider_account_id' => $account->id,
            'origin' => 'authoritative_fetch',
            'provider_reference_fingerprint' => hash('sha256', 'reconcile-provider-id'),
            'status' => 'requested',
        ]);
    }

    public function test_responded_rfi_under_review_is_non_actionable_but_not_clear(): void
    {
        [$provider, $user, $account] = $this->account();
        $service = app(NiumRfiWorkflowService::class);
        $case = $service->ingestCustomerEvidence($provider, $account, [
            'eventId' => 'responded-review',
            'subStatus' => 'RFI_REQUESTED',
        ]);
        $case->update(['status' => 'requested']);
        $account->update([
            'provider_status' => 'pending',
            'provider_sub_status' => 'under_review',
            'rfi_status' => 'requested',
        ]);

        $service->reconcileCustomerEvidence($account->fresh(), [
            $this->authoritativeRfi('c4b61b85-d1c0-4461-83d2-e204de675ed4', 'RFI_RESPONDED'),
        ]);

        $case->refresh();
        $this->assertSame('responded', $account->fresh()->rfi_status);
        $this->assertSame('historical_webhook_evidence', $case->status);
        $this->assertSame('not_claimed', $case->submission_state);
        $this->assertNull($case->approved_at);
        $this->assertNull($case->claimed_at);
        $authoritative = NiumRfiCase::query()->where('origin', 'authoritative_fetch')->sole();
        $this->assertSame('responded_under_review', $authoritative->status);
        $this->assertSame('RFI_RESPONDED', data_get($authoritative->provider_response_evidence, 'rfi_status'));
    }

    public function test_under_review_without_responded_evidence_does_not_clear_or_deactivate_rfi(): void
    {
        [$provider, $user, $account] = $this->account();
        $service = app(NiumRfiWorkflowService::class);
        $case = $service->ingestCustomerEvidence($provider, $account, [
            'eventId' => 'unproven-review',
            'subStatus' => 'RFI_REQUESTED',
        ]);
        $case->update(['status' => 'requested']);
        $account->update([
            'provider_status' => 'pending',
            'provider_sub_status' => 'under_review',
            'rfi_status' => 'requested',
        ]);

        try {
            $service->reconcileCustomerEvidence($account->fresh());
            $this->fail('Expected missing authoritative Corporate RFI evidence to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Corporate RFI evidence is required', $exception->getMessage());
        }

        $this->assertSame('requested', $account->fresh()->rfi_status);
        $this->assertSame('requested', $case->fresh()->status);
    }

    public function test_responded_rfi_resolves_only_on_authoritative_clear_and_later_rfi_is_actionable(): void
    {
        [$provider, $user, $account] = $this->account();
        $service = app(NiumRfiWorkflowService::class);
        $responded = $service->ingestCustomerEvidence($provider, $account, [
            'eventId' => 'first-rfi',
            'subStatus' => 'RFI_REQUESTED',
        ]);
        $responded->update(['status' => 'requested']);
        $account->update(['provider_status' => 'pending', 'provider_sub_status' => 'under_review']);
        $service->reconcileCustomerEvidence($account->fresh(), [$this->authoritativeRfi('first-rfi-provider-id', 'RFI_RESPONDED')]);

        $account->update(['provider_status' => 'clear', 'provider_sub_status' => null]);
        $service->reconcileCustomerEvidence($account->fresh());

        $this->assertSame('cleared', $account->fresh()->rfi_status);
        $this->assertSame('historical_webhook_evidence', $responded->fresh()->status);

        $newCase = $service->ingestCustomerEvidence($provider, $account->fresh(), [
            'eventId' => 'later-rfi',
            'subStatus' => 'RFI_REQUESTED',
        ]);
        $account->update(['provider_status' => 'pending', 'provider_sub_status' => 'rfi_requested']);
        $service->reconcileCustomerEvidence($account->fresh(), [$this->authoritativeRfi('later-rfi-provider-id', 'RFI_REQUESTED')]);

        $this->assertSame('requested', $account->fresh()->rfi_status);
        $this->assertSame('historical_webhook_evidence', $newCase->fresh()->status);
        $this->assertSame('historical_webhook_evidence', $responded->fresh()->status);
        $this->assertDatabaseHas('nium_rfi_cases', [
            'origin' => 'authoritative_fetch',
            'provider_reference_fingerprint' => hash('sha256', 'later-rfi-provider-id'),
            'status' => 'requested',
        ]);
    }

    public function test_endpoint_only_does_not_open_rfi_contract_gate(): void
    {
        [$provider, $reviewer, $account] = $this->account();
        $case = NiumRfiCase::query()->create([...$this->rfiAttributes($provider, $account, 'gate'), 'submission_state' => 'approved',
            'approved_at' => now(), 'approved_by' => $reviewer->id, 'response_draft' => [['questionId' => 'q', 'answer' => 'x', 'provenance' => ['source' => 'human_supplied', 'reviewer_id' => $reviewer->id]]]]);
        config()->set('services.nium.customer_rfi_response_endpoint', '/configured-only');
        $this->expectExceptionMessage('NIUM_RFI_PROVIDER_CONTRACT_GATE');
        app(NiumRfiWorkflowService::class)->claimForProviderSubmission($case);
    }

    public function test_beneficiary_schema_provenance_dimensions_staleness_and_trusted_acceptance(): void
    {
        [$provider, $user] = $this->account(); $corridor = $this->corridor();
        $fabricated = new NiumBeneficiaryRequirementsResult($this->dimensions(), ['IFSC'], [], 'fake', now()->toISOString(), 'v1', 'bad');
        $payload = [...$this->dimensions(), 'routingCodeType1' => 'IFSC', 'beneficiaryAccountNumber' => '123'];
        try { app(NiumBeneficiaryPreflightValidator::class)->validate($payload, $corridor, $fabricated); $this->fail(); }
        catch (RuntimeException $e) { $this->assertStringContainsString('provenance', $e->getMessage()); }
        $stale = NiumBeneficiaryRequirementsResult::trusted($this->dimensions(), ['IFSC'], [], now()->subDays(2)->toISOString(), 'v1');
        try { app(NiumBeneficiaryPreflightValidator::class)->validate($payload, $corridor, $stale); $this->fail(); }
        catch (RuntimeException $e) { $this->assertStringContainsString('stale', $e->getMessage()); }
        $trusted = NiumBeneficiaryRequirementsResult::trusted($this->dimensions(), ['IFSC'], [['name' => 'beneficiaryAccountNumber', 'required' => true]], now()->toISOString(), 'v1');
        $this->assertTrue(app(NiumBeneficiaryPreflightValidator::class)->validate($payload, $corridor, $trusted)['valid']);
        $this->expectExceptionMessage('dimensions do not match');
        app(NiumBeneficiaryPreflightValidator::class)->validate($payload, [...$corridor, 'destinationCurrency' => 'USD'], $trusted);
    }

    public function test_service_produced_beneficiary_requirements_are_trusted(): void
    {
        [$provider, $user] = $this->account(complete: true);
        Http::fake(['*' => Http::response(['fields' => [['fieldName' => 'beneficiaryAccountNumber', 'required' => true]]])]);
        $schema = app(NiumBeneficiaryRequirementsService::class)->requirements($user, $this->corridor());
        $payload = [...$this->dimensions(), 'routingCodeType1' => 'IFSC', 'beneficiaryAccountNumber' => '123'];
        $this->assertTrue(app(NiumBeneficiaryPreflightValidator::class)->validate($payload, $this->corridor(), $schema)['valid']);
    }

    public function test_caller_compliance_true_cannot_bypass_persisted_unresolved_rfi(): void
    {
        [$provider, $user] = $this->account();
        $transaction = Transaction::query()->create(['user_id' => $user->id, 'provider_id' => $provider->id, 'external_transaction_id' => 'tx-1',
            'currency' => 'HKD', 'amount' => 1, 'status' => 'pending', 'compliance_status' => 'CLEAR', 'compliance_review_required' => false]);
        NiumComplianceEvent::query()->create(['provider_id' => $provider->id, 'user_id' => $user->id, 'transaction_id' => $transaction->id,
            'event_id' => 'tx-rfi', 'requires_action' => true, 'review_status' => 'pending', 'payload' => []]);
        $facts = array_fill_keys(['selected_corridor','requirements_schema','beneficiary_validated','beneficiary_created','transfer_validated','transfer_submitted','transaction_terminal'], true);
        $facts['transaction_compliance_clear'] = true;
        $this->assertSame('HOLD', app(NiumPayoutReadinessService::class)->report($facts, $transaction)['transaction_compliance']['status']);
        $this->assertSame('HOLD', app(NiumPayoutReadinessService::class)->report([])['supported_corridor']['status']);
    }

    private function account(?int $id = null, bool $complete = false, ?IntegrationProvider $provider = null): array
    {
        config()->set('services.nium.base_url', 'https://gateway.sandbox.nium.test'); config()->set('services.nium.client_id', 'client-test');
        config()->set('services.nium.auth', ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'test-key']);
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key'); config()->set('services.nium.webhook.static_header_value', 'test-partner-key');
        $provider ??= IntegrationProvider::query()->firstOrCreate(['code' => 'nium'], ['name' => 'Nium', 'status' => 'active']);
        $user = User::factory()->create(['kyc_status' => $complete ? 'verified' : 'pending']);
        $user->kycProfile()->create(['status' => 'approved', 'applicant_type' => 'business', 'legal_name' => 'Factual Test Profile',
            'address_line1' => 'Internal factual address', 'city' => 'Hong Kong', 'country_code' => 'HK']);
        $account = $user->providerAccounts()->create(['provider_id' => $provider->id, 'external_customer_id' => 'customer-'.$user->id,
            'external_account_id' => 'wallet-'.$user->id, 'status' => $complete ? 'active' : 'pending', 'provider_status' => $complete ? 'clear' : 'pending',
            'compliance_status' => $complete ? 'completed' : null, 'customer_id_verified_at' => now(), 'wallet_id_verified_at' => now(), 'provider_ids_verified_at' => now()]);
        if ($id !== null) { $account->forceFill(['id' => $id])->save(); $account = $account->fresh(); }
        return [$provider, $user, $account];
    }

    private function document(User $user, string $status, bool $factual, bool $synthetic): KycDocument
    {
        return $user->kycProfile->documents()->create(['type' => 'passport', 'status' => $status, 'file_url' => 'private://fact',
            'metadata' => ['nium_file_id' => sprintf('10000000-0000-4000-8000-%012d', KycDocument::query()->count() + 1),
                'nium_file_state' => 'AVAILABLE', 'factual' => $factual, 'synthetic' => $synthetic]]);
    }

    private function rfiAttributes(IntegrationProvider $provider, $account, string $reference): array
    { return ['provider_id' => $provider->id, 'user_provider_account_id' => $account->id, 'scope' => 'customer',
        'provider_reference_fingerprint' => hash('sha256', $reference), 'status' => 'requested', 'evidence' => []]; }
    private function authoritativeRfi(string $rfiHashId, string $status): array
    { return ['rfiHashId' => $rfiHashId, 'templateId' => "template-{$rfiHashId}",
        'template' => ['name' => 'otherDocument', 'documentType' => 'document', 'type' => 'document', 'rfiType' => 'corporate',
            'requiredFields' => [['fieldLabel' => 'Source of Funds', 'fieldValue' => 'document', 'type' => 'document']]],
        'remarks' => 'Provide the requested supporting document.', 'status' => $status]; }
    private function vanConfig(): void { config()->set('services.nium.hk_van_allocation_mode', 'request_based'); config()->set('services.nium.hk_payment_id_currency', 'HKD'); config()->set('services.nium.hk_payment_id_bank_name', 'Confirmed Bank'); config()->set('services.nium.hk_payment_id_account_category', 'COLLECTION_ACCOUNT'); }
    private function corridor(): array { return [...$this->dimensions(), 'routingCodeType' => ['IFSC']]; }
    private function dimensions(): array { return ['destinationCountry' => 'IN', 'destinationCurrency' => 'INR', 'payoutMethod' => 'LOCAL', 'beneficiaryAccountType' => 'INDIVIDUAL', 'customerType' => 'INDIVIDUAL']; }
    private function issueTokenFor(User $user): string
    {
        $plain = Str::random(80);
        ApiToken::query()->create(['user_id' => $user->id, 'name' => 'test', 'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addDay()]);
        return $plain;
    }
}
