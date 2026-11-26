<?php

namespace Tests\Feature;

use App\Models\AuthChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Support\MfaFixture;
use Tests\Support\TotpTestTools;
use Tests\Support\IsolatedAuthTestCase as TestCase;

class AuthVerificationSecurityTest extends TestCase
{
    use RefreshDatabase, TotpTestTools;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfMinute());
        Mail::fake();
        config(['mail.expose_verification_code' => true, 'totp.rates.credentials' => [100, 100],
            'totp.rates.verification' => [100, 100], 'auth.verification.account_rate_limit' => 100,
            'auth.verification.ip_rate_limit' => 100]);
    }

    public function test_wrong_totp_attempts_commit_and_exhaust_the_challenge(): void
    {
        $user = User::factory()->create();
        MfaFixture::factor($user);
        $token = $this->loginChallenge($user);
        $invalid = $this->invalidOtp();
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->ceremonyPost('/api/auth/login/verify', $token, ['totp_code' => $invalid])->assertStatus(422);
            $this->assertSame($attempt, $this->storedChallenge($token)->attempts);
        }
        $this->assertNotNull($this->storedChallenge($token)->revoked_at);
        $this->ceremonyPost('/api/auth/login/verify', $token, ['totp_code' => $this->otpCode()])->assertStatus(422);
        $this->assertDatabaseCount('api_tokens', 0);
        $this->assertDatabaseHas('auth_security_events', ['event_type' => 'authentication_challenge_failed']);
    }

    public function test_shared_account_and_ip_limits_survive_challenge_restart(): void
    {
        config(['totp.rates.verification' => [1, 100]]);
        $user = User::factory()->create();
        MfaFixture::factor($user);
        $a = $this->loginChallenge($user);
        $b = $this->loginChallenge($user);
        $this->ceremonyPost('/api/auth/login/verify', $a, ['totp_code' => $this->invalidOtp()])->assertStatus(422);
        $this->ceremonyPost('/api/auth/login/verify', $b, ['totp_code' => $this->otpCode()])->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseHas('auth_security_events', ['event_type' => 'authentication_rate_limited']);
    }

    public function test_ip_budget_applies_across_accounts_and_portals(): void
    {
        config(['totp.rates.credentials' => [100, 1]]);
        foreach (['a', 'b'] as $name) {
            User::factory()->create(['email' => $name.'@example.com']);
        }
        $this->postJson('/api/auth/login', ['email' => 'a@example.com', 'password' => 'password', 'client_binding' => $this->binding])->assertAccepted();
        $this->postJson('/api/admin/auth/login', ['email' => 'b@example.com', 'password' => 'password', 'client_binding' => $this->binding])->assertStatus(429);
    }

    public function test_cache_lock_failure_fails_closed(): void
    {
        Cache::shouldReceive('lock')->andThrow(new \RuntimeException('synthetic unavailable store'));
        $this->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'password', 'client_binding' => $this->binding])
            ->assertStatus(503)->assertJsonMissingPath('exception');
        $this->assertDatabaseCount('auth_challenges', 0);
    }

    public function test_password_reset_attempt_limit_and_replay_prevention_remain(): void
    {
        config(['auth.verification.max_attempts' => 2]);
        $user = User::factory()->create();
        $reset = $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertAccepted()->json('verification_code');
        $wrong = $reset === '000000' ? '111111' : '000000';
        $body = ['email' => $user->email, 'verification_code' => $wrong, 'password' => 'new-password', 'password_confirmation' => 'new-password'];
        $this->postJson('/api/auth/reset-password', $body)->assertStatus(422);
        $this->postJson('/api/auth/reset-password', $body)->assertStatus(422);
        $this->assertDatabaseHas('auth_security_events', ['event_type' => 'password_reset_verification_lockout']);
        $body['verification_code'] = $reset;
        $this->postJson('/api/auth/reset-password', $body)->assertStatus(422);
        DB::table('password_reset_tokens')->where('email', $user->email)->update(['locked_until' => null, 'verification_attempts' => 0]);
        $this->postJson('/api/auth/reset-password', $body)->assertOk();
        $this->postJson('/api/auth/reset-password', $body)->assertStatus(422);
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password_hash));
    }
}
