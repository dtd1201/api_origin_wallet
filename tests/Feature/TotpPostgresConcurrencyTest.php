<?php

namespace Tests\Feature;

use App\Models\AuthChallenge;
use App\Models\User;
use App\Models\UserAuthFactor;
use App\Services\Auth\ApiAuthService;
use App\Services\Auth\AuthRevocationService;
use App\Services\Auth\RecoveryCodeService;
use App\Services\Auth\TotpEnrollmentService;
use App\Services\Auth\TotpRecoveryService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\MfaFixture;
use Tests\Support\OwnerFirstPostgresDatabase;
use Tests\Support\PostgresLockRace;
use Tests\Support\TotpTestTools;
use Tests\Support\IsolatedAuthTestCase as TestCase;

/** Real committed PostgreSQL roots; existing approved reset safeguards unchanged. */
class TotpPostgresConcurrencyTest extends TestCase
{
    use OwnerFirstPostgresDatabase, TotpTestTools;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfMinute());
        Mail::fake();
        Http::preventStrayRequests();
        config(['totp.rates.credentials' => [100, 100], 'totp.rates.verification' => [100, 100], 'totp.rates.identity_send' => [100, 100]]);
    }

    private function race(callable $left, callable $right): array
    {
        return (new PostgresLockRace)->run(
            function (PostgresLockRace $race) use ($left): string {
                $race->pauseAfterLock('users');
                return $left();
            },
            function (PostgresLockRace $race) use ($right): string {
                $race->await('locked', 0);
                return $right();
            },
            function (PostgresLockRace $race, Connection $observer, array $pids): void {
                $race->await('locked', 0);
                $wait = $race->waitForBlock($observer, $pids[1], $pids[0]);
                $this->assertStringContainsString('users', $wait['query']);
                $race->signal('continue');
            });
    }

    private function outcome(callable $action): string
    {
        try {
            $action();
            return 'accepted'; // Never serialize response secrets to race artifacts.
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 422) {
                throw $exception;
            }
            return 'denied';
        }
    }

    public function test_two_challenges_across_portals_can_consume_a_counter_only_once(): void
    {
        $user = User::factory()->create();
        $user->roles()->create(['role_code' => 'admin']);
        MfaFixture::factor($user);
        $a = $this->loginChallenge($user, 'user');
        $b = $this->loginChallenge($user, 'admin');
        $code = $this->otpCode();
        $race = $this->race(
            fn () => $this->outcome(fn () => app(ApiAuthService::class)->completeLogin($a, $code, 'user', $this->binding)),
            fn () => $this->outcome(fn () => app(ApiAuthService::class)->completeLogin($b, $code, 'admin', $this->binding)));
        $this->assertSame(['accepted', 'denied'], array_column($race['workers'], 'value'));
        $this->assertDatabaseCount('api_tokens', 1);
        $this->assertSame(intdiv(now()->getTimestamp(), 30), UserAuthFactor::where('user_id', $user->id)->first()->last_accepted_time_step);
    }

    public function test_same_challenge_cannot_issue_two_tokens(): void
    {
        $user = User::factory()->create();
        MfaFixture::factor($user);
        $token = $this->loginChallenge($user);
        $code = $this->otpCode();
        $action = fn () => $this->outcome(fn () => app(ApiAuthService::class)->completeLogin($token, $code, 'user', $this->binding));
        $race = $this->race($action, $action);
        $this->assertSame(['accepted', 'denied'], array_column($race['workers'], 'value'));
        $this->assertDatabaseCount('api_tokens', 1);
    }

    public function test_two_first_enrollments_cannot_activate_multiple_factors(): void
    {
        $user = User::factory()->create();
        $this->newCustomerEvidence($user);
        $a = $this->ceremonyPost('/api/auth/enrollment/identity/request', $this->loginChallenge($user))->assertAccepted()->json('challenge_token');
        $b = $this->ceremonyPost('/api/auth/enrollment/identity/request', $this->loginChallenge($user))->assertAccepted()->json('challenge_token');
        $secretA = $this->ceremonyPost('/api/auth/enrollment/setup', $a)->assertOk()->json('manual_key');
        $secretB = $this->ceremonyPost('/api/auth/enrollment/setup', $b)->assertOk()->json('manual_key');
        $race = $this->race(
            fn () => $this->outcome(fn () => app(TotpEnrollmentService::class)->confirm($a, 'user', $this->binding, $this->otpCode($secretA))),
            fn () => $this->outcome(fn () => app(TotpEnrollmentService::class)->confirm($b, 'user', $this->binding, $this->otpCode($secretB))));
        $this->assertSame(['accepted', 'denied'], array_column($race['workers'], 'value'));
        $this->assertDatabaseCount('user_auth_factors', 1);
        $this->assertDatabaseCount('api_tokens', 1);
        $this->assertDatabaseCount('auth_recovery_codes', 10);
    }

    public function test_same_email_proof_can_advance_to_setup_only_once(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['auth_legacy_email_hash' => hash('sha256', $user->email),
            'auth_legacy_password_fingerprint' => hash('sha256', $user->password_hash),
            'auth_legacy_not_before' => now()->subDay()])->save();
        $identity = $this->loginChallenge($user);
        app(TotpEnrollmentService::class)->identityRequest($identity, 'user', $this->binding);
        $proof = Mail::sent(\App\Mail\EnrollmentIdentityProofMail::class)->sole()->proof;
        $action = fn () => $this->outcome(fn () => app(TotpEnrollmentService::class)
            ->identityVerify($identity, 'user', $this->binding, $proof, null));
        $race = $this->race($action, $action);
        $this->assertSame(['accepted', 'denied'], array_column($race['workers'], 'value'));
        $this->assertSame(1, AuthChallenge::where('purpose', 'enrollment_setup')->count());
        $this->assertDatabaseCount('user_auth_factors', 0);
        $this->assertDatabaseCount('api_tokens', 0);
    }

    public function test_competing_recovery_ceremonies_cannot_reuse_a_backup(): void
    {
        $user = User::factory()->create();
        $factor = MfaFixture::factor($user);
        $codes = DB::transaction(fn () => app(RecoveryCodeService::class)->rotate($factor));
        $a = $this->ceremonyPost('/api/auth/recovery/start', $this->loginChallenge($user))->json('challenge_token');
        $b = $this->ceremonyPost('/api/auth/recovery/start', $this->loginChallenge($user))->json('challenge_token');
        $race = $this->race(
            fn () => $this->outcome(fn () => app(TotpRecoveryService::class)->verify($a, 'user', $this->binding, $codes[0], null)),
            fn () => $this->outcome(fn () => app(TotpRecoveryService::class)->verify($b, 'user', $this->binding, $codes[0], null)));
        $this->assertSame(['accepted', 'denied'], array_column($race['workers'], 'value'));
        $this->assertDatabaseCount('api_tokens', 0);
        $this->assertSame(1, \App\Models\AuthRecoveryCode::whereNotNull('consumed_at')->count());
        $this->assertSame(1, AuthChallenge::where('purpose', 'replacement_setup')->whereNull('revoked_at')->count());
    }

    public function test_password_epoch_change_wins_before_waiting_login_can_issue(): void
    {
        $user = User::factory()->create();
        MfaFixture::factor($user);
        $token = $this->loginChallenge($user);
        $code = $this->otpCode();
        $race = $this->race(
            function () use ($user): string {
                DB::transaction(function () use ($user): void {
                    $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                    app(AuthRevocationService::class)->lockFactor($locked);
                    $locked->forceFill(['password_hash' => 'synthetic-new-password', 'credentials_changed_at' => now()])->save();
                    app(AuthRevocationService::class)->revokeLocked($locked, 'password_reset');
                });
                return 'accepted';
            },
            fn () => $this->outcome(fn () => app(ApiAuthService::class)->completeLogin($token, $code, 'user', $this->binding)));
        $this->assertSame(['accepted', 'denied'], array_column($race['workers'], 'value'));
        $this->assertDatabaseCount('api_tokens', 0);
        $this->assertSame(1, $user->fresh()->auth_epoch);
    }

    public function test_replacement_commit_invalidates_waiting_old_factor_login(): void
    {
        $user = User::factory()->create();
        MfaFixture::factor($user);
        $initiator = MfaFixture::token(['user_id' => $user->id, 'token_hash' => hash('sha256', 'replace-session'), 'expires_at' => now()->addHour()]);
        $login = $this->loginChallenge($user);
        $service = app(TotpRecoveryService::class);
        $step = $service->stepUp($initiator, 'password', $this->binding, 'replace_authenticator')['challenge_token'];
        $setup = $service->stepUpVerify($initiator, $step, $this->binding, $this->otpCode())['challenge_token'];
        $secret = app(TotpEnrollmentService::class)->setup($setup, 'user', $this->binding, true)['manual_key'];
        $newCode = $this->otpCode($secret);
        $oldCode = $this->otpCode(MfaFixture::SECRET, 1);
        $race = $this->race(
            fn () => $this->outcome(fn () => app(TotpEnrollmentService::class)->confirm($setup, 'user', $this->binding, $newCode, true)),
            fn () => $this->outcome(fn () => app(ApiAuthService::class)->completeLogin($login, $oldCode, 'user', $this->binding)));
        $this->assertSame(['accepted', 'denied'], array_column($race['workers'], 'value'));
        $this->assertSame(2, UserAuthFactor::where('user_id', $user->id)->first()->version);
        $this->assertDatabaseCount('api_tokens', 1);
    }

    public function test_recovery_regeneration_invalidates_waiting_old_backup_code(): void
    {
        $user = User::factory()->create();
        $factor = MfaFixture::factor($user);
        $codes = DB::transaction(fn () => app(RecoveryCodeService::class)->rotate($factor));
        $initiator = MfaFixture::token(['user_id' => $user->id, 'token_hash' => hash('sha256', 'regenerate-session'), 'expires_at' => now()->addHour()]);
        $service = app(TotpRecoveryService::class);
        $recovery = $service->start($this->loginChallenge($user), 'user', $this->binding)['challenge_token'];
        $step = $service->stepUp($initiator, 'password', $this->binding, 'regenerate_recovery_codes')['challenge_token'];
        $grant = $service->stepUpVerify($initiator, $step, $this->binding, $this->otpCode())['challenge_token'];
        $race = $this->race(
            fn () => $this->outcome(fn () => app(TotpRecoveryService::class)->regenerate($initiator, $grant, $this->binding)),
            fn () => $this->outcome(fn () => app(TotpRecoveryService::class)->verify($recovery, 'user', $this->binding, $codes[0], null)));
        $this->assertSame(['accepted', 'denied'], array_column($race['workers'], 'value'));
        $this->assertSame(2, $factor->fresh()->recovery_set_version);
        $this->assertSame(10, \App\Models\AuthRecoveryCode::where('set_version', 2)->whereNull('consumed_at')->count());
        $this->assertDatabaseCount('api_tokens', 1);
    }

    public function test_same_regeneration_grant_cannot_return_two_backup_sets(): void
    {
        $user = User::factory()->create();
        MfaFixture::factor($user);
        $initiator = MfaFixture::token(['user_id' => $user->id, 'token_hash' => hash('sha256', 'regenerate-once'), 'expires_at' => now()->addHour()]);
        $service = app(TotpRecoveryService::class);
        $step = $service->stepUp($initiator, 'password', $this->binding, 'regenerate_recovery_codes')['challenge_token'];
        $grant = $service->stepUpVerify($initiator, $step, $this->binding, $this->otpCode())['challenge_token'];
        $action = fn () => $this->outcome(fn () => app(TotpRecoveryService::class)->regenerate($initiator, $grant, $this->binding));
        $race = $this->race($action, $action);
        $this->assertSame(['accepted', 'denied'], array_column($race['workers'], 'value'));
        $this->assertSame(2, UserAuthFactor::where('user_id', $user->id)->first()->recovery_set_version);
        $this->assertDatabaseCount('auth_recovery_codes', 10);
    }
}
