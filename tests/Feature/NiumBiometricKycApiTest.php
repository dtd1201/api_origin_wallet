<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Models\IntegrationProvider;
use App\Models\NiumApiExchangeEvidence;
use App\Models\User;
use App\Models\UserProviderAccount;
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
        ], [$this->entity('initiated')]);

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
        ], [$this->entity('initiated')]);
        $this->createNiumAccount($userWithRejectedCurrentAttempt, [
            'old' => $this->attempt('accepted', '2026-10-01T00:00:00Z', 'https://idv.nium.com/old'),
            'current' => $this->attempt('rejected', '2026-10-02T00:00:00Z', 'https://idv.nium.com/rejected'),
        ], [$this->entity('initiated')]);

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
        ], [$this->entity('initiated')]);

        $token = $this->issueTokenFor($other);

        $this->withToken($token)
            ->getJson("/api/user/users/{$owner->id}/kyc-profile")
            ->assertForbidden();
        $this->withToken($token)
            ->getJson("/api/admin/users/{$owner->id}/kyc-profile")
            ->assertForbidden();
    }

    public function test_retry_status_with_biometric_url_is_returned(): void
    {
        $user = User::factory()->create();
        $url = 'https://idv.nium.com/retry-session';
        $this->createNiumAccount($user, [
            'current' => $this->attempt('accepted', '2026-10-02T00:00:00Z', $url, 'retry'),
        ], [$this->entity('retry')]);

        $this->withToken($this->issueTokenFor($user))
            ->getJson("/api/user/users/{$user->id}/kyc-profile")
            ->assertOk()
            ->assertJsonPath('biometric_kyc.status', 'retry')
            ->assertJsonPath('biometric_kyc.url', $url);
    }

    public function test_retry_without_biometric_url_returns_null(): void
    {
        $user = User::factory()->create();
        $this->createNiumAccount($user, [
            'current' => $this->attempt('accepted', '2026-10-02T00:00:00Z', null, 'retry'),
        ], [$this->entity('retry')]);

        $this->withToken($this->issueTokenFor($user))
            ->getJson("/api/user/users/{$user->id}/kyc-profile")
            ->assertOk()
            ->assertJsonPath('biometric_kyc', null);
    }

    public function test_mismatched_applicant_or_reference_returns_null(): void
    {
        foreach ([
            ['externalId' => 'origin-wallet-applicant-other', 'referenceId' => 'reference-current'],
            ['externalId' => 'origin-wallet-applicant-current', 'referenceId' => 'reference-other'],
        ] as $identifiers) {
            $user = User::factory()->create();
            $account = $this->createNiumAccount($user, [
                'current' => $this->attempt('accepted', '2026-10-02T00:00:00Z', null, 'retry'),
            ]);
            $this->recordCustomerGet($account, [
                'customerHashId' => $account->external_customer_id,
                'applicant' => [
                    ...$identifiers,
                    'entityType' => 'applicant',
                    'kycMode' => 'biometric_kyc',
                    'kycStatus' => 'retry',
                    'biometricUrl' => 'https://idv.nium.com/wrong-applicant',
                ],
            ]);

            $this->withToken($this->issueTokenFor($user))
                ->getJson("/api/user/users/{$user->id}/kyc-profile")
                ->assertOk()
                ->assertJsonPath('biometric_kyc', null);
        }
    }

    public function test_matching_persisted_customer_get_supplies_url_removed_from_attempt(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create();
        $admin->roles()->create(['role_code' => 'admin']);
        $url = 'https://idv.nium.com/customer-get-retry-session';
        $account = $this->createNiumAccount($user, [
            'current' => $this->attempt('accepted', '2026-10-02T00:00:00Z', null, 'retry'),
        ]);
        $this->recordCustomerGet($account, [
            'customerHashId' => $account->external_customer_id,
            'applicant' => [
                'entityType' => 'applicant',
                'externalId' => 'origin-wallet-applicant-current',
                'referenceId' => 'reference-current',
                'kycMode' => 'biometric_kyc',
                'kycStatus' => 'retry',
                'biometricUrl' => $url,
            ],
        ]);

        $this->withToken($this->issueTokenFor($user))
            ->getJson("/api/user/users/{$user->id}/kyc-profile")
            ->assertOk()
            ->assertJsonPath('biometric_kyc.status', 'retry')
            ->assertJsonPath('biometric_kyc.url', $url);

        $this->withToken($this->issueTokenFor($admin))
            ->getJson("/api/admin/users/{$user->id}/kyc-profile")
            ->assertOk()
            ->assertJsonPath('biometric_kyc.status', 'retry')
            ->assertJsonPath('biometric_kyc.url', $url);
    }

    private function createNiumAccount(User $user, array $attempts, array $entities = []): UserProviderAccount
    {
        $provider = IntegrationProvider::query()->firstOrCreate(
            ['code' => 'nium'],
            ['name' => 'Nium', 'status' => 'active'],
        );

        return $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => (string) Str::uuid(),
            'status' => 'pending',
            'metadata' => [
                'nium_submit_kyc_attempts' => $attempts,
                'nium_entity_kyc_states' => $entities,
            ],
        ]);
    }

    private function attempt(string $state, string $updatedAt, ?string $url, string $status = 'initiated'): array
    {
        return [
            'state' => $state,
            'entity_kyc_status' => $status,
            'kyc_mode' => 'biometric_kyc',
            'entity_type' => 'applicant',
            'external_id' => 'origin-wallet-applicant-current',
            'biometric_url' => $url,
            'biometric_url_fingerprint' => $url === null ? null : hash('sha256', $url),
            'provider_reference_id' => 'reference-current',
            'updated_at' => $updatedAt,
        ];
    }

    private function entity(
        string $status,
        string $externalId = 'origin-wallet-applicant-current',
        string $referenceId = 'reference-current',
    ): array {
        return [
            'entity_type' => 'applicant',
            'kyc_status' => $status,
            'kyc_mode' => 'biometric_kyc',
            'external_id' => $externalId,
            'provider_reference_id' => $referenceId,
            'source' => 'nium_v5_customer_get_response',
            'updated_at' => '2026-10-03T00:00:00Z',
        ];
    }

    private function recordCustomerGet(UserProviderAccount $account, array $response): void
    {
        $log = ApiRequestLog::query()->create([
            'provider_id' => $account->provider_id,
            'user_id' => $account->user_id,
            'operation' => 'get_nium_api',
            'request_method' => 'GET',
            'request_url' => 'https://api.nium.test/api/v5/customer',
            'endpoint_path' => '/api/v5/customer',
            'response_status' => 200,
            'is_success' => true,
        ]);
        $raw = json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        NiumApiExchangeEvidence::query()->create([
            'api_request_log_id' => $log->id,
            'request_method' => 'GET',
            'request_url' => 'https://api.nium.test/api/v5/customer',
            'raw_response_body' => $raw,
            'response_body_sha256' => hash('sha256', $raw),
            'response_byte_length' => strlen($raw),
            'capture_version' => 1,
            'response_received' => true,
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
