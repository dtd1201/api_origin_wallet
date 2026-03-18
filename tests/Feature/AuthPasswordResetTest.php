<?php

namespace Tests\Feature;

use App\Mail\PasswordResetCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_request_password_reset_code(): void
    {
        config()->set('mail.expose_verification_code', true);
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'user@example.com',
        ]);

        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => $user->email,
        ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('email', $user->email)
            ->assertJsonStructure([
                'message',
                'email',
                'expires_in_minutes',
                'verification_code',
            ]);

        $tokenRow = DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->first();

        $this->assertNotNull($tokenRow);
        $this->assertTrue(Hash::check($response->json('verification_code'), $tokenRow->token));

        Mail::assertSent(PasswordResetCodeMail::class, function (PasswordResetCodeMail $mail) use ($user, $response): bool {
            return $mail->hasTo($user->email)
                && $mail->verificationCode === $response->json('verification_code');
        });
    }

    public function test_reset_password_updates_password_and_clears_tokens(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password_hash' => Hash::make('old-password'),
        ]);

        $user->apiTokens()->create([
            'name' => 'test-token',
            'token_hash' => hash('sha256', 'existing-token'),
            'expires_at' => now()->addDay(),
        ]);

        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make('123456'),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'verification_code' => '123456',
            'password' => 'new-secret',
            'password_confirmation' => 'new-secret',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Password reset successful. Please log in again with your new password.');

        $user->refresh();

        $this->assertTrue(Hash::check('new-secret', $user->password_hash));
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => $user->email,
        ]);
        $this->assertDatabaseCount('api_tokens', 0);
    }

    public function test_reset_password_rejects_invalid_code(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password_hash' => Hash::make('old-password'),
        ]);

        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make('123456'),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'verification_code' => '654321',
            'password' => 'new-secret',
            'password_confirmation' => 'new-secret',
        ]);

        $response
            ->assertStatus(422)
            ->assertSee('Invalid password reset code.');

        $user->refresh();

        $this->assertTrue(Hash::check('old-password', $user->password_hash));
        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => $user->email,
        ]);
    }
}
