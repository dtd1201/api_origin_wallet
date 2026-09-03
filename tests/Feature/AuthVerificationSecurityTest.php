<?php

namespace Tests\Feature;

use App\Models\PendingLogin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthVerificationSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mail.expose_verification_code', true);
        config()->set('auth.verification.account_rate_limit', 100);
        config()->set('auth.verification.ip_rate_limit', 100);
        Mail::fake();
    }

    public function test_correct_hashed_login_code_succeeds_without_changing_login_contract(): void
    {
        $user = User::factory()->create(['email' => 'correct@example.com']);
        $login = $this->startLogin($user);
        $pending = PendingLogin::query()->where('email', $user->email)->firstOrFail();

        $this->assertNull($pending->verification_code);
        $this->assertNotSame($login->json('verification_code'), $pending->verification_code_hash);
        $this->assertTrue(Hash::check($login->json('verification_code'), $pending->verification_code_hash));

        $this->postJson('/api/auth/login/verify', [
            'email' => $user->email,
            'verification_code' => $login->json('verification_code'),
        ])->assertOk()
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonStructure(['token', 'token_type', 'user', 'onboarding', 'providers']);

        $this->assertDatabaseMissing('pending_logins', ['email' => $user->email]);
        $this->assertDatabaseCount('api_tokens', 1);
    }

    public function test_wrong_code_increments_attempts_and_records_security_events(): void
    {
        config()->set('auth.verification.suspicious_attempt_threshold', 2);
        $user = User::factory()->create(['email' => 'wrong@example.com']);
        $login = $this->startLogin($user);
        $wrongCode = $this->wrongCode($login->json('verification_code'));

        foreach (range(1, 2) as $attempt) {
            $this->postJson('/api/auth/login/verify', [
                'email' => $user->email,
                'verification_code' => $wrongCode,
            ])->assertStatus(422)->assertJsonPath('message', 'Invalid verification code.');

            $this->assertDatabaseHas('pending_logins', [
                'email' => $user->email,
                'verification_attempts' => $attempt,
            ]);
        }

        $this->assertDatabaseHas('auth_security_events', [
            'user_id' => $user->id,
            'event_type' => 'login_verification_failed',
        ]);
        $this->assertDatabaseHas('auth_security_events', [
            'user_id' => $user->id,
            'event_type' => 'suspicious_verification_attempts',
        ]);
    }

    public function test_maximum_attempts_locks_login_and_blocks_the_correct_code(): void
    {
        config()->set('auth.verification.max_attempts', 2);
        $user = User::factory()->create(['email' => 'locked@example.com']);
        $login = $this->startLogin($user);
        $wrongCode = $this->wrongCode($login->json('verification_code'));

        $this->postJson('/api/auth/login/verify', [
            'email' => $user->email,
            'verification_code' => $wrongCode,
        ])->assertStatus(422);
        $this->postJson('/api/auth/login/verify', [
            'email' => $user->email,
            'verification_code' => $wrongCode,
        ])->assertStatus(422)->assertJsonPath('message', 'Too many invalid verification attempts. Please request a new code.');

        $this->assertNotNull(PendingLogin::query()->where('email', $user->email)->value('locked_until'));
        $this->assertDatabaseHas('auth_security_events', ['event_type' => 'login_verification_lockout']);

        $this->postJson('/api/auth/login/verify', [
            'email' => $user->email,
            'verification_code' => $login->json('verification_code'),
        ])->assertStatus(422)->assertJsonPath('message', 'Too many invalid verification attempts. Please request a new code.');
    }

    public function test_expired_login_code_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'expired@example.com']);
        $login = $this->startLogin($user);
        PendingLogin::query()->where('email', $user->email)->update(['expires_at' => now()->subSecond()]);

        $this->postJson('/api/auth/login/verify', [
            'email' => $user->email,
            'verification_code' => $login->json('verification_code'),
        ])->assertStatus(422)->assertJsonPath('message', 'Verification code has expired.');
    }

    public function test_account_verification_rate_limit_is_enforced_and_recorded(): void
    {
        config()->set('auth.verification.account_rate_limit', 1);
        $user = User::factory()->create(['email' => 'rate@example.com']);
        $login = $this->startLogin($user);

        $payload = ['email' => $user->email, 'verification_code' => $this->wrongCode($login->json('verification_code'))];
        $this->postJson('/api/auth/login/verify', $payload)->assertStatus(422);
        $this->postJson('/api/auth/login/verify', $payload)
            ->assertStatus(429)
            ->assertJsonPath('message', 'Too many verification attempts. Please try again later.');

        $this->assertDatabaseHas('auth_security_events', ['event_type' => 'verification_rate_limited']);
    }

    public function test_ip_verification_rate_limit_applies_across_accounts(): void
    {
        config()->set('auth.verification.ip_rate_limit', 1);
        $first = User::factory()->create(['email' => 'ip-first@example.com']);
        $second = User::factory()->create(['email' => 'ip-second@example.com']);
        $firstLogin = $this->startLogin($first);
        $secondLogin = $this->startLogin($second);
        $server = ['REMOTE_ADDR' => '198.51.100.42'];

        $this->withServerVariables($server)->postJson('/api/auth/login/verify', [
            'email' => $first->email,
            'verification_code' => $this->wrongCode($firstLogin->json('verification_code')),
        ])->assertStatus(422);

        $this->withServerVariables($server)->postJson('/api/auth/login/verify', [
            'email' => $second->email,
            'verification_code' => $this->wrongCode($secondLogin->json('verification_code')),
        ])->assertStatus(429);
    }

    public function test_password_reset_attempt_limit_and_replay_prevention(): void
    {
        config()->set('auth.verification.max_attempts', 2);
        $user = User::factory()->create([
            'email' => 'reset-security@example.com',
            'password_hash' => Hash::make('old-password'),
        ]);
        $reset = $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertAccepted();
        $wrongCode = $this->wrongCode($reset->json('verification_code'));
        $payload = [
            'email' => $user->email,
            'verification_code' => $wrongCode,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ];

        $this->postJson('/api/auth/reset-password', $payload)->assertStatus(422);
        $this->postJson('/api/auth/reset-password', $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Too many invalid password reset attempts. Please request a new code.');
        $this->assertDatabaseHas('auth_security_events', ['event_type' => 'password_reset_verification_lockout']);

        DB::table('password_reset_tokens')->where('email', $user->email)->update([
            'verification_attempts' => 0,
            'locked_until' => null,
        ]);
        $payload['verification_code'] = $reset->json('verification_code');
        $this->postJson('/api/auth/reset-password', $payload)->assertOk();
        $this->postJson('/api/auth/reset-password', $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', 'No pending password reset found for this email.');
    }

    private function startLogin(User $user)
    {
        return $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertAccepted()
            ->assertJsonStructure(['message', 'email', 'expires_in_minutes', 'verification_code']);
    }

    private function wrongCode(string $correctCode): string
    {
        return $correctCode === '000000' ? '999999' : '000000';
    }
}
