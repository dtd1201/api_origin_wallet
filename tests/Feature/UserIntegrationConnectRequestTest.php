<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\IntegrationProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserIntegrationConnectRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_sees_all_active_providers_and_can_request_connect_when_link_missing(): void
    {
        $user = User::factory()->create();
        $user->profile()->create([
            'user_type' => 'business',
        ]);
        IntegrationProvider::query()->create([
            'code' => 'AIRWALLEX',
            'name' => 'Airwallex',
            'status' => 'active',
        ]);
        IntegrationProvider::query()->create([
            'code' => 'CURRENXIE',
            'name' => 'Currenxie',
            'status' => 'active',
        ]);

        $response = $this->withToken($this->issueTokenFor($user))
            ->getJson("/api/user/users/{$user->id}/provider-accounts");

        $response
            ->assertOk()
            ->assertJsonFragment(['code' => 'AIRWALLEX'])
            ->assertJsonFragment(['code' => 'CURRENXIE'])
            ->assertJsonPath('data.0.can_request_connect', true);
    }

    public function test_user_can_submit_connect_request_for_provider_without_link(): void
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

        $response = $this->withToken($this->issueTokenFor($user))
            ->postJson("/api/user/users/{$user->id}/provider-accounts/{$provider->code}/request-connect", [
                'note' => 'Please enable this provider for my account.',
            ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('request_pending', true)
            ->assertJsonPath('integration_request.status', 'pending');

        $this->assertDatabaseHas('user_integration_requests', [
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'status' => 'pending',
        ]);
    }

    public function test_admin_can_see_pending_connect_request_in_integration_link_list(): void
    {
        $admin = $this->createAdminUser();
        $user = User::factory()->create();
        $provider = IntegrationProvider::query()->create([
            'code' => 'CURRENXIE',
            'name' => 'Currenxie',
            'status' => 'active',
        ]);
        $user->integrationRequests()->create([
            'provider_id' => $provider->id,
            'status' => 'pending',
            'note' => 'Need access',
            'requested_at' => now(),
        ]);

        $response = $this->withToken($this->issueTokenFor($admin))
            ->getJson("/api/admin/users/{$user->id}/integration-links");

        $response
            ->assertOk()
            ->assertJsonPath('data.0.provider.code', 'CURRENXIE')
            ->assertJsonPath('data.0.integration_request.status', 'pending');
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
