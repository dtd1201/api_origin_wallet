<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\IntegrationProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class NiumVirtualAccountEligibilityProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_pending_and_under_review_accounts_are_ineligible(): void
    {
        foreach (['pending', 'under_review'] as $status) {
            [$user, $token, $provider] = $this->context();
            $this->account($user, $provider, ['status' => $status, 'provider_status' => 'pending']);

            $this->withToken($token)
                ->getJson("/api/user/users/{$user->id}/provider-accounts")
                ->assertOk()
                ->assertJsonPath('data.0.can_request_virtual_account', false)
                ->assertJsonPath('data.0.virtual_account_eligibility_reason', 'kyb_approval_pending');
        }
    }

    public function test_clear_active_account_with_unverified_identifiers_is_ineligible(): void
    {
        [$user, $token, $provider] = $this->context();
        $this->account($user, $provider, [
            'customer_id_verified_at' => null,
            'wallet_id_verified_at' => null,
        ]);

        $this->withToken($token)
            ->getJson("/api/user/users/{$user->id}/provider-accounts")
            ->assertOk()
            ->assertJsonPath('data.0.can_request_virtual_account', false)
            ->assertJsonPath('data.0.virtual_account_eligibility_reason', 'account_verification_incomplete');
    }

    public function test_missing_mismatched_and_conflicted_accounts_are_ineligible(): void
    {
        [$missingUser, $missingToken] = $this->context();
        $this->withToken($missingToken)
            ->getJson("/api/user/users/{$missingUser->id}/provider-accounts")
            ->assertOk()
            ->assertJsonPath('data.0.can_request_virtual_account', false)
            ->assertJsonPath('data.0.virtual_account_eligibility_reason', 'account_missing');

        [$mismatchedUser, $mismatchedToken] = $this->context();
        $otherProvider = IntegrationProvider::query()->create([
            'code' => 'WISE',
            'name' => 'Wise',
            'status' => 'active',
        ]);
        $this->account($mismatchedUser, $otherProvider);
        $this->withToken($mismatchedToken)
            ->getJson("/api/user/users/{$mismatchedUser->id}/provider-accounts")
            ->assertOk()
            ->assertJsonPath('data.0.can_request_virtual_account', false)
            ->assertJsonPath('data.0.virtual_account_eligibility_reason', 'account_missing');

        [$conflictedUser, $conflictedToken, $nium] = $this->context();
        $this->account($conflictedUser, $nium, [
            'security_conflict_at' => now(),
            'security_conflict_reason' => 'provider_identifier_mismatch_secret',
        ]);
        $this->withToken($conflictedToken)
            ->getJson("/api/user/users/{$conflictedUser->id}/provider-accounts")
            ->assertOk()
            ->assertJsonPath('data.0.can_request_virtual_account', false)
            ->assertJsonPath('data.0.virtual_account_eligibility_reason', 'account_verification_incomplete');
    }

    public function test_approved_clear_account_with_verified_identifiers_is_eligible(): void
    {
        [$user, $token, $provider] = $this->context();
        $this->account($user, $provider);

        $this->withToken($token)
            ->getJson("/api/user/users/{$user->id}/provider-accounts")
            ->assertOk()
            ->assertJsonPath('data.0.can_request_virtual_account', true)
            ->assertJsonPath('data.0.virtual_account_eligibility_reason', null);
    }

    private function context(): array
    {
        $user = User::factory()->create();
        $user->profile()->create(['user_type' => 'business']);
        $provider = IntegrationProvider::query()->firstOrCreate(
            ['code' => 'nium'],
            ['name' => 'Nium', 'status' => 'active'],
        );
        $plainToken = Str::random(80);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'eligibility-test-token',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        return [$user, $plainToken, $provider];
    }

    private function account(User $user, IntegrationProvider $provider, array $overrides = []): void
    {
        $user->providerAccounts()->create(array_merge([
            'provider_id' => $provider->id,
            'status' => 'active',
            'provider_status' => 'clear',
            'provider_sub_status' => null,
            'external_customer_id' => 'customer-test',
            'external_account_id' => 'wallet-test',
            'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(),
        ], $overrides));
    }
}
