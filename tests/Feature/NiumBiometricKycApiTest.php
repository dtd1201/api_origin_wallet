<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Models\IntegrationProvider;
use App\Models\NiumApiExchangeEvidence;
use App\Models\User;
use App\Models\UserProviderAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_verified_submit_kyc_evidence_recovers_initiated_url_without_another_post(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $url = 'https://idv.nium.test/synthetic-recovered-session';
        $attempt = $this->attempt('accepted', '2026-10-02T00:00:00Z', $url);
        unset($attempt['entity_kyc_status']);
        $account = $this->createNiumAccount($user, ['current' => $attempt]);
        $log = $this->recordSubmitKyc($account, $url);
        $metadata = $account->metadata;
        $metadata['nium_submit_kyc_attempts']['current']['submit_kyc_log_id'] = $log->id;
        $metadata['nium_submit_kyc_attempts']['current']['submit_kyc_log_at'] = $log->created_at->toISOString();
        $account->update(['metadata' => $metadata]);

        $this->withToken($this->issueTokenFor($user))
            ->getJson("/api/user/users/{$user->id}/kyc-profile")
            ->assertOk()
            ->assertJsonPath('biometric_kyc.status', 'initiated')
            ->assertJsonPath('biometric_kyc.url', $url);

        Http::assertNothingSent();
        $this->assertDatabaseCount('api_request_logs', 1);
        $this->assertDatabaseCount('nium_api_exchange_evidence', 1);
    }

    public function test_terminal_attempt_status_takes_precedence_over_older_submit_initiated_evidence(): void
    {
        $user = User::factory()->create();
        $url = 'https://idv.nium.test/terminal-attempt-session';
        $attempt = $this->attempt('accepted', '2026-10-04T00:00:00Z', $url, 'failed');
        $account = $this->createNiumAccount($user, ['current' => $attempt]);
        $log = $this->recordSubmitKyc($account, $url);
        $metadata = $account->metadata;
        $metadata['nium_submit_kyc_attempts']['current']['submit_kyc_log_id'] = $log->id;
        $metadata['nium_submit_kyc_attempts']['current']['submit_kyc_log_at'] = '2026-10-02T00:00:00Z';
        $account->update(['metadata' => $metadata]);

        $this->withToken($this->issueTokenFor($user))
            ->getJson("/api/user/users/{$user->id}/kyc-profile")
            ->assertOk()->assertJsonPath('biometric_kyc', null);
    }

    public function test_failed_jumio_session_blocks_retry_that_reuses_the_same_url_and_reference(): void
    {
        $user = User::factory()->create();
        $url = 'https://idv.nium.test/reused-session?referenceNumber=same-reference';
        $attempt = $this->attempt('accepted', '2026-10-04T00:00:00Z', $url);
        unset($attempt['entity_kyc_status']);
        $account = $this->createNiumAccount($user, ['current' => $attempt]);

        $this->recordCustomerGet($account, [
            'customerHashId' => $account->external_customer_id,
            'applicant' => $this->customerApplicant('initiated', $url),
        ], '2026-10-02T00:00:00Z');
        $this->recordCustomerGet($account, [
            'customerHashId' => $account->external_customer_id,
            'applicant' => $this->customerApplicant('failed', $url),
        ], '2026-10-03T00:00:00Z');
        $log = $this->recordSubmitKyc($account, $url);
        $metadata = $account->metadata;
        $metadata['nium_submit_kyc_attempts']['current']['submit_kyc_log_id'] = $log->id;
        $metadata['nium_submit_kyc_attempts']['current']['submit_kyc_log_at'] = '2026-10-04T00:00:00Z';
        $account->update(['metadata' => $metadata]);

        $this->withToken($this->issueTokenFor($user))
            ->getJson("/api/user/users/{$user->id}/kyc-profile")
            ->assertOk()->assertJsonPath('biometric_kyc', null);
    }

    public function test_retry_with_a_different_verified_url_is_not_blocked_by_an_older_failed_session(): void
    {
        $user = User::factory()->create();
        $oldUrl = 'https://idv.nium.test/failed-session?referenceNumber=old-session';
        $newUrl = 'https://idv.nium.test/new-session?referenceNumber=new-session';
        $attempt = $this->attempt('accepted', '2026-10-04T00:00:00Z', $newUrl);
        unset($attempt['entity_kyc_status']);
        $account = $this->createNiumAccount($user, ['current' => $attempt]);
        $this->recordCustomerGet($account, [
            'customerHashId' => $account->external_customer_id,
            'applicant' => $this->customerApplicant('failed', $oldUrl),
        ], '2026-10-03T00:00:00Z');
        $log = $this->recordSubmitKyc($account, $newUrl);
        $metadata = $account->metadata;
        $metadata['nium_submit_kyc_attempts']['current']['submit_kyc_log_id'] = $log->id;
        $metadata['nium_submit_kyc_attempts']['current']['submit_kyc_log_at'] = '2026-10-04T00:00:00Z';
        $account->update(['metadata' => $metadata]);

        $this->withToken($this->issueTokenFor($user))
            ->getJson("/api/user/users/{$user->id}/kyc-profile")
            ->assertOk()
            ->assertJsonPath('biometric_kyc.status', 'initiated')
            ->assertJsonPath('biometric_kyc.url', $newUrl);
    }

    public function test_newest_entity_state_wins_over_older_history_for_the_same_applicant(): void
    {
        $user = User::factory()->create();
        $url = 'https://idv.nium.test/newest-entity-state';
        $older = $this->entity('failed');
        $older['updated_at'] = '2026-10-03T00:00:00Z';
        $newer = $this->entity('retry');
        $newer['updated_at'] = '2026-10-05T00:00:00Z';
        $this->createNiumAccount($user, [
            'current' => $this->attempt('accepted', '2026-10-02T00:00:00Z', $url, 'retry'),
        ], [$older, $newer]);

        $this->withToken($this->issueTokenFor($user))
            ->getJson("/api/user/users/{$user->id}/kyc-profile")
            ->assertOk()
            ->assertJsonPath('biometric_kyc.status', 'retry')
            ->assertJsonPath('biometric_kyc.url', $url);
    }

    public function test_submit_kyc_recovery_fails_closed_for_mismatched_or_untrusted_evidence(): void
    {
        foreach (['failed', 'external_id', 'reference_id', 'fingerprint', 'hash', 'http'] as $case) {
            $user = User::factory()->create();
            $url = 'https://idv.nium.test/recovery-'.$case;
            $attempt = $this->attempt('accepted', '2026-10-02T00:00:00Z', $url);
            unset($attempt['entity_kyc_status']);
            $account = $this->createNiumAccount($user, ['current' => $attempt]);
            $overrides = match ($case) {
                'failed' => ['kycStatus' => 'failed'],
                'external_id' => ['externalId' => 'origin-wallet-applicant-other'],
                'reference_id' => ['referenceId' => 'reference-other'],
                default => [],
            };
            $log = $this->recordSubmitKyc($account, $url, $overrides, $case === 'http' ? 500 : 200);
            if ($case === 'hash') {
                $log->niumExchangeEvidence()->update(['response_body_sha256' => str_repeat('0', 64)]);
            }
            $metadata = $account->metadata;
            $metadata['nium_submit_kyc_attempts']['current']['submit_kyc_log_id'] = $log->id;
            $metadata['nium_submit_kyc_attempts']['current']['submit_kyc_log_at'] = $log->created_at->toISOString();
            if ($case === 'fingerprint') {
                $metadata['nium_submit_kyc_attempts']['current']['biometric_url_fingerprint'] = str_repeat('0', 16);
            }
            $account->update(['metadata' => $metadata]);

            $this->withToken($this->issueTokenFor($user))
                ->getJson("/api/user/users/{$user->id}/kyc-profile")
                ->assertOk()->assertJsonPath('biometric_kyc', null);
        }
    }

    public function test_newer_terminal_entity_status_prevents_stale_submit_url_exposure(): void
    {
        $user = User::factory()->create();
        $url = 'https://idv.nium.test/stale-session';
        $attempt = $this->attempt('accepted', '2026-10-02T00:00:00Z', $url);
        unset($attempt['entity_kyc_status']);
        $account = $this->createNiumAccount($user, ['current' => $attempt], [$this->entity('completed')]);
        $log = $this->recordSubmitKyc($account, $url);
        $metadata = $account->metadata;
        $metadata['nium_submit_kyc_attempts']['current']['submit_kyc_log_id'] = $log->id;
        $metadata['nium_submit_kyc_attempts']['current']['submit_kyc_log_at'] = '2026-10-02T00:00:00Z';
        $account->update(['metadata' => $metadata]);

        $this->withToken($this->issueTokenFor($user))
            ->getJson("/api/user/users/{$user->id}/kyc-profile")
            ->assertOk()->assertJsonPath('biometric_kyc', null);
    }

    public function test_missing_account_mismatched_and_ambiguous_evidence_fail_closed(): void
    {
        foreach (['missing', 'account', 'ambiguous'] as $case) {
            $user = User::factory()->create();
            $url = 'https://idv.nium.test/fail-closed-'.$case;
            $attempt = $this->attempt('accepted', '2026-10-02T00:00:00Z', $url);
            unset($attempt['entity_kyc_status']);
            $entities = $case === 'ambiguous'
                ? [$this->entity('initiated'), $this->entity('completed')]
                : [];
            $account = $this->createNiumAccount($user, ['current' => $attempt], $entities);
            $metadata = $account->metadata;

            if ($case !== 'missing') {
                $log = $this->recordSubmitKyc($account, $url);
                $metadata['nium_submit_kyc_attempts']['current']['submit_kyc_log_id'] = $log->id;
                $metadata['nium_submit_kyc_attempts']['current']['submit_kyc_log_at'] = '2026-10-02T00:00:00Z';
                if ($case === 'account') {
                    $log->update([
                        'request_url' => 'https://api.nium.test/api/v5/customer/another-customer/submitKyc',
                        'endpoint_path' => '/api/v5/customer/another-customer/submitKyc',
                    ]);
                }
            }
            $account->update(['metadata' => $metadata]);

            $this->withToken($this->issueTokenFor($user))
                ->getJson("/api/user/users/{$user->id}/kyc-profile")
                ->assertOk()->assertJsonPath('biometric_kyc', null);
        }
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
            'biometric_url_fingerprint' => $url === null ? null : substr(hash('sha256', $url), 0, 16),
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

    private function customerApplicant(string $status, string $url): array
    {
        return [
            'entityType' => 'applicant',
            'externalId' => 'origin-wallet-applicant-current',
            'referenceId' => 'reference-current',
            'kycMode' => 'biometric_kyc',
            'kycStatus' => $status,
            'biometricUrl' => $url,
        ];
    }

    private function recordCustomerGet(
        UserProviderAccount $account,
        array $response,
        ?string $createdAt = null,
    ): ApiRequestLog {
        $log = ApiRequestLog::query()->create([
            'provider_id' => $account->provider_id,
            'user_id' => $account->user_id,
            'operation' => 'get_nium_api',
            'request_method' => 'GET',
            'request_url' => 'https://api.nium.test/api/v5/customer/'.$account->external_customer_id,
            'endpoint_path' => '/api/v5/customer/'.$account->external_customer_id,
            'response_status' => 200,
            'is_success' => true,
        ]);
        $raw = json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        NiumApiExchangeEvidence::query()->create([
            'api_request_log_id' => $log->id,
            'request_method' => 'GET',
            'request_url' => $log->request_url,
            'raw_response_body' => $raw,
            'response_body_sha256' => hash('sha256', $raw),
            'response_byte_length' => strlen($raw),
            'capture_version' => 1,
            'response_received' => true,
        ]);

        if ($createdAt !== null) {
            $log->forceFill(['created_at' => $createdAt])->save();
        }

        return $log->fresh();
    }

    private function recordSubmitKyc(
        UserProviderAccount $account,
        string $url,
        array $overrides = [],
        int $status = 200,
    ): ApiRequestLog {
        $response = array_replace([
            'externalId' => 'origin-wallet-applicant-current',
            'referenceId' => 'reference-current',
            'entityType' => 'applicant',
            'kycMode' => 'biometric_kyc',
            'kycStatus' => 'initiated',
            'biometricUrl' => $url,
        ], $overrides);
        $log = ApiRequestLog::query()->create([
            'provider_id' => $account->provider_id,
            'user_id' => $account->user_id,
            'operation' => 'submit_kyc',
            'external_reference' => 'reference-current',
            'request_method' => 'POST',
            'request_url' => 'https://api.nium.test/api/v5/customer/'.$account->external_customer_id.'/submitKyc',
            'endpoint_path' => '/api/v5/customer/'.$account->external_customer_id.'/submitKyc',
            'response_status' => $status,
            'is_success' => $status >= 200 && $status < 300,
        ]);
        $raw = json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        NiumApiExchangeEvidence::query()->create([
            'api_request_log_id' => $log->id,
            'request_method' => 'POST',
            'request_url' => $log->request_url,
            'raw_response_body' => $raw,
            'response_body_sha256' => hash('sha256', $raw),
            'response_byte_length' => strlen($raw),
            'capture_version' => 1,
            'response_received' => true,
        ]);

        return $log->fresh();
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
