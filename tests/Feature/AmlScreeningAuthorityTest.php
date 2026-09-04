<?php

namespace Tests\Feature;

use App\Contracts\Aml\AmlScreeningProvider;
use App\Models\AmlScreening;
use App\Models\KycProfile;
use App\Models\User;
use App\Services\Aml\AmlScreeningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Fixtures\FakeAmlScreeningProvider;
use Tests\TestCase;

class AmlScreeningAuthorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_screening_creation_persists_authoritative_execution_fields(): void
    {
        $service = $this->service(new FakeAmlScreeningProvider);
        $profile = $this->profile();

        $screening = $service->prepareProfile($profile)->firstOrFail();

        $this->assertSame('fake-authoritative', $screening->provider);
        $this->assertSame('pending', $screening->status);
        $this->assertSame('pending_review', $screening->compliance_decision);
        $this->assertNull($screening->screening_reference);
    }

    public function test_provider_success_persists_clear_result_separately_from_execution_status(): void
    {
        $service = $this->service(new FakeAmlScreeningProvider);
        $screening = $service->prepareProfile($this->profile())->firstOrFail();

        $result = $service->runScreening($screening);

        $this->assertSame('completed', $result->status);
        $this->assertSame('clear', $result->screening_result);
        $this->assertSame('clear', $result->compliance_decision);
        $this->assertSame('low', $result->risk_level);
        $this->assertNotNull($result->screening_reference);
        $this->assertNotNull($result->screened_at);
        $this->assertNotNull($result->completed_at);
        $this->assertSame(0, $result->result_summary['match_count']);
    }

    public function test_provider_failure_is_recorded_and_does_not_clear_customer(): void
    {
        $service = $this->service(new FakeAmlScreeningProvider(fails: true));
        $screening = $service->prepareProfile($this->profile())->firstOrFail();

        $result = $service->runScreening($screening);

        $this->assertSame('failed', $result->status);
        $this->assertSame('pending_review', $result->compliance_decision);
        $this->assertSame(['error' => 'provider_failure'], $result->result_summary);
    }

    public function test_provider_match_requires_manual_review_before_a_decision(): void
    {
        $service = $this->service(new FakeAmlScreeningProvider('match'));
        $screening = $service->prepareProfile($this->profile())->firstOrFail();

        $result = $service->runScreening($screening);

        $this->assertSame('manual_review', $result->status);
        $this->assertSame('match', $result->screening_result);
        $this->assertSame('pending_review', $result->compliance_decision);
        $this->assertSame(1, $result->matches->count());
    }

    public function test_compliance_reviewer_can_clear_a_provider_match(): void
    {
        $service = $this->service(new FakeAmlScreeningProvider('match'));
        $screening = $service->runScreening($service->prepareProfile($this->profile())->firstOrFail());
        $reviewer = User::factory()->create();

        $result = $service->manualClear($screening, $reviewer, 'False positive.');

        $this->assertSame('completed', $result->status);
        $this->assertSame('clear', $result->compliance_decision);
        $this->assertSame($reviewer->id, $result->reviewed_by_user_id);
    }

    public function test_internal_metadata_cannot_create_an_aml_match(): void
    {
        $profile = $this->profile();
        $profile->update(['metadata' => ['aml' => ['matches' => [['score' => 100]]]]]);
        $service = $this->service(new FakeAmlScreeningProvider);

        $result = $service->runScreening($service->prepareProfile($profile->fresh())->firstOrFail());

        $this->assertSame('clear', $result->compliance_decision);
        $this->assertCount(0, $result->matches);
    }

    public function test_staging_unconfigured_provider_failure_returns_bypass_applied(): void
    {
        $this->app->detectEnvironment(fn (): string => 'staging');
        $profile = $this->profile();
        $screening = $this->failedScreening($profile, 'unconfigured');
        $beforeUpdatedAt = $screening->updated_at;

        $this->assertTrue($this->service(new FakeAmlScreeningProvider)->assertProfileClear($profile));
        $screening->refresh();
        $this->assertSame('unconfigured', $screening->screening_provider);
        $this->assertSame('unconfigured', $screening->provider);
        $this->assertSame('failed', $screening->status);
        $this->assertSame('pending_review', $screening->compliance_decision);
        $this->assertSame(['error' => 'provider_failure'], $screening->result_summary);
        $this->assertTrue($beforeUpdatedAt->equalTo($screening->updated_at));
    }

    public function test_production_unconfigured_provider_failure_still_throws(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $profile = $this->profile();
        $this->failedScreening($profile, 'unconfigured');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('All AML screenings must be clear or manually cleared before KYC/KYB approval.');

        $this->service(new FakeAmlScreeningProvider)->assertProfileClear($profile);
    }

    public function test_staging_real_provider_failure_still_throws(): void
    {
        $this->app->detectEnvironment(fn (): string => 'staging');
        $profile = $this->profile();
        $this->failedScreening($profile, 'authoritative');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('All AML screenings must be clear or manually cleared before KYC/KYB approval.');

        $this->service(new FakeAmlScreeningProvider)->assertProfileClear($profile);
    }

    private function service(AmlScreeningProvider $provider): AmlScreeningService
    {
        return new AmlScreeningService($provider);
    }

    private function profile(): KycProfile
    {
        $user = User::factory()->create();

        return KycProfile::query()->create([
            'user_id' => $user->id,
            'status' => 'submitted',
            'applicant_type' => 'individual',
            'legal_name' => 'Screened Customer',
            'address_line1' => '1 Compliance Road',
            'city' => 'Bangkok',
            'country_code' => 'TH',
        ]);
    }

    private function failedScreening(KycProfile $profile, string $provider): AmlScreening
    {
        return $profile->amlScreenings()->create([
            'user_id' => $profile->user_id,
            'subject_type' => 'kyc_profile',
            'subject_id' => $profile->id,
            'subject_name' => $profile->legal_name,
            'subject_role' => $profile->applicant_type,
            'screening_provider' => $provider,
            'provider' => $provider,
            'status' => 'failed',
            'compliance_decision' => 'pending_review',
            'result_summary' => ['error' => 'provider_failure'],
        ]);
    }
}
