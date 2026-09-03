<?php

namespace Tests\Feature;

use App\Models\AmlScreening;
use App\Models\ApiToken;
use App\Models\IntegrationProvider;
use App\Models\KycProfile;
use App\Models\Role;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminRbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_user_cannot_access_admin_apis(): void
    {
        $user = User::factory()->create();

        $this->withToken($this->issueTokenFor($user))
            ->getJson('/api/admin/users')
            ->assertForbidden();
    }

    public function test_admin_without_permission_is_rejected_and_admin_with_permission_is_accepted(): void
    {
        $support = $this->createAdminWithRole('support_agent');
        $auditor = $this->createAdminWithRole('auditor');

        $this->withToken($this->issueTokenFor($support))
            ->getJson('/api/admin/audit-logs')
            ->assertForbidden();

        $this->withToken($this->issueTokenFor($auditor))
            ->getJson('/api/admin/audit-logs')
            ->assertOk();
    }

    public function test_compliance_officer_can_approve_kyc_but_cannot_approve_transfer(): void
    {
        $officer = $this->createAdminWithRole('compliance_officer');
        $customer = User::factory()->create(['status' => 'pending', 'kyc_status' => 'submitted']);
        $profile = KycProfile::query()->create([
            'user_id' => $customer->id,
            'status' => 'submitted',
            'applicant_type' => 'individual',
            'legal_name' => 'Customer One',
            'address_line1' => '1 Test Street',
            'city' => 'Bangkok',
            'country_code' => 'TH',
        ]);
        AmlScreening::query()->create([
            'user_id' => $customer->id,
            'kyc_profile_id' => $profile->id,
            'subject_type' => 'kyc_profile',
            'subject_id' => $profile->id,
            'subject_name' => 'Customer One',
            'screening_provider' => 'fake-authoritative',
            'provider' => 'fake-authoritative',
            'status' => 'completed',
            'compliance_decision' => 'clear',
            'risk_level' => 'low',
        ]);
        $transfer = $this->createTransfer($customer);
        $token = $this->issueTokenFor($officer);

        $this->withToken($token)
            ->postJson("/api/admin/users/{$customer->id}/kyc-profile/approve")
            ->assertOk()
            ->assertJsonPath('kyc_profile.status', 'verified');

        $this->withToken($token)
            ->postJson("/api/admin/transfers/{$transfer->id}/approve")
            ->assertForbidden();
    }

    public function test_compliance_officer_can_access_aml_screening_records(): void
    {
        $officer = $this->createAdminWithRole('compliance_officer');

        $this->withToken($this->issueTokenFor($officer))
            ->getJson('/api/admin/aml-screenings')
            ->assertOk();
    }

    public function test_finance_operator_can_approve_transfer_but_cannot_modify_kyc(): void
    {
        $operator = $this->createAdminWithRole('finance_operator');
        $customer = User::factory()->create();
        $profile = KycProfile::query()->create([
            'user_id' => $customer->id,
            'status' => 'submitted',
            'applicant_type' => 'individual',
            'legal_name' => 'Customer Two',
            'address_line1' => '2 Test Street',
            'city' => 'Bangkok',
            'country_code' => 'TH',
        ]);
        $transfer = $this->createTransfer($customer);
        $token = $this->issueTokenFor($operator);

        $this->withToken($token)
            ->postJson("/api/admin/transfers/{$transfer->id}/approve")
            ->assertOk()
            ->assertJsonPath('transfer.status', 'approved');

        $this->withToken($token)
            ->postJson("/api/admin/users/{$customer->id}/kyc-profile/reject", ['rejection_reason' => 'Not permitted'])
            ->assertForbidden();

        $this->assertDatabaseHas('kyc_profiles', ['id' => $profile->id, 'status' => 'submitted']);
    }

    public function test_auditor_is_read_only(): void
    {
        $auditor = $this->createAdminWithRole('auditor');
        User::factory()->create();
        $token = $this->issueTokenFor($auditor);

        $this->withToken($token)->getJson('/api/admin/users')->assertOk();
        $this->withToken($token)->postJson('/api/admin/users', [
            'email' => 'blocked@example.com',
            'password' => 'secret123',
        ])->assertForbidden();
    }

    public function test_legacy_admin_role_keeps_super_admin_compatibility(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->create(['role_code' => 'admin']);

        $this->withToken($this->issueTokenFor($admin))
            ->getJson('/api/admin/audit-logs')
            ->assertOk();
    }

    private function createAdminWithRole(string $code): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('code', $code)->firstOrFail();
        $user->roles()->create(['role_id' => $role->id, 'role_code' => $role->code]);

        return $user;
    }

    private function createTransfer(User $customer): Transfer
    {
        $provider = IntegrationProvider::query()->firstOrCreate(
            ['code' => 'TEST_PROVIDER'],
            ['name' => 'Test Provider', 'status' => 'active'],
        );

        return Transfer::query()->create([
            'transfer_no' => 'TRF-'.Str::upper(Str::random(12)),
            'user_id' => $customer->id,
            'provider_id' => $provider->id,
            'transfer_type' => 'bank',
            'source_currency' => 'USD',
            'target_currency' => 'USD',
            'source_amount' => 100,
            'fee_amount' => 0,
            'status' => 'approval_required',
        ]);
    }

    private function issueTokenFor(User $user): string
    {
        $plainToken = Str::random(80);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'test-token',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        return $plainToken;
    }
}
