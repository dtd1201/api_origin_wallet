<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\IntegrationProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NiumBiometricKycApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_and_admin_detail_responses_expose_only_the_current_biometric_kyc_url(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create();
        $admin->roles()->create(['role_code' => 'admin']);
        $url = 'https://idv.nium.com/prod/jumio/start?referenceNumber=current';

        $this->createNiumAccount($user, [
            'old' => $this->attempt('accepted', '2026-10-01T00:00:00Z', 'https://idv.nium.com/old'),
            'current' => $this->attempt('accepted', '2026-10-02T00:00:00Z', $url),
        ]);

        $expected = [
            'status' => 'initiated',
            'mode' => 'biometric_kyc',
            'url' => $url,
            'reference_id' => 'reference-current',
        ];

        $this->withToken($this->issueTokenFor($user))
            ->getJson("/api/user/users/{$user->id}/kyc-profile")
            ->assertOk()
            ->assertJsonPath('biometric_kyc', $expected);

        $this->withToken($this->issueTokenFor($admin))
            ->getJson("/api/admin/users/{$user->id}/kyc-profile")
            ->assertOk()
            ->assertJsonPath('biometric_kyc', $expected);
    }

    public function test_missing_url_and_newer_rejected_attempt_do_not_expose_a_historical_url(): void
    {
        $userWithoutUrl = User::factory()->create();
        $userWithRejectedCurrentAttempt = User::factory()->create();

        $this->createNiumAccount($userWithoutUrl, [
            'current' => $this->attempt('accepted', '2026-10-02T00:00:00Z', null),
        ]);
        $this->createNiumAccount($userWithRejectedCurrentAttempt, [
            'old' => $this->attempt('accepted', '2026-10-01T00:00:00Z', 'https://idv.nium.com/old'),
            'current' => $this->attempt('rejected', '2026-10-02T00:00:00Z', 'https://idv.nium.com/rejected'),
        ]);

        foreach ([$userWithoutUrl, $userWithRejectedCurrentAttempt] as $user) {
            $this->withToken($this->issueTokenFor($user))
                ->getJson("/api/user/users/{$user->id}/kyc-profile")
                ->assertOk()
                ->assertJsonPath('biometric_kyc', null);
        }
    }

    public function test_user_cannot_access_another_users_url_and_non_admin_cannot_access_admin_detail(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->createNiumAccount($owner, [
            'current' => $this->attempt('accepted', '2026-10-02T00:00:00Z', 'https://idv.nium.com/private'),
        ]);

        $token = $this->issueTokenFor($other);

        $this->withToken($token)
            ->getJson("/api/user/users/{$owner->id}/kyc-profile")
            ->assertForbidden();
        $this->withToken($token)
            ->getJson("/api/admin/users/{$owner->id}/kyc-profile")
            ->assertForbidden();
    }

    private function createNiumAccount(User $user, array $attempts): void
    {
        $provider = IntegrationProvider::query()->firstOrCreate(
            ['code' => 'nium'],
            ['name' => 'Nium', 'status' => 'active'],
        );

        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => (string) Str::uuid(),
            'status' => 'pending',
            'metadata' => ['nium_submit_kyc_attempts' => $attempts],
        ]);
    }

    private function attempt(string $state, string $updatedAt, ?string $url): array
    {
        return [
            'state' => $state,
            'kyc_status' => 'initiated',
            'kyc_mode' => 'biometric_kyc',
            'biometric_url' => $url,
            'biometric_url_fingerprint' => $url === null ? null : hash('sha256', $url),
            'provider_reference_id' => 'reference-current',
            'updated_at' => $updatedAt,
        ];
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
