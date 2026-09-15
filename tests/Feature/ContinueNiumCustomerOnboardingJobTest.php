<?php

namespace Tests\Feature;

use App\Jobs\Nium\ContinueNiumCustomerOnboardingJob;
use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Compliance\ComplianceEvidenceService;
use App\Services\Nium\NiumCustomerDocumentPreparationService;
use App\Services\Nium\NiumCustomerOnboardingService;
use App\Services\Nium\NiumProviderRequestException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class ContinueNiumCustomerOnboardingJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_processing_nium_file_releases_job_without_creating_customer(): void
    {
        [$provider, $user] = $this->providerAccount();
        $documentPreparation = Mockery::mock(NiumCustomerDocumentPreparationService::class);
        $documentPreparation->shouldReceive('prepare')
            ->once()
            ->withArgs(fn (User $candidate): bool => $candidate->is($user))
            ->andReturn(['ready' => false, 'pending_document_count' => 1]);
        $onboarding = Mockery::mock(NiumCustomerOnboardingService::class);
        $onboarding->shouldNotReceive('syncUser');
        $job = (new ContinueNiumCustomerOnboardingJob($user->id, $provider->id))
            ->withFakeQueueInteractions();

        $job->handle($documentPreparation, $onboarding, app(ComplianceEvidenceService::class));

        $job->assertReleased(30);
        $this->assertNull($user->providerAccounts()->sole()->external_customer_id);
        $log = ApiRequestLog::query()
            ->where('operation', 'nium_customer_onboarding_continuation')
            ->sole();
        $this->assertSame('documents_processing', $log->request_body['state']);
        $this->assertSame(1, $log->request_body['pending_document_count']);
    }

    public function test_available_nium_files_continue_customer_creation(): void
    {
        [$provider, $user, $account] = $this->providerAccount();
        $documentPreparation = Mockery::mock(NiumCustomerDocumentPreparationService::class);
        $documentPreparation->shouldReceive('prepare')
            ->once()
            ->andReturn(['ready' => true, 'pending_document_count' => 0]);
        $onboarding = Mockery::mock(NiumCustomerOnboardingService::class);
        $onboarding->shouldReceive('syncUser')
            ->once()
            ->withArgs(fn (IntegrationProvider $candidateProvider, User $candidateUser): bool => $candidateProvider->is($provider)
                && $candidateUser->is($user))
            ->andReturnUsing(function () use ($account) {
                $account->update(['external_customer_id' => 'customer-created-by-nium']);

                return $account->fresh();
            });
        $job = (new ContinueNiumCustomerOnboardingJob($user->id, $provider->id))
            ->withFakeQueueInteractions();

        $job->handle($documentPreparation, $onboarding, app(ComplianceEvidenceService::class));

        $job->assertNotReleased();
        $this->assertSame('customer-created-by-nium', $account->fresh()->external_customer_id);
        $log = ApiRequestLog::query()
            ->where('operation', 'nium_customer_onboarding_continuation')
            ->sole();
        $this->assertSame('customer_created', $log->request_body['state']);
        $this->assertSame(0, $log->request_body['pending_document_count']);
    }

    public function test_deterministic_nium_4xx_rejections_do_not_retry(): void
    {
        foreach ([400, 422] as $status) {
            [$provider, $user] = $this->providerAccount();

            $documentPreparation = Mockery::mock(NiumCustomerDocumentPreparationService::class);
            $documentPreparation->shouldReceive('prepare')
                ->once()
                ->andReturn(['ready' => true, 'pending_document_count' => 0]);

            $exception = new NiumProviderRequestException(
                'Nium rejected the customer payload.',
                'invalid_input',
                null,
                null,
                $status,
                [],
            );

            $onboarding = Mockery::mock(NiumCustomerOnboardingService::class);
            $onboarding->shouldReceive('syncUser')
                ->once()
                ->andThrow($exception);

            $job = (new ContinueNiumCustomerOnboardingJob($user->id, $provider->id))
                ->withFakeQueueInteractions();

            $job->handle($documentPreparation, $onboarding, app(ComplianceEvidenceService::class));

            $job->assertNotReleased();

            $log = ApiRequestLog::query()
                ->where('user_id', $user->id)
                ->where('operation', 'nium_customer_onboarding_continuation')
                ->sole();

            $this->assertSame('provider_rejected', $log->request_body['state']);
            $this->assertSame('provider_rejected', $log->transport_outcome);
        }
    }

    public function test_retryable_nium_failures_are_rethrown(): void
    {
        foreach ([429, 500] as $status) {
            [$provider, $user] = $this->providerAccount();

            $documentPreparation = Mockery::mock(NiumCustomerDocumentPreparationService::class);
            $documentPreparation->shouldReceive('prepare')
                ->once()
                ->andReturn(['ready' => true, 'pending_document_count' => 0]);

            $exception = new NiumProviderRequestException(
                'Nium request should be retried.',
                null,
                null,
                null,
                $status,
                [],
            );

            $onboarding = Mockery::mock(NiumCustomerOnboardingService::class);
            $onboarding->shouldReceive('syncUser')
                ->once()
                ->andThrow($exception);

            $job = (new ContinueNiumCustomerOnboardingJob($user->id, $provider->id))
                ->withFakeQueueInteractions();

            $thrown = null;

            try {
                $job->handle($documentPreparation, $onboarding, app(ComplianceEvidenceService::class));
            } catch (NiumProviderRequestException $candidate) {
                $thrown = $candidate;
            }

            $this->assertSame($exception, $thrown);

            $log = ApiRequestLog::query()
                ->where('user_id', $user->id)
                ->where('operation', 'nium_customer_onboarding_continuation')
                ->sole();

            $this->assertSame('continuation_failed', $log->request_body['state']);
            $this->assertSame('continuation_failed', $log->transport_outcome);
        }
    }

    public function test_logging_failure_does_not_fail_successful_customer_creation(): void
    {
        [$provider, $user, $account] = $this->providerAccount();

        $documentPreparation = Mockery::mock(NiumCustomerDocumentPreparationService::class);
        $documentPreparation->shouldReceive('prepare')
            ->once()
            ->andReturn(['ready' => true, 'pending_document_count' => 0]);

        $onboarding = Mockery::mock(NiumCustomerOnboardingService::class);
        $onboarding->shouldReceive('syncUser')
            ->once()
            ->andReturnUsing(function () use ($account) {
                $account->update(['external_customer_id' => 'customer-created-despite-log-failure']);

                return $account->fresh();
            });

        $event = 'eloquent.creating: '.ApiRequestLog::class;

        Event::listen($event, static function (): void {
            throw new \RuntimeException('Forced ApiRequestLog persistence failure.');
        });

        try {
            $job = (new ContinueNiumCustomerOnboardingJob($user->id, $provider->id))
                ->withFakeQueueInteractions();

            $job->handle($documentPreparation, $onboarding, app(ComplianceEvidenceService::class));
        } finally {
            Event::forget($event);
        }

        $this->assertSame(
            'customer-created-despite-log-failure',
            $account->fresh()->external_customer_id,
        );
        $this->assertSame(0, ApiRequestLog::query()->count());
    }

    private function providerAccount(): array
    {
        $provider = IntegrationProvider::query()->firstOrCreate(
            ['code' => 'nium'],
            [
                'name' => 'Nium',
                'status' => 'active',
            ],
        );
        $user = User::factory()->create(['status' => 'active', 'kyc_status' => 'verified']);
        $account = $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'status' => 'pending',
            'provider_status' => 'pending',
        ]);

        return [$provider, $user, $account];
    }
}
