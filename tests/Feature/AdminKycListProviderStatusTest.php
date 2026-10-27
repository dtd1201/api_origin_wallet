<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\IntegrationProvider;
use App\Models\KycProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminKycListProviderStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    #[DataProvider('niumListCases')]
    public function test_list_projects_nium_status_by_code_without_changing_stored_statuses(
        string $endpoint,
        string $applicantType,
        string $providerCode,
    ): void {
        $otherProvider = $this->provider(7, 'other');
        $nium = $this->provider(10, $providerCode);
        $user = User::factory()->create(['kyc_status' => 'verified']);
        $profile = $this->profile($user, ['applicant_type' => $applicantType]);
        $otherAccount = $user->providerAccounts()->create([
            'provider_id' => $otherProvider->id,
            'status' => 'pending',
            'provider_status' => 'pending',
            'provider_sub_status' => 'under_review',
            'rfi_status' => 'requested',
            'odd_status' => 'pending',
        ]);
        $niumAccount = $user->providerAccounts()->create([
            'provider_id' => $nium->id,
            'status' => 'active',
            'provider_status' => 'clear',
        ]);
        $document = $profile->documents()->create([
            'type' => 'passport', 'status' => 'verified', 'file_url' => 'https://example.test/passport.pdf',
        ]);
        $requirement = $profile->requirements()->create([
            'key' => 'passport', 'label' => 'Passport', 'category' => 'identity', 'requirement_type' => 'document',
        ]);
        $profileBefore = $profile->fresh()->getAttributes();
        $niumBefore = $niumAccount->fresh()->getAttributes();
        $otherBefore = $otherAccount->fresh()->getAttributes();

        $this->withToken($this->adminToken())->getJson($endpoint)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('per_page', 15)
            ->assertJsonPath('data.0.id', $profile->id)
            ->assertJsonPath('data.0.status', 'verified')
            ->assertJsonPath('data.0.provider_status', 'clear')
            ->assertJsonPath('data.0.provider_sub_status', null)
            ->assertJsonPath('data.0.rfi_status', null)
            ->assertJsonPath('data.0.odd_status', null)
            ->assertJsonCount(1, 'data.0.user.provider_accounts')
            ->assertJsonPath('data.0.user.provider_accounts.0.id', $niumAccount->id)
            ->assertJsonCount(1, 'data.0.documents')
            ->assertJsonPath('data.0.documents.0.id', $document->id)
            ->assertJsonCount(1, 'data.0.requirements')
            ->assertJsonPath('data.0.requirements.0.id', $requirement->id);

        $this->assertSame($profileBefore, $profile->fresh()->getAttributes());
        $this->assertSame($niumBefore, $niumAccount->fresh()->getAttributes());
        $this->assertSame($otherBefore, $otherAccount->fresh()->getAttributes());
        $this->assertSame('verified', $user->fresh()->kyc_status);
        Http::assertNothingSent();
    }

    public static function niumListCases(): array
    {
        return [
            'KYB profiles lowercase' => ['/api/admin/kyc-profiles', 'business', 'nium'],
            'KYB submissions mixed case' => ['/api/admin/kyc-submissions', 'business', 'NiUm'],
            'KYC profiles uppercase' => ['/api/admin/kyc-profiles', 'individual', 'NIUM'],
            'KYC submissions lowercase' => ['/api/admin/kyc-submissions', 'individual', 'nium'],
        ];
    }

    public function test_list_preserves_all_provider_status_fields(): void
    {
        $nium = $this->provider(10, 'nium');
        $user = User::factory()->create();
        $this->profile($user, ['status' => 'under_review']);
        $user->providerAccounts()->create([
            'provider_id' => $nium->id,
            'status' => 'pending',
            'provider_status' => 'pending',
            'provider_sub_status' => 'under_review',
            'rfi_status' => 'requested',
            'odd_status' => 'pending',
        ]);

        $this->withToken($this->adminToken())->getJson('/api/admin/kyc-profiles')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'under_review')
            ->assertJsonPath('data.0.provider_status', 'pending')
            ->assertJsonPath('data.0.provider_sub_status', 'under_review')
            ->assertJsonPath('data.0.rfi_status', 'requested')
            ->assertJsonPath('data.0.odd_status', 'pending');
    }

    public function test_users_without_a_nium_account_have_null_provider_status_fields(): void
    {
        $otherProvider = $this->provider(7, 'other');
        $this->provider(10, 'nium');
        $userWithOtherAccount = User::factory()->create();
        $this->profile($userWithOtherAccount);
        $userWithOtherAccount->providerAccounts()->create([
            'provider_id' => $otherProvider->id,
            'provider_status' => 'pending',
            'provider_sub_status' => 'under_review',
            'rfi_status' => 'requested',
            'odd_status' => 'pending',
        ]);
        $this->profile(User::factory()->create());

        $response = $this->withToken($this->adminToken())->getJson('/api/admin/kyc-profiles')
            ->assertOk()->assertJsonPath('total', 2)->assertJsonCount(2, 'data');

        foreach ([0, 1] as $index) {
            $response->assertJsonPath("data.{$index}.provider_status", null)
                ->assertJsonPath("data.{$index}.provider_sub_status", null)
                ->assertJsonPath("data.{$index}.rfi_status", null)
                ->assertJsonPath("data.{$index}.odd_status", null)
                ->assertJsonCount(0, "data.{$index}.user.provider_accounts");
        }
    }

    public function test_status_filter_pagination_order_and_admin_exclusion_are_preserved(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->create(['role_code' => 'admin']);
        $this->profile($admin);
        $this->profile(User::factory()->create(), ['status' => 'submitted']);
        $oldest = $this->profile(User::factory()->create(), ['submitted_at' => now()->subDays(2)]);

        foreach (range(1, 15) as $index) {
            $this->profile(User::factory()->create(), ['submitted_at' => now()->subMinutes($index)]);
        }

        $token = $this->issueTokenFor($admin);
        $this->withToken($token)->getJson('/api/admin/kyc-profiles?status=verified')
            ->assertOk()->assertJsonPath('total', 16)->assertJsonPath('per_page', 15)
            ->assertJsonPath('current_page', 1)->assertJsonCount(15, 'data');
        $this->withToken($token)->getJson('/api/admin/kyc-profiles?status=verified&page=2')
            ->assertOk()->assertJsonPath('total', 16)->assertJsonPath('current_page', 2)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $oldest->id);
        $this->withToken($token)->getJson('/api/admin/kyc-profiles?status=invalid')
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    #[DataProvider('listEndpoints')]
    public function test_list_requires_authentication_admin_access_and_kyc_view_permission(string $endpoint): void
    {
        $this->getJson($endpoint)->assertUnauthorized();
        $this->withToken('invalid-token')->getJson($endpoint)->assertUnauthorized();
        $this->withToken($this->issueTokenFor(User::factory()->create()))
            ->getJson($endpoint)->assertForbidden();

        $operator = User::factory()->create();
        $operator->roles()->create(['role_code' => 'finance_operator']);
        $this->assertTrue($operator->isAdmin());
        $this->assertFalse($operator->hasPermission('kyc.view'));
        $this->withToken($this->issueTokenFor($operator))->getJson($endpoint)->assertForbidden();
    }

    public static function listEndpoints(): array
    {
        return [
            'profiles' => ['/api/admin/kyc-profiles'],
            'submissions' => ['/api/admin/kyc-submissions'],
        ];
    }

    private function provider(int $id, string $code): IntegrationProvider
    {
        $provider = new IntegrationProvider(['code' => $code, 'name' => $code, 'status' => 'active']);
        $provider->id = $id;
        $provider->save();

        return $provider;
    }

    private function profile(User $user, array $attributes = []): KycProfile
    {
        return $user->kycProfile()->create(array_merge([
            'status' => 'verified',
            'applicant_type' => 'business',
            'legal_name' => 'GREENTECH',
            'address_line1' => '1 Test Street',
            'city' => 'Hong Kong',
            'country_code' => 'HK',
            'submitted_at' => now(),
        ], $attributes));
    }

    private function adminToken(): string
    {
        $admin = User::factory()->create();
        $admin->roles()->create(['role_code' => 'admin']);

        return $this->issueTokenFor($admin);
    }

    private function issueTokenFor(User $user): string
    {
        $plain = Str::random(80);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'admin-kyc-list-test',
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDay(),
        ]);

        return $plain;
    }
}
