<?php

namespace Tests\Feature;

use App\Mail\EnrollmentIdentityProofMail;
use App\Mail\LoginVerificationCodeMail;
use App\Mail\RegistrationVerificationCodeMail;
use App\Models\AuthChallenge;
use App\Models\AuthRecoveryCode;
use App\Models\AuthRegistrationEvidence;
use App\Models\User;
use App\Models\UserAuthFactor;
use App\Services\Auth\RecoveryCodeService;
use App\Services\Auth\TotpEnrollmentService;
use App\Services\Auth\TotpRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MfaFixture;
use Tests\Support\TotpTestTools;
use Tests\Support\IsolatedAuthTestCase as TestCase;

class MandatoryTotpSimplificationTest extends TestCase
{
    use RefreshDatabase, TotpTestTools;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfMinute());
        Mail::fake();
        Http::preventStrayRequests();
        config(['totp.migration.enabled' => false, 'totp.assisted.enabled' => false,
            'mail.expose_verification_code' => false, 'totp.rates.credentials' => [100, 100],
            'totp.rates.verification' => [100, 100], 'totp.rates.identity_send' => [100, 100],
            'totp.rates.registration' => [100, 100]]);
    }

    public static function portals(): array { return [['user'], ['admin']]; }

    private function legacy(string $audience = 'user'): User
    {
        $user = User::factory()->create();
        // Synthetic equivalent of the cutover destination snapshot. This is
        // never email_verified_at or a trusted enrollment completion.
        $user->forceFill(['auth_legacy_email_hash' => hash('sha256', $user->email),
            'auth_legacy_password_fingerprint' => hash('sha256', $user->password_hash),
            'auth_legacy_not_before' => now()->subDay()])->save();
        if ($audience === 'admin') $user->roles()->create(['role_code' => 'admin']);
        return $user;
    }

    private function requestProof(User $user, string $audience = 'user'): array
    {
        $token = $this->loginChallenge($user, $audience);
        $this->ceremonyPost($this->authPath($audience).'/enrollment/identity/request', $token)
            ->assertAccepted()->assertJsonPath('methods.0', 'enrollment_email_proof')->assertJsonMissingPath('token')
            ->assertJsonMissingPath('identity_proof')->assertJsonMissingPath('verification_code');
        $mail = Mail::sent(EnrollmentIdentityProofMail::class)->last();
        $this->assertTrue($mail->hasTo($user->email));
        return [$token, $mail->proof];
    }

    private function verifyProof(string $token, string $proof, string $audience = 'user'): string
    {
        return $this->ceremonyPost($this->authPath($audience).'/enrollment/identity/verify', $token, ['identity_proof' => $proof])
            ->assertAccepted()->assertJsonPath('next_action', 'enrollment_ready')->assertJsonMissingPath('token')->json('challenge_token');
    }

    #[DataProvider('portals')]
    public function test_legacy_account_enrolls_without_grants_or_verified_timestamp_and_subsequently_uses_only_totp(string $audience): void
    {
        $user = $this->legacy($audience);
        [$identity, $proof] = $this->requestProof($user, $audience);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertMatchesRegularExpression('/\A[0-9]{6}\z/', $proof);
        $stored = $this->storedChallenge($identity);
        $this->assertNotSame($proof, $stored->identity_proof_hash);
        $this->assertDatabaseCount('api_tokens', 0);
        $this->ceremonyPost($this->authPath($audience).'/enrollment/setup', $identity)->assertStatus(422);
        $setup = $this->verifyProof($identity, $proof, $audience);
        $this->assertNotNull($this->storedChallenge($identity)->consumed_at);
        $this->assertNull($this->storedChallenge($identity)->identity_proof_hash);
        $this->ceremonyPost($this->authPath($audience).'/enrollment/identity/verify', $identity, ['identity_proof' => $proof])->assertStatus(422);
        $secret = $this->ceremonyPost($this->authPath($audience).'/enrollment/setup', $setup)->assertOk()->json('manual_key');
        $valid = [$this->otpCode($secret, -1), $this->otpCode($secret), $this->otpCode($secret, 1)];
        foreach (['000000', '000001', '000002', '000003'] as $invalid) {
            if (! in_array($invalid, $valid, true)) break;
        }
        $this->ceremonyPost($this->authPath($audience).'/enrollment/confirm', $setup, ['totp_code' => $invalid])->assertStatus(422);
        $this->assertDatabaseCount('user_auth_factors', 0);
        $this->assertDatabaseCount('api_tokens', 0);
        $result = $this->ceremonyPost($this->authPath($audience).'/enrollment/confirm', $setup, ['totp_code' => $this->otpCode($secret)])
            ->assertOk()->assertJsonCount(10, 'recovery_codes')->assertJsonStructure(['token', 'user']);
        $factor = UserAuthFactor::where('user_id', $user->id)->firstOrFail();
        $this->assertNotSame($secret, $factor->secret_ciphertext);
        foreach ($result->json('recovery_codes') as $code) {
            $this->assertNotNull(app(RecoveryCodeService::class)->valid($factor, $code));
            $this->assertStringNotContainsString($code, json_encode(DB::table('auth_recovery_codes')->get()));
        }
        $this->assertDatabaseCount('auth_enrollment_grants', 0);
        $this->withToken($result->json('token'))->getJson($this->authPath($audience).'/me')->assertOk();
        Mail::assertNotSent(LoginVerificationCodeMail::class);
        $sent = Mail::sent(EnrollmentIdentityProofMail::class)->count();
        $this->travel(31)->seconds();
        $login = $this->loginChallenge($user, $audience);
        $this->assertSame('login_totp', $this->storedChallenge($login)->purpose);
        $this->ceremonyPost($this->authPath($audience).'/enrollment/identity/request', $login)->assertStatus(422);
        $this->ceremonyPost($this->authPath($audience).'/login/verify', $login, ['totp_code' => $this->otpCode($secret)])->assertOk();
        $this->assertSame($sent, Mail::sent(EnrollmentIdentityProofMail::class)->count());
    }

    public function test_wrong_email_codes_commit_attempts_and_lock_even_the_right_code(): void
    {
        [$token, $proof] = $this->requestProof($this->legacy());
        $wrong = $proof === '000000' ? '111111' : '000000';
        for ($i = 1; $i <= 5; $i++) {
            $this->ceremonyPost('/api/auth/enrollment/identity/verify', $token, ['identity_proof' => $wrong])->assertStatus(422);
            $this->assertSame($i, $this->storedChallenge($token)->attempts);
        }
        $this->assertNotNull($this->storedChallenge($token)->revoked_at);
        $this->ceremonyPost('/api/auth/enrollment/identity/verify', $token, ['identity_proof' => $proof])->assertStatus(422);
        $this->assertDatabaseCount('api_tokens', 0);
        $this->assertDatabaseCount('user_auth_factors', 0);
    }

    public function test_expired_proof_cannot_advance_or_issue(): void
    {
        [$token, $proof] = $this->requestProof($this->legacy());
        $this->travel(301)->seconds();
        $this->ceremonyPost('/api/auth/enrollment/identity/verify', $token, ['identity_proof' => $proof])->assertStatus(422);
        $this->assertNull($this->storedChallenge($token)->identity_proof_hash);
        $this->assertDatabaseCount('api_tokens', 0);
    }

    public function test_proof_cannot_cross_accounts_audiences_browsers_or_purposes(): void
    {
        [$a, $proof] = $this->requestProof($this->legacy('admin'), 'admin');
        [$b] = $this->requestProof($this->legacy());
        // Even transplanting the stored verifier cannot retarget its context;
        // avoids a probabilistic test when two independent six-digit codes collide.
        $this->storedChallenge($b)->forceFill(['identity_proof_hash' => $this->storedChallenge($a)->identity_proof_hash])->save();
        $this->ceremonyPost('/api/auth/enrollment/identity/verify', $b, ['identity_proof' => $proof])->assertStatus(422);
        $this->ceremonyPost('/api/auth/enrollment/identity/verify', $a, ['identity_proof' => $proof])->assertStatus(422);
        $this->withHeader('X-Auth-Client-Binding', str_repeat('b', 64))->postJson('/api/admin/auth/enrollment/identity/verify',
            ['challenge_token' => $a, 'identity_proof' => $proof])->assertStatus(422);
        $this->ceremonyPost('/api/admin/auth/login/verify', $a, ['totp_code' => $proof])->assertStatus(422);
        $this->ceremonyPost('/api/admin/auth/recovery/verify', $a, ['recovery_code' => $proof])->assertStatus(422);
        $this->assertDatabaseCount('api_tokens', 0);
    }

    public function test_password_only_and_missing_or_changed_channels_cannot_enroll(): void
    {
        foreach (['missing', 'changed', 'recent_password', 'recent_account', 'untracked_password'] as $case) {
            $user = $case === 'missing' ? User::factory()->create() : $this->legacy();
            if ($case === 'changed') $user->forceFill(['email' => 'changed-'.$user->id.'@example.test'])->save();
            if ($case === 'recent_password') $user->forceFill(['credentials_changed_at' => now()->subHour()])->save();
            if ($case === 'recent_account') $user->forceFill(['auth_legacy_not_before' => now()->addHours(71)])->save();
            if ($case === 'untracked_password') $user->forceFill(['auth_legacy_password_fingerprint' => str_repeat('0', 64)])->save();
            $token = $this->loginChallenge($user);
            $this->ceremonyPost('/api/auth/enrollment/identity/request', $token)->assertAccepted()->assertJsonPath('support_required', true)->assertJsonPath('methods', []);
            $this->ceremonyPost('/api/auth/enrollment/setup', $token)->assertStatus(422);
            $this->ceremonyPost('/api/auth/enrollment/identity/verify', $token, ['identity_proof' => '123456'])->assertStatus(422);
        }
        Mail::assertNothingSent();
        $this->assertDatabaseCount('user_auth_factors', 0);
        $this->assertDatabaseCount('api_tokens', 0);
    }

    public function test_changed_email_invalidates_both_delivered_proof_and_verified_setup(): void
    {
        $user = $this->legacy();
        [$identity, $proof] = $this->requestProof($user);
        $setup = $this->verifyProof($identity, $proof);
        $user->forceFill(['email' => 'different@example.test'])->save();
        $this->ceremonyPost('/api/auth/enrollment/setup', $setup)->assertStatus(422);
        $this->assertDatabaseCount('user_auth_factors', 0);
        $this->assertDatabaseCount('api_tokens', 0);
    }

    public function test_restarting_credentials_does_not_reset_send_cooldown_or_budget(): void
    {
        $user = $this->legacy();
        [$old] = $this->requestProof($user);
        $new = $this->loginChallenge($user);
        $this->ceremonyPost('/api/auth/enrollment/identity/request', $new)->assertStatus(429)->assertHeader('Retry-After');
        $this->travel(121)->seconds();
        $this->ceremonyPost('/api/auth/enrollment/identity/request', $new)->assertAccepted();
        $this->assertNotNull($this->storedChallenge($old)->revoked_at);
        $this->travel(121)->seconds();
        $third = $this->loginChallenge($user);
        $this->ceremonyPost('/api/auth/enrollment/identity/request', $third)->assertAccepted();
        $this->travel(121)->seconds();
        $fourth = $this->loginChallenge($user);
        $this->ceremonyPost('/api/auth/enrollment/identity/request', $fourth)->assertStatus(429);
        Mail::assertSent(EnrollmentIdentityProofMail::class, 3);
    }

    public function test_legacy_grants_are_rejected_even_if_old_policies_are_enabled(): void
    {
        config(['totp.assisted.enabled' => true, 'totp.migration.enabled' => true]);
        $user = $this->legacy();
        $identity = $this->loginChallenge($user);
        $this->ceremonyPost('/api/auth/enrollment/identity/verify', $identity, ['grant_token' => str_repeat('d', 64)])->assertStatus(422);
        MfaFixture::factor($user);
        $recovery = $this->ceremonyPost('/api/auth/recovery/start', $this->loginChallenge($user))->assertAccepted()->json('challenge_token');
        $this->ceremonyPost('/api/auth/recovery/verify', $recovery, ['grant_token' => str_repeat('d', 64)])->assertStatus(422);
        $this->assertDatabaseCount('api_tokens', 0);
        $this->assertDatabaseCount('auth_enrollment_grants', 0);
    }

    public function test_preexisting_grant_authorized_setup_is_not_an_alternate_enrollment_authority(): void
    {
        config(['totp.assisted.enabled' => true, 'totp.assisted.policy_reference' => 'obsolete-policy']);
        $user = $this->legacy('admin')->refresh();
        $grant = new \App\Models\AuthEnrollmentGrant;
        $grant->forceFill(['user_id' => $user->id, 'token_hash' => hash('sha256', str_repeat('d', 64)),
            'audience' => 'admin', 'purpose' => 'initial_enrollment', 'auth_epoch' => $user->auth_epoch,
            'email_hash' => hash('sha256', $user->email), 'policy_reference' => 'obsolete-policy',
            'evidence_reference' => 'synthetic-old-policy', 'approver_ids' => [101, 102],
            'approved_at' => now(), 'expires_at' => now()->addMinutes(10)])->save();
        $identity = $this->loginChallenge($user, 'admin');
        $this->ceremonyPost('/api/admin/auth/enrollment/identity/verify', $identity,
            ['grant_token' => str_repeat('d', 64)])->assertStatus(422);
        $setup = DB::transaction(fn () => app(\App\Services\Auth\AuthChallengeService::class)
            ->create($user, null, 'admin', 'enrollment_setup', hash('sha256', $this->binding), now(), now()->addMinutes(10),
                ['trusted_identity_method' => 'operator_grant', 'trusted_identity_verified_at' => now(), 'grant_id' => $grant->id]));
        $this->ceremonyPost('/api/admin/auth/enrollment/setup', $setup['challenge_token'])->assertStatus(422);
        $this->assertNull($grant->fresh()->consumed_at);
        $this->assertDatabaseCount('user_auth_factors', 0);
        $this->assertDatabaseCount('api_tokens', 0);
    }

    public function test_actual_cutover_snapshot_does_not_backfill_verification_or_follow_address_changes(): void
    {
        // Exercise the additive migration against a synthetic pre-cutover row.
        \Illuminate\Support\Facades\Schema::table('auth_challenges', function ($table): void {
            $table->dropColumn('identity_email_hash');
        });
        \Illuminate\Support\Facades\Schema::table('users', function ($table): void {
            $table->dropColumn(['auth_legacy_email_hash', 'auth_legacy_password_fingerprint', 'auth_legacy_not_before']);
        });
        $user = User::factory()->create(['updated_at' => now()->subDays(4)]);
        $migration = require database_path('migrations/2026_11_26_100000_bind_enrollment_email_identity.php');
        $migration->up();
        $user->refresh();
        $this->assertNull($user->email_verified_at);
        $this->assertSame(hash('sha256', $user->email), $user->auth_legacy_email_hash);
        $this->assertSame(hash('sha256', $user->password_hash), $user->auth_legacy_password_fingerprint);
        $this->assertTrue(app(\App\Services\Auth\EnrollmentTrustPolicy::class)->establishedEmail($user));
        $user->forceFill(['email' => 'different-snapshot@example.test'])->save();
        $this->assertFalse(app(\App\Services\Auth\EnrollmentTrustPolicy::class)->establishedEmail($user->fresh()));
        $this->assertArrayNotHasKey('auth_legacy_email_hash', $user->toArray());
        $this->assertArrayNotHasKey('auth_legacy_password_fingerprint', $user->toArray());
    }

    public function test_registration_email_verification_directly_returns_restricted_setup_without_another_email(): void
    {
        $start = $this->postJson('/api/auth/register', ['email' => 'new@example.test', 'password' => 'password',
            'password_confirmation' => 'password', 'client_binding' => $this->binding])->assertAccepted();
        $mail = Mail::sent(RegistrationVerificationCodeMail::class)->sole();
        $body = ['email' => 'new@example.test', 'verification_code' => $mail->verificationCode,
            'registration_version' => $start->json('registration_version'), 'client_binding' => $this->binding];
        $result = $this->postJson('/api/auth/register/verify', $body)->assertCreated()
            ->assertJsonPath('next_action', 'enrollment_ready')->assertJsonMissingPath('token');
        $this->assertDatabaseCount('api_tokens', 0);
        Mail::assertNotSent(EnrollmentIdentityProofMail::class);
        $this->ceremonyPost('/api/auth/enrollment/setup', $result->json('challenge_token'))->assertOk()->assertJsonStructure(['manual_key', 'qr_svg']);
        $this->postJson('/api/auth/register/verify', $body)->assertStatus(422);
    }

    public function test_registration_wrong_browser_and_bad_codes_cannot_reset_attempts(): void
    {
        $start = $this->postJson('/api/auth/register', ['email' => 'bound@example.test', 'password' => 'password',
            'password_confirmation' => 'password', 'client_binding' => $this->binding])->assertAccepted();
        $proof = Mail::sent(RegistrationVerificationCodeMail::class)->sole()->verificationCode;
        $body = ['email' => 'bound@example.test', 'verification_code' => $proof,
            'registration_version' => $start->json('registration_version'), 'client_binding' => str_repeat('b', 64)];
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/auth/register/verify', $body)->assertStatus(422);
            $this->assertSame($i, (int) DB::table('pending_registrations')->value('verification_attempts'));
        }
        $this->postJson('/api/auth/register/verify', [...$body, 'client_binding' => $this->binding])->assertStatus(422);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('api_tokens', 0);
    }

    public function test_recovery_consumes_once_and_requires_new_totp_before_login(): void
    {
        $user = $this->legacy();
        $factor = MfaFixture::factor($user);
        $codes = DB::transaction(fn () => app(RecoveryCodeService::class)->rotate($factor));
        $old = MfaFixture::token(['user_id' => $user->id, 'token_hash' => hash('sha256', 'old')]);
        $start = $this->ceremonyPost('/api/auth/recovery/start', $this->loginChallenge($user))->assertAccepted()->json('challenge_token');
        $setup = $this->ceremonyPost('/api/auth/recovery/verify', $start, ['recovery_code' => $codes[0]])->assertAccepted()->assertJsonMissingPath('token')->json('challenge_token');
        $this->assertDatabaseCount('api_tokens', 0);
        $this->assertNull(app(RecoveryCodeService::class)->valid($factor, $codes[0]));
        $this->withToken('old')->getJson('/api/auth/me')->assertUnauthorized();
        $this->ceremonyPost('/api/auth/recovery/verify', $start, ['recovery_code' => $codes[0]])->assertStatus(422);
        $secret = $this->ceremonyPost('/api/auth/totp/replacement/setup', $setup)->assertOk()->json('manual_key');
        $this->ceremonyPost('/api/auth/totp/replacement/confirm', $setup, ['totp_code' => $this->otpCode($secret)])
            ->assertOk()->assertJsonCount(10, 'recovery_codes')->assertJsonStructure(['token']);
        $this->assertSame(2, $factor->fresh()->version);
        foreach ($codes as $code) $this->assertNull(app(RecoveryCodeService::class)->valid($factor->fresh(), $code));
        $this->assertSame(10, AuthRecoveryCode::whereNull('consumed_at')->count());
    }

    public function test_reset_preserves_factor_and_does_not_establish_enrollment_trust(): void
    {
        $user = $this->legacy();
        $factor = MfaFixture::factor($user);
        $ciphertext = $factor->secret_ciphertext;
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => Hash::make('123456'), 'created_at' => now()]);
        $this->postJson('/api/auth/reset-password', ['email' => $user->email, 'verification_code' => '123456',
            'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertOk()->assertJsonMissingPath('token');
        $this->assertSame($ciphertext, $factor->fresh()->secret_ciphertext);
        $this->assertSame(1, $factor->fresh()->version);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertDatabaseCount('api_tokens', 0);
        $unEnrolled = $this->legacy();
        $unEnrolled->forceFill(['credentials_changed_at' => now()])->save();
        $token = $this->loginChallenge($unEnrolled);
        $this->ceremonyPost('/api/auth/enrollment/identity/request', $token)->assertAccepted()->assertJsonPath('support_required', true);
    }
}
