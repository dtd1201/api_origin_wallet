<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\IntegrationProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserIntegrationLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_upsert_integration_link_for_non_admin_user(): void
    {
        $admin = $this->createAdminUser();
        $user = User::factory()->create();
        $provider = IntegrationProvider::query()->create([
            'code' => 'CURRENXIE',
            'name' => 'Currenxie',
            'status' => 'active',
        ]);

        $response = $this->withToken($this->issueTokenFor($admin))
            ->putJson("/api/admin/users/{$user->id}/integration-links/{$provider->code}", [
                'link_url' => 'https://provider.example.com/connect/user-123',
                'link_label' => 'Connect provider',
                'is_active' => true,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('provider.code', 'CURRENXIE')
            ->assertJsonPath('integration_link.link_url', 'https://provider.example.com/connect/user-123');

        $this->assertDatabaseHas('user_integration_links', [
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'link_url' => 'https://provider.example.com/connect/user-123',
        ]);
    }

    public function test_admin_cannot_manage_integration_links_for_admin_user(): void
    {
        $admin = $this->createAdminUser();
        $targetAdmin = User::factory()->create();
        $targetAdmin->roles()->create([
            'role_code' => 'admin',
        ]);
        $provider = IntegrationProvider::query()->create([
            'code' => 'CURRENXIE',
            'name' => 'Currenxie',
            'status' => 'active',
        ]);

        $this->withToken($this->issueTokenFor($admin))
            ->putJson("/api/admin/users/{$targetAdmin->id}/integration-links/{$provider->code}", [
                'link_url' => 'https://provider.example.com/connect/admin',
            ])
            ->assertNotFound();
    }

    public function test_upserting_same_provider_for_same_user_keeps_single_link_record(): void
    {
        $admin = $this->createAdminUser();
        $user = User::factory()->create();
        $provider = IntegrationProvider::query()->create([
            'code' => 'CURRENXIE',
            'name' => 'Currenxie',
            'status' => 'active',
        ]);
        $token = $this->issueTokenFor($admin);

        $this->withToken($token)
            ->putJson("/api/admin/users/{$user->id}/integration-links/{$provider->code}", [
                'link_url' => 'https://provider.example.com/connect/first',
                'link_label' => 'Connect provider',
                'is_active' => true,
            ])
            ->assertOk();

        $this->withToken($token)
            ->putJson("/api/admin/users/{$user->id}/integration-links/{$provider->code}", [
                'link_url' => 'https://provider.example.com/connect/second',
                'link_label' => 'Reconnect provider',
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('integration_link.link_url', 'https://provider.example.com/connect/second')
            ->assertJsonPath('integration_link.link_label', 'Reconnect provider')
            ->assertJsonPath('integration_link.is_active', false);

        $this->assertDatabaseCount('user_integration_links', 1);
        $this->assertDatabaseHas('user_integration_links', [
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'link_url' => 'https://provider.example.com/connect/second',
            'link_label' => 'Reconnect provider',
            'is_active' => false,
        ]);
    }

    public function test_user_can_see_provider_link_in_integration_list(): void
    {
        $user = User::factory()->create();
        $user->profile()->create([
            'user_type' => 'business',
        ]);
        $provider = IntegrationProvider::query()->create([
            'code' => 'CURRENXIE',
            'name' => 'Currenxie',
            'status' => 'active',
        ]);
        $user->integrationLinks()->create([
            'provider_id' => $provider->id,
            'link_url' => 'https://provider.example.com/connect/user-456',
            'link_label' => 'Connect provider',
            'is_active' => true,
        ]);
        $token = $this->issueTokenFor($user);

        $response = $this->withToken($token)
            ->getJson("/api/user/users/{$user->id}/provider-accounts");

        $response
            ->assertOk()
            ->assertJsonPath('data.0.provider.code', 'CURRENXIE')
            ->assertJsonPath('data.0.integration_link.link_url', 'https://provider.example.com/connect/user-456')
            ->assertJsonPath('data.0.link_available', true);
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
