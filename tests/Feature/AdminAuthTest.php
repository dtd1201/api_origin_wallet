<?php

namespace Tests\Feature;

use App\Mail\LoginVerificationCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\MfaFixture;
use Tests\Support\TotpTestTools;
use Tests\Support\IsolatedAuthTestCase as TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase, TotpTestTools;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfMinute());
        Mail::fake();
        Http::preventStrayRequests();
        config(['totp.rates.credentials' => [100, 100], 'totp.rates.verification' => [100, 100]]);
    }

    public function test_admin_login_requires_totp_without_sending_email(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->create(['role_code' => 'super_admin']);
        MfaFixture::factor($admin);
        $challenge = $this->loginChallenge($admin, 'admin');
        $this->assertDatabaseCount('api_tokens', 0);
        Mail::assertNotSent(LoginVerificationCodeMail::class);
        $result = $this->ceremonyPost('/api/admin/auth/login/verify', $challenge, ['totp_code' => $this->otpCode()])
            ->assertOk()->assertJsonPath('user.id', $admin->id)->assertJsonStructure(['token', 'token_type', 'user', 'onboarding', 'providers']);
        $this->withToken($result->json('token'))->getJson('/api/admin/auth/me')->assertOk();
        $this->withToken($result->json('token'))->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_non_admin_and_unknown_credentials_have_the_same_failure(): void
    {
        $user = User::factory()->create();
        foreach ([$user->email, 'unknown@example.com'] as $email) {
            $this->postJson('/api/admin/auth/login', ['email' => $email, 'password' => 'password', 'client_binding' => $this->binding])
                ->assertStatus(422)->assertJsonPath('message', 'Authentication could not be completed.');
        }
        $this->assertDatabaseCount('auth_challenges', 0);
    }

    public function test_admin_role_is_rechecked_at_completion(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->create(['role_code' => 'admin']);
        MfaFixture::factor($admin);
        $challenge = $this->loginChallenge($admin, 'admin');
        $admin->roles()->delete();
        $this->ceremonyPost('/api/admin/auth/login/verify', $challenge, ['totp_code' => $this->otpCode()])->assertStatus(422);
        $this->assertDatabaseCount('api_tokens', 0);
    }

    public function test_both_resend_routes_are_retired_even_with_a_pending_legacy_code(): void
    {
        $user = User::factory()->create();
        \App\Models\PendingLogin::create(['user_id' => $user->id, 'email' => $user->email,
            'verification_code_hash' => \Illuminate\Support\Facades\Hash::make('123456'), 'expires_at' => now()->addMinutes(15)]);
        foreach (['/api/auth', '/api/admin/auth'] as $prefix) {
            $this->postJson($prefix.'/login/resend', ['email' => $user->email])->assertStatus(410);
            $this->postJson($prefix.'/login/verify', ['email' => $user->email, 'verification_code' => '123456'])->assertStatus(422);
        }
        Mail::assertNothingSent();
        $this->assertDatabaseCount('api_tokens', 0);
    }
}
