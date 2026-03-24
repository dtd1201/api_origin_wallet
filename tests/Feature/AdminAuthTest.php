<?php

namespace Tests\Feature;

use App\Mail\LoginVerificationCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

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
}
