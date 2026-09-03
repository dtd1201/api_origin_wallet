<?php

namespace Tests\Feature;

use App\Contracts\Aml\AmlScreeningProvider;
use App\Models\KycProfile;
use App\Models\User;
use App\Services\Aml\AmlScreeningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
