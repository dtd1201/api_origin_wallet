<?php

namespace Tests\Feature;

use App\Mail\LoginVerificationCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use App\Models\PendingLogin;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_start_admin_login_flow(): void
    {
        config()->set('mail.expose_verification_code', true);
        Mail::fake();

        $admin = User::factory()->create([
            'email' => 'admin@example.com',
        ]);
        $admin->roles()->create([
            'role_code' => 'admin',
        ]);

        $response = $this->postJson('/api/admin/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('email', 'admin@example.com')
            ->assertJsonStructure([
                'message',
                'email',
                'expires_in_minutes',
                'verification_code',
            ]);

        Mail::assertSent(LoginVerificationCodeMail::class, function (LoginVerificationCodeMail $mail) use ($admin): bool {
            return $mail->hasTo($admin->email);
        });
    }

    public function test_non_admin_cannot_start_admin_login_flow(): void
    {
        User::factory()->create([
            'email' => 'user@example.com',
        ]);

        $response = $this->postJson('/api/admin/auth/login', [
            'email' => 'user@example.com',
            'password' => 'password',
        ]);

        $response
            ->assertForbidden()
            ->assertJsonPath('message', 'This account is not allowed to access admin.');
    }

    public function test_admin_can_verify_admin_login_flow(): void
    {
        config()->set('mail.expose_verification_code', true);
        Mail::fake();

        $admin = User::factory()->create([
            'email' => 'admin@example.com',
        ]);
        $admin->roles()->create([
            'role_code' => 'super_admin',
        ]);

        $loginResponse = $this->postJson('/api/admin/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $verifyResponse = $this->postJson('/api/admin/auth/login/verify', [
            'email' => 'admin@example.com',
            'verification_code' => $loginResponse->json('verification_code'),
        ]);

        $verifyResponse
            ->assertOk()
            ->assertJsonPath('user.email', 'admin@example.com')
            ->assertJsonPath('user.roles.0', 'super_admin')
            ->assertJsonStructure([
                'message',
                'token',
                'token_type',
                'user',
            ]);
    }


    public function test_admin_can_resend_admin_login_verification_code(): void
    {
        config()->set('mail.expose_verification_code', true);
        Mail::fake();

        $admin = User::factory()->create([
            'email' => 'admin-resend@example.com',
        ]);

        $admin->roles()->create([
            'role_code' => 'admin',
        ]);

        $loginResponse = $this->postJson('/api/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $loginResponse->assertAccepted();

        $pending = PendingLogin::query()
            ->where('email', $admin->email)
            ->firstOrFail();

        $oldHash = $pending->verification_code_hash;

        $pending->update([
            'verification_sent_at' => now()->subSeconds(121),
        ]);

        $response = $this->postJson('/api/admin/auth/login/resend', [
            'email' => $admin->email,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('email', $admin->email)
            ->assertJsonPath('expires_in_minutes', 15)
            ->assertJsonPath('resend_cooldown_seconds', 120);

        $pending->refresh();

        $this->assertNotSame(
            $oldHash,
            $pending->verification_code_hash
        );

        $this->assertSame(0, $pending->verification_attempts);
        $this->assertNull($pending->locked_until);
        $this->assertNull($pending->last_attempt_at);
        $this->assertNotNull($pending->verification_sent_at);

        Mail::assertSent(LoginVerificationCodeMail::class, 2);
    }

    public function test_admin_login_verification_resend_is_blocked_during_cooldown(): void
    {
        Mail::fake();

        $admin = User::factory()->create([
            'email' => 'admin-resend-cooldown@example.com',
        ]);

        $admin->roles()->create([
            'role_code' => 'admin',
        ]);

        $this->postJson('/api/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertAccepted();

        $response = $this->postJson('/api/admin/auth/login/resend', [
            'email' => $admin->email,
        ]);

        $response
            ->assertStatus(429)
            ->assertJsonPath(
                'message',
                'Please wait before requesting another verification code.'
            );

        Mail::assertSent(LoginVerificationCodeMail::class, 1);
    }

    public function test_non_admin_cannot_resend_admin_login_verification_code(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'user-resend@example.com',
        ]);

        $response = $this->postJson('/api/admin/auth/login/resend', [
            'email' => $user->email,
        ]);

        $response->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_admin_login_verification_resend_requires_pending_login(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/admin/auth/login/resend', [
            'email' => 'missing-pending@example.com',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'No pending login found for this email.'
            );

        Mail::assertNothingSent();
    }

}
