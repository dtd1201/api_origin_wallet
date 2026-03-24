<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\IntegrationProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_user_listing_excludes_admin_accounts(): void
    {
        $admin = $this->createAdminUser();
        $regularUser = User::factory()->create();
        $hiddenAdmin = User::factory()->create();
        $hiddenAdmin->roles()->create([
            'role_code' => 'admin',
        ]);

        $response = $this->withToken($this->issueTokenFor($admin))
            ->getJson('/api/admin/users');

        $response
            ->assertOk()
            ->assertJsonMissing(['id' => $hiddenAdmin->id])
            ->assertJsonFragment(['id' => $regularUser->id]);
    }

    public function test_admin_cannot_view_update_or_delete_admin_accounts_via_user_crud(): void
    {
        $admin = $this->createAdminUser();
        $targetAdmin = User::factory()->create();
        $targetAdmin->roles()->create([
            'role_code' => 'super_admin',
        ]);

        $token = $this->issueTokenFor($admin);

        $this->withToken($token)
            ->getJson("/api/admin/users/{$targetAdmin->id}")
            ->assertNotFound();

        $this->withToken($token)
            ->putJson("/api/admin/users/{$targetAdmin->id}", [
                'full_name' => 'Blocked',
            ])
            ->assertNotFound();

        $this->withToken($token)
            ->deleteJson("/api/admin/users/{$targetAdmin->id}")
            ->assertNotFound();
    }

    public function test_admin_can_create_regular_user_with_password_field(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->withToken($this->issueTokenFor($admin))
            ->postJson('/api/admin/users', [
                'email' => 'member@example.com',
                'full_name' => 'Member User',
                'password' => 'secret123',
                'status' => 'active',
                'kyc_status' => 'pending',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('email', 'member@example.com');

        $this->assertDatabaseHas('users', [
            'email' => 'member@example.com',
            'full_name' => 'Member User',
        ]);
        $this->assertDatabaseMissing('user_roles', [
            'user_id' => $response->json('id'),
            'role_code' => 'admin',
        ]);
    }

    public function test_admin_can_create_user_with_assigned_providers_and_links(): void
    {
        $admin = $this->createAdminUser();
        IntegrationProvider::query()->create([
            'code' => 'CURRENXIE',
            'name' => 'Currenxie',
            'status' => 'active',
        ]);
        IntegrationProvider::query()->create([
            'code' => 'AIRWALLEX',
            'name' => 'Airwallex',
            'status' => 'active',
        ]);

        $response = $this->withToken($this->issueTokenFor($admin))
            ->postJson('/api/admin/users', [
                'email' => 'providers@example.com',
                'full_name' => 'Provider User',
                'password' => 'secret123',
                'status' => 'active',
                'kyc_status' => 'pending',
                'integration_links' => [
                    [
                        'provider_code' => 'CURRENXIE',
                        'link_url' => 'https://provider.example.com/connect/currenxie',
                        'link_label' => 'Connect Currenxie',
                        'is_active' => true,
                    ],
                    [
                        'provider_code' => 'AIRWALLEX',
                        'link_url' => 'https://provider.example.com/connect/airwallex',
                        'link_label' => 'Connect Airwallex',
                        'is_active' => false,
                    ],
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonFragment(['code' => 'AIRWALLEX'])
            ->assertJsonFragment(['code' => 'CURRENXIE'])
            ->assertJsonPath('available_providers.0.code', 'AIRWALLEX');

        $this->assertDatabaseCount('user_integration_links', 2);
    }

    public function test_admin_can_replace_user_provider_assignments_via_update(): void
    {
        $admin = $this->createAdminUser();
        $user = User::factory()->create();
        $currenxie = IntegrationProvider::query()->create([
            'code' => 'CURRENXIE',
            'name' => 'Currenxie',
            'status' => 'active',
        ]);
        $airwallex = IntegrationProvider::query()->create([
            'code' => 'AIRWALLEX',
            'name' => 'Airwallex',
            'status' => 'active',
        ]);
        $user->integrationLinks()->create([
            'provider_id' => $currenxie->id,
            'link_url' => 'https://provider.example.com/connect/old',
            'link_label' => 'Old link',
            'is_active' => true,
        ]);

        $response = $this->withToken($this->issueTokenFor($admin))
            ->putJson("/api/admin/users/{$user->id}", [
                'integration_links' => [
                    [
                        'provider_code' => 'AIRWALLEX',
                        'link_url' => 'https://provider.example.com/connect/new',
                        'link_label' => 'New link',
                        'is_active' => true,
                    ],
                ],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('integration_links.0.provider.code', 'AIRWALLEX')
            ->assertJsonPath('available_providers.0.code', 'AIRWALLEX');

        $this->assertDatabaseMissing('user_integration_links', [
            'user_id' => $user->id,
            'provider_id' => $currenxie->id,
        ]);
        $this->assertDatabaseHas('user_integration_links', [
            'user_id' => $user->id,
            'provider_id' => $airwallex->id,
            'link_url' => 'https://provider.example.com/connect/new',
        ]);
        $this->assertDatabaseCount('user_integration_links', 1);
    }

    public function test_available_providers_only_include_active_providers(): void
    {
        $admin = $this->createAdminUser();
        $user = User::factory()->create();

        IntegrationProvider::query()->create([
            'code' => 'CURRENXIE',
            'name' => 'Currenxie',
            'status' => 'active',
        ]);
        IntegrationProvider::query()->create([
            'code' => 'LEGACY_BANK',
            'name' => 'Legacy Bank',
            'status' => 'inactive',
        ]);

        $this->withToken($this->issueTokenFor($admin))
            ->getJson("/api/admin/users/{$user->id}")
            ->assertOk()
            ->assertJsonFragment(['code' => 'CURRENXIE'])
            ->assertJsonMissing(['code' => 'LEGACY_BANK']);
    }

    public function test_admin_cannot_assign_inactive_provider_to_user(): void
    {
        $admin = $this->createAdminUser();

        IntegrationProvider::query()->create([
            'code' => 'LEGACY_BANK',
            'name' => 'Legacy Bank',
            'status' => 'inactive',
        ]);

        $response = $this->withToken($this->issueTokenFor($admin))
            ->postJson('/api/admin/users', [
                'email' => 'inactive-provider@example.com',
                'full_name' => 'Inactive Provider User',
                'password' => 'secret123',
                'integration_links' => [
                    [
                        'provider_code' => 'LEGACY_BANK',
                        'link_url' => 'https://provider.example.com/connect/legacy-bank',
                    ],
                ],
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['integration_links.0.provider_code']);

        $this->assertDatabaseMissing('users', [
            'email' => 'inactive-provider@example.com',
        ]);
        $this->assertDatabaseCount('user_integration_links', 0);
    }

    private function createAdminUser(): User
    {
        $user = User::factory()->create();
        $user->roles()->create([
            'role_code' => 'admin',
        ]);

        return $user;
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
