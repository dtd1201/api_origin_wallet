<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\UserKycSubmissionController;
use App\Jobs\Nium\ContinueNiumCustomerOnboardingJob;
use App\Models\AuditLog;
use App\Models\IntegrationProvider;
use App\Models\KycProfile;
use App\Models\KycProviderSubmission;
use App\Models\User;
use App\Services\Aml\AmlScreeningService;
use App\Services\Compliance\ComplianceEvidenceService;
use App\Services\Integrations\Contracts\OnboardingProvider;
use App\Services\Integrations\DataObjects\ProviderOnboardingResult;
use App\Services\Integrations\ProviderOnboardingEligibilityException;
use App\Services\Integrations\ProviderOnboardingManager;
use App\Services\Integrations\ProviderOnboardingReadinessService;
use App\Services\Integrations\ProviderRegistry;
use App\Services\Nium\NiumCustomerOnboardingService;
use App\Services\Nium\NiumKycDataValidator;
use App\Services\Nium\NiumProviderRequestException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fixtures\FakeAmlScreeningProvider;
use Tests\TestCase;

class NiumComplianceBridgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_kyc_approval_dispatches_automatic_nium_continuation_for_processing_documents(): void
    {
        Queue::fake();
        $provider = $this->provider();
        $admin = User::factory()->create();
        $user = User::factory()->create(['status' => 'pending', 'kyc_status' => 'pending']);
        $profile = $this->profile($user, 'submitted');
        $profile->update([
            'date_of_birth' => '1990-01-01',
            'nationality_country_code' => 'TH',
            'postal_code' => '10110',
        ]);
        $this->approvedDocument($profile, 'submitted')->update([
            'issuing_country_code' => 'TH',
        ]);
        $this->clearAml($profile);
        $account = $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'status' => 'pending',
            'provider_status' => 'pending',
        ]);
        $onboardingService = Mockery::mock(NiumCustomerOnboardingService::class);
        $onboardingService->shouldReceive('beginOnboarding')->once()->andReturn(new ProviderOnboardingResult(
            providerAccount: $account,
            status: 'pending',
            nextAction: 'wait_for_document_processing',
            message: 'Nium documents are processing.',
            metadata: ['pending_document_count' => 1],
        ));
        $request = Request::create('/api/admin/users/'.$user->id.'/kyc-profile/approve', 'POST');
        $request->setUserResolver(fn () => $admin);

        $response = app(UserKycSubmissionController::class)->approve(
            $request,
            $user,
            new AmlScreeningService(new FakeAmlScreeningProvider),
            app(ComplianceEvidenceService::class),
            app(ProviderOnboardingReadinessService::class),
            $onboardingService,
            app(NiumKycDataValidator::class),
            app(\App\Services\Nium\NiumKycDataValidator::class),
        );

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame(
            'KYC profile approved. Nium onboarding workflow started and will continue automatically.',
            $response->getData(true)['message'],
        );
        Queue::assertPushed(
            ContinueNiumCustomerOnboardingJob::class,
            fn (ContinueNiumCustomerOnboardingJob $job): bool => $job->userId === $user->id
                && $job->providerId === $provider->id,
        );
    }

    public function test_kyc_approval_prepares_and_submits_nium_tracking(): void
    {
        $provider = $this->provider();
        $admin = User::factory()->create();
        $user = User::factory()->create(['status' => 'pending', 'kyc_status' => 'pending']);
        $profile = $this->profile($user, 'submitted');
        $this->approvedDocument($profile, 'submitted');
        $this->clearAml($profile);
        $account = $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'status' => 'pending',
            'provider_status' => 'pending',
            'external_customer_id' => 'synthetic-customer-id',
        ]);
        $onboardingService = Mockery::mock(NiumCustomerOnboardingService::class);
        $onboardingService->shouldReceive('beginOnboarding')
            ->once()
            ->withArgs(fn (IntegrationProvider $candidate, User $candidateUser): bool => $candidate->is($provider) && $candidateUser->is($user))
            ->andReturn(new ProviderOnboardingResult(
                providerAccount: $account,
                status: 'pending',
                nextAction: 'wait_for_provider_review',
                message: 'Nium customer onboarding is in progress.',
            ));
        $request = Request::create('/api/admin/users/'.$user->id.'/kyc-profile/approve', 'POST');
        $request->setUserResolver(fn () => $admin);

        app(UserKycSubmissionController::class)->approve(
            $request,
            $user,
            new AmlScreeningService(new FakeAmlScreeningProvider),
            app(ComplianceEvidenceService::class),
            app(ProviderOnboardingReadinessService::class),
            $onboardingService,
            app(\App\Services\Nium\NiumKycDataValidator::class),
        );

        $submission = KycProviderSubmission::query()->sole();
        $this->assertSame($provider->id, $submission->provider_id);
        $this->assertSame($profile->id, $submission->kyc_profile_id);
        $this->assertSame('submitted', $submission->status);
        $this->assertNull($submission->approved_at);
        $this->assertNotNull($submission->submitted_at);
        $this->assertSame($admin->id, $submission->reviewed_by_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'kyc_provider_submission.prepared_from_kyc',
            'entity_id' => (string) $submission->id,
        ]);
    }

    public function test_superseded_aml_does_not_block_direct_nium_onboarding(): void
    {
        $provider = $this->provider();
        $admin = User::factory()->create();
        $user = User::factory()->create(['status' => 'pending', 'kyc_status' => 'pending']);
        $profile = $this->profile($user, 'submitted');
        $this->approvedDocument($profile, 'submitted');
        $this->clearAml($profile);
        $profile->amlScreenings()->create([
            'user_id' => $user->id,
            'subject_type' => 'kyc_profile',
            'subject_id' => $profile->id,
            'subject_name' => 'Historical Pending Customer',
            'subject_role' => 'individual',
            'screening_provider' => 'historical',
            'provider' => 'historical',
            'status' => 'pending',
            'compliance_decision' => 'pending_review',
            'risk_level' => 'unknown',
            'superseded_at' => now(),
        ]);
        $account = $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'status' => 'pending',
            'provider_status' => 'pending',
            'external_customer_id' => 'synthetic-customer-id',
        ]);
        $onboardingService = Mockery::mock(NiumCustomerOnboardingService::class);
        $onboardingService->shouldReceive('beginOnboarding')->once()->andReturn(new ProviderOnboardingResult(
            providerAccount: $account,
            status: 'pending',
            nextAction: 'wait_for_provider_review',
            message: 'Nium customer onboarding is in progress.',
        ));
        $request = Request::create('/api/admin/users/'.$user->id.'/kyc-profile/approve', 'POST');
        $request->setUserResolver(fn () => $admin);

        $response = app(UserKycSubmissionController::class)->approve(
            $request,
            $user,
            new AmlScreeningService(new FakeAmlScreeningProvider),
            app(ComplianceEvidenceService::class),
            app(ProviderOnboardingReadinessService::class),
            $onboardingService,
            app(\App\Services\Nium\NiumKycDataValidator::class),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('verified', $profile->fresh()->status);
        $this->assertDatabaseHas('kyc_provider_submissions', [
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'status' => 'submitted',
        ]);
    }

    public function test_kyc_approval_rejects_blocking_compliance_state(): void
    {
        $provider = $this->provider();
        $admin = User::factory()->create();
        $user = User::factory()->create(['status' => 'pending', 'kyc_status' => 'pending']);
        $profile = $this->profile($user, 'submitted');
        $this->approvedDocument($profile, 'submitted');
        $this->clearAml($profile);
        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'status' => 'blocked',
            'provider_status' => 'blocked',
            'compliance_status' => 'failed',
        ]);
        $request = Request::create('/api/admin/users/'.$user->id.'/kyc-profile/approve', 'POST');
        $request->setUserResolver(fn () => $admin);
        $onboardingService = Mockery::mock(NiumCustomerOnboardingService::class);
        $onboardingService->shouldNotReceive('syncUser');

        try {
            app(UserKycSubmissionController::class)->approve(
                $request,
                $user,
                new AmlScreeningService(new FakeAmlScreeningProvider),
                app(ComplianceEvidenceService::class),
                app(ProviderOnboardingReadinessService::class),
                $onboardingService,
                app(\App\Services\Nium\NiumKycDataValidator::class),
            );
            $this->fail('Expected KYC approval to be blocked.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertSame('submitted', $profile->fresh()->status);
        $this->assertDatabaseMissing('kyc_provider_submissions', ['user_id' => $user->id]);
    }

    public function test_nium_failure_keeps_kyc_verified_and_marks_submission_retryable(): void
    {
        config()->set('services.nium.auth.header_value', 'sandbox-secret-api-key');
        Log::spy();
        $provider = $this->provider();
        $admin = User::factory()->create();
        $user = User::factory()->create(['status' => 'pending', 'kyc_status' => 'pending']);
        $profile = $this->profile($user, 'submitted');
        $this->approvedDocument($profile, 'submitted');
        $this->clearAml($profile);
        $onboardingService = Mockery::mock(NiumCustomerOnboardingService::class);
        $onboardingService->shouldReceive('beginOnboarding')
            ->once()
            ->andThrow(new NiumProviderRequestException(
                publicMessage: 'Nium customer onboarding failed.',
                providerCode: 'invalid_customer',
                providerField: 'documents.0.fileIds',
                providerPath: 'documents.0.fileIds',
                httpStatus: 422,
                responseBody: [
                    'errorCode' => 'invalid_customer',
                    'message' => 'Document verification failed.',
                    'authorization' => 'Bearer sandbox-secret-api-key',
                    'apiKey' => 'sandbox-secret-api-key',
                ],
            ));
        $request = Request::create('/api/admin/users/'.$user->id.'/kyc-profile/approve', 'POST');
        $request->setUserResolver(fn () => $admin);

        $response = app(UserKycSubmissionController::class)->approve(
            $request,
            $user,
            new AmlScreeningService(new FakeAmlScreeningProvider),
            app(ComplianceEvidenceService::class),
            app(ProviderOnboardingReadinessService::class),
            $onboardingService,
            app(\App\Services\Nium\NiumKycDataValidator::class),
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('nium_onboarding_failed', $response->getData(true)['code']);
        $this->assertSame('verified', $profile->fresh()->status);
        $this->assertSame('verified', $user->fresh()->kyc_status);
        $this->assertDatabaseHas('kyc_provider_submissions', [
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'status' => 'failed',
            'failure_reason' => 'nium_onboarding_failed',
        ]);
        $submission = KycProviderSubmission::query()->where('user_id', $user->id)->sole();

        Log::shouldHaveReceived('error')
            ->once()
            ->with('Direct Nium onboarding failed after KYC approval.', Mockery::on(
                function (array $context) use ($user, $submission): bool {
                    $this->assertSame(NiumProviderRequestException::class, $context['exception_class']);
                    $this->assertSame('Nium customer onboarding failed.', $context['exception_message']);
                    $this->assertSame(422, $context['http_status']);
                    $this->assertSame($user->id, $context['user_id']);
                    $this->assertSame($submission->id, $context['kyc_submission_id']);
                    $this->assertSame('invalid_customer', $context['provider_code']);
                    $this->assertSame('documents.0.fileIds', $context['provider_field']);
                    $this->assertSame('documents.0.fileIds', $context['provider_path']);
                    $this->assertArrayNotHasKey('response_body', $context);
                    $this->assertStringNotContainsString('Document verification failed.', json_encode($context));
                    $this->assertStringNotContainsString('sandbox-secret-api-key', json_encode($context));

                    return true;
                },
            ));
    }

    public function test_aml_rerun_invalidates_existing_nium_submission_tracking(): void
    {
        [$provider, $user, $profile] = $this->approvedCustomer();
        $submission = $this->pendingSubmission($provider, $user, $profile);

        app(AmlScreeningService::class)->prepareProfile($profile);

        $submission->refresh();
        $this->assertSame('failed', $submission->status);
        $this->assertNull($submission->approved_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'kyc_provider_submission.invalidated',
            'entity_id' => (string) $submission->id,
        ]);
    }

    public function test_aml_not_clear_blocks_before_the_nium_provider_is_resolved(): void
    {
        [$provider, $user, $profile] = $this->approvedCustomer(withAml: false);
        $profile->amlScreenings()->create([
            'user_id' => $user->id,
            'subject_type' => 'kyc_profile',
            'subject_id' => $profile->id,
            'subject_name' => 'Manual Review Customer',
            'subject_role' => 'individual',
            'screening_provider' => 'authoritative',
            'provider' => 'authoritative',
            'status' => 'manual_review',
            'screening_result' => 'match',
            'compliance_decision' => 'pending_review',
            'risk_level' => 'high',
        ]);
        $this->pendingSubmission($provider, $user, $profile);

        $this->assertBlockedWithoutOutbound($provider, $user, 'aml_not_clear', 'aml');
    }

    public function test_missing_documents_block_before_the_nium_provider_is_resolved(): void
    {
        [$provider, $user, $profile] = $this->approvedCustomer(withDocument: false);
        $this->pendingSubmission($provider, $user, $profile);

        $this->assertBlockedWithoutOutbound($provider, $user, 'documents_not_approved', 'kyc_documents');
    }

    public function test_blocking_compliance_state_blocks_before_the_nium_provider_is_resolved(): void
    {
        [$provider, $user, $profile] = $this->approvedCustomer();
        $this->pendingSubmission($provider, $user, $profile);
        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'status' => 'blocked',
            'provider_status' => 'blocked',
            'compliance_status' => 'failed',
        ]);

        $this->assertBlockedWithoutOutbound($provider, $user, 'compliance_state_blocking', 'provider_compliance');
    }

    public function test_missing_automatic_kyc_submission_blocks_before_the_nium_provider_is_resolved(): void
    {
        [$provider, $user] = $this->approvedCustomer();

        $this->assertBlockedWithoutOutbound($provider, $user, 'nium_submission_not_ready', 'internal_kyc_bridge');
    }

    public function test_prepared_nium_submission_can_reach_the_provider(): void
    {
        [$provider, $user, $profile] = $this->approvedCustomer();
        $submission = $this->pendingSubmission($provider, $user, $profile);
        $account = $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'status' => 'pending',
            'provider_status' => 'pending',
        ]);
        $onboardingProvider = Mockery::mock(OnboardingProvider::class);
        $onboardingProvider->shouldReceive('syncUser')->once()->with($provider, $user)->andReturn($account);
        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolveOnboardingProvider')->once()->with($provider)->andReturn($onboardingProvider);
        $manager = new ProviderOnboardingManager($registry, app(ProviderOnboardingReadinessService::class));

        $this->assertSame($account->id, $manager->syncUser($provider, $user)->id);
        $this->assertSame('submitted', $submission->fresh()->status);
    }

    private function assertBlockedWithoutOutbound(
        IntegrationProvider $provider,
        User $user,
        string $reasonCode,
        string $failedGate,
    ): void {
        Http::fake();
        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldNotReceive('resolveOnboardingProvider');
        $manager = new ProviderOnboardingManager($registry, app(ProviderOnboardingReadinessService::class));

        try {
            $manager->syncUser($provider, $user);
            $this->fail('Expected onboarding to be blocked.');
        } catch (ProviderOnboardingEligibilityException $exception) {
            $this->assertSame($reasonCode, $exception->reasonCode);
            $this->assertSame($failedGate, $exception->failedGate);
        }

        $audit = AuditLog::query()->where('action', 'provider_onboarding.blocked')->latest('id')->firstOrFail();
        $this->assertSame($reasonCode, $audit->new_data['reason_code']);
        $this->assertSame($failedGate, $audit->new_data['failed_gate']);
        $this->assertSame($user->id, $audit->new_data['customer_id']);
        $this->assertArrayHasKey('provider_submission_id', $audit->new_data);
        Http::assertNothingSent();
    }

    private function approvedCustomer(bool $withDocument = true, bool $withAml = true): array
    {
        $provider = $this->provider();
        $user = User::factory()->create(['status' => 'active', 'kyc_status' => 'verified']);
        $profile = $this->profile($user);

        if ($withDocument) {
            $this->approvedDocument($profile);
        }

        if ($withAml) {
            $this->clearAml($profile);
        }

        return [$provider, $user, $profile];
    }

    private function provider(): IntegrationProvider
    {
        return IntegrationProvider::query()->firstOrCreate(
            ['code' => 'nium'],
            ['name' => 'Nium', 'status' => 'active'],
        );
    }

    private function profile(User $user, string $status = 'verified'): KycProfile
    {
        return $user->kycProfile()->create([
            'status' => $status,
            'applicant_type' => 'individual',
            'legal_name' => 'Compliance Bridge Customer',
            'address_line1' => '1 Compliance Road',
            'city' => 'Bangkok',
            'country_code' => 'TH',
            'reviewed_by_user_id' => $status === 'verified' ? $user->id : null,
            'reviewed_at' => $status === 'verified' ? now() : null,
        ]);
    }

    private function approvedDocument(KycProfile $profile, string $status = 'approved')
    {
        return $profile->documents()->create([
            'type' => 'passport',
            'status' => $status,
            'file_url' => 'private://compliance-document',
            'file_path' => 'private/compliance-document.pdf',
            'document_number' => 'SENSITIVE-DOCUMENT-NUMBER',
            'file_size' => 100,
        ]);
    }

    private function clearAml(KycProfile $profile)
    {
        return $profile->amlScreenings()->create([
            'user_id' => $profile->user_id,
            'subject_type' => 'kyc_profile',
            'subject_id' => $profile->id,
            'subject_name' => 'Sensitive Customer Name',
            'subject_role' => 'individual',
            'screening_provider' => 'authoritative',
            'provider' => 'authoritative',
            'status' => 'completed',
            'screening_result' => 'clear',
            'compliance_decision' => 'clear',
            'risk_level' => 'low',
            'completed_at' => now(),
            'raw_data' => ['provider_payload' => 'sensitive'],
        ]);
    }

    private function pendingSubmission(
        IntegrationProvider $provider,
        User $user,
        KycProfile $profile,
    ): KycProviderSubmission {
        return KycProviderSubmission::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'kyc_profile_id' => $profile->id,
            'status' => 'pending',
            'reviewed_by_user_id' => $user->id,
            'reviewed_at' => now(),
            'approved_at' => null,
        ]);
    }
}
