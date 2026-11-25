<?php

namespace App\Services\Auth;

use App\Mail\EnrollmentIdentityProofMail;
use App\Models\AuthChallenge;
use App\Models\AuthEnrollmentGrant;
use App\Models\AuthRegistrationEvidence;
use App\Models\User;
use App\Models\UserAuthFactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Throwable;

class TotpEnrollmentService
{
    public function __construct(private readonly AuthChallengeService $challenges, private readonly TotpService $totp,
        private readonly EnrollmentTrustPolicy $trust, private readonly EnrollmentGrantService $grants,
        private readonly AuthRevocationService $revocation, private readonly RecoveryCodeService $codes,
        private readonly AuthSecurity $security, private readonly ApiAuthService $auth) {}

    public function identityRequest(string $token, string $audience, string $binding): array
    {
        $snapshot = AuthChallenge::where('token_hash', hash('sha256', $token))->first();
        $this->security->limit('identity_send', (string) ($snapshot?->user_id ?? 'unknown'));
        return $this->challenges->run($token, $audience, $binding, ['enrollment_identity'], function (User $user, $factor, AuthChallenge $challenge): array {
            if ($factor !== null) {
                return $this->challenges->reject($user, $challenge, 'already_enrolled');
            }
            if ($challenge->audience === 'user') {
                $evidence = $this->trust->newRegistration($user);
                if ($evidence !== null && $evidence->client_binding_hash !== null
                    && hash_equals($evidence->client_binding_hash, $challenge->client_binding_hash)) {
                    return $this->challenges->transition($user, null, $challenge, 'enrollment_setup', [
                        'trusted_identity_method' => 'new_registration', 'trusted_identity_verified_at' => now(),
                        'registration_evidence_id' => $evidence->id,
                    ]);
                }
                if ($evidence !== null) {
                    return ['next_action' => 'enrollment_identity_required', 'methods' => ['registration_email_proof']];
                }
            }
            $migration = $challenge->audience === 'user' ? $this->trust->migration($user) : null;
            if ($migration === null) {
                return ['next_action' => 'enrollment_identity_required',
                    'methods' => config('totp.assisted.enabled') ? ['operator_grant'] : [],
                    'policy_pending' => ! config('totp.assisted.enabled')];
            }
            $migration = AuthEnrollmentGrant::whereKey($migration->id)->lockForUpdate()->firstOrFail();
            if ($migration->proof_send_count >= config('totp.proof_send_limit', 3)
                || ($migration->proof_sent_at !== null && $migration->proof_sent_at->addSeconds(config('totp.proof_send_cooldown_seconds', 120))->gt(now()))) {
                return $this->challenges->reject($user, $challenge, 'proof_send_limit');
            }
            // Never send a proof to a log/array/failover transport in runtime.
            // Mail::fake() in isolated tests is explicitly permitted.
            if (! Mail::isFake() && ! in_array(config('mail.mailers.'.config('mail.default').'.transport'), ['smtp', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun'], true)) {
                abort(503, 'Enrollment identity delivery is unavailable.');
            }
            $proof = bin2hex(random_bytes(16));
            $challenge->forceFill(['grant_id' => $migration->id, 'identity_proof_hash' => Hash::make($proof),
                'proof_sent_at' => now(), 'proof_send_count' => $challenge->proof_send_count + 1])->save();
            $migration->forceFill(['proof_sent_at' => now(), 'proof_send_count' => $migration->proof_send_count + 1])->save();
            $this->security->event('enrollment_identity_proof_requested', $user, ['challenge_id' => $challenge->id]);
            DB::afterCommit(function () use ($user, $proof): void {
                try {
                    Mail::to($user->email)->send(new EnrollmentIdentityProofMail($proof));
                } catch (Throwable) {
                    $this->security->event('enrollment_identity_delivery_failed', $user);
                }
            });

            return ['next_action' => 'enrollment_identity_required', 'methods' => ['migration_email_proof'],
                'proof_expires_in_seconds' => min(600, $challenge->expires_at->getTimestamp() - now()->getTimestamp())];
        });
    }

    public function identityVerify(string $token, string $audience, string $binding, ?string $proof, ?string $grantToken): array
    {
        return $this->challenges->run($token, $audience, $binding, ['enrollment_identity'], function (User $user, $factor, AuthChallenge $challenge) use ($proof, $grantToken): array {
            if ($factor !== null) {
                return $this->challenges->reject($user, $challenge, 'already_enrolled');
            }
            $evidence = $challenge->audience === 'user' ? $this->trust->newRegistration($user) : null;
            if ($grantToken === null && $evidence !== null && $proof !== null) {
                $evidence = AuthRegistrationEvidence::whereKey($evidence->id)->lockForUpdate()->firstOrFail();
                if ($evidence->verification_attempts < 5 && Hash::check($proof, $evidence->registration_proof_hash)) {
                    return $this->challenges->transition($user, null, $challenge, 'enrollment_setup', [
                        'trusted_identity_method' => 'new_registration', 'trusted_identity_verified_at' => now(),
                        'registration_evidence_id' => $evidence->id,
                    ]);
                }
                $evidence->forceFill(['verification_attempts' => $evidence->verification_attempts + 1])->save();

                return $this->challenges->reject($user, $challenge, 'registration_proof_invalid');
            }
            if ($grantToken !== null) {
                $grant = $this->grants->find($grantToken);
                if ($grant !== null && $this->trust->validGrant($grant, $user, $challenge->audience, 'initial_enrollment')) {
                    return $this->challenges->transition($user, null, $challenge, 'enrollment_setup', [
                        'trusted_identity_method' => 'operator_grant', 'trusted_identity_verified_at' => now(), 'grant_id' => $grant->id,
                    ]);
                }
            } elseif ($challenge->identity_proof_hash !== null && $challenge->proof_sent_at?->addMinutes(10)->gt(now())
                && $proof !== null && Hash::check($proof, $challenge->identity_proof_hash)) {
                $grant = AuthEnrollmentGrant::whereKey($challenge->grant_id)->lockForUpdate()->first();
                if ($grant !== null && $this->trust->validGrant($grant, $user, $challenge->audience, 'migration')) {
                    return $this->challenges->transition($user, null, $challenge, 'enrollment_setup', [
                        'trusted_identity_method' => 'migration_email', 'trusted_identity_verified_at' => now(), 'grant_id' => $grant->id,
                    ]);
                }
            }

            return $this->challenges->reject($user, $challenge, 'identity_proof_invalid');
        });
    }

    public function setup(string $token, string $audience, string $binding, bool $replacement = false): array
    {
        return $this->challenges->run($token, $audience, $binding, [$replacement ? 'replacement_setup' : 'enrollment_setup'],
            function (User $user, $factor, AuthChallenge $challenge) use ($token): array {
                if (! $this->trust->trusted($user, $challenge)) {
                    return $this->challenges->reject($user, $challenge, 'identity_proof_required');
                }
                if ($challenge->pending_secret_ciphertext === null) {
                    $challenge->forceFill(['pending_secret_ciphertext' => $this->totp->encrypt($this->totp->newSecret()),
                        'encryption_key_version' => 'app-v1'])->save();
                }
                $this->security->event('authenticator_setup_accessed', $user, ['challenge_id' => $challenge->id]);

                return [...$this->challenges->payload($challenge, $token),
                    ...$this->totp->provisioning($this->totp->decrypt($challenge->pending_secret_ciphertext, $challenge->encryption_key_version), $user->email)];
            });
    }

    public function confirm(string $token, string $audience, string $binding, string $code, bool $replacement = false): array
    {
        return $this->challenges->run($token, $audience, $binding, [$replacement ? 'replacement_setup' : 'enrollment_setup'],
            function (User $user, ?UserAuthFactor $factor, AuthChallenge $challenge) use ($code, $replacement): array {
                if (($replacement ? $factor === null : $factor !== null) || ! $this->trust->trusted($user, $challenge)
                    || $challenge->pending_secret_ciphertext === null) {
                    return $this->challenges->reject($user, $challenge, 'setup_unavailable');
                }
                $ciphertext = $challenge->pending_secret_ciphertext;
                $counter = $this->totp->matchingCounter($this->totp->decrypt($ciphertext, $challenge->encryption_key_version), $code);
                if ($counter === null) {
                    return $this->challenges->reject($user, $challenge, 'totp_invalid');
                }
                if ($challenge->grant_id !== null) {
                    AuthEnrollmentGrant::whereKey($challenge->grant_id)->update(['consumed_at' => now()]);
                }
                if ($challenge->registration_evidence_id !== null) {
                    $consumed = AuthRegistrationEvidence::whereKey($challenge->registration_evidence_id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
                    if ($consumed !== 1) {
                        return $this->challenges->reject($user, $challenge, 'registration_evidence_used');
                    }
                }
                $this->revocation->revokeLocked($user, $replacement ? 'factor_replaced' : 'factor_enrolled', $challenge);
                $factor ??= new UserAuthFactor;
                $factor->forceFill(['user_id' => $user->id, 'type' => 'totp', 'secret_ciphertext' => $ciphertext,
                    'encryption_key_version' => 'app-v1', 'confirmed_at' => now(), 'last_accepted_time_step' => $counter,
                    'version' => $factor->exists ? $factor->version + 1 : 1,
                    'recovery_set_version' => $factor->exists ? $factor->recovery_set_version + 1 : 1])->save();
                $this->challenges->consume($challenge, ['factor_id' => $factor->id, 'factor_version' => $factor->version,
                    'verified_counter' => $counter, 'mfa_verified_at' => now()]);
                $codes = $this->codes->rotate($factor);
                $plain = $this->security->tokenIssuance(fn () => $this->auth->issueToken($user, $challenge, 'portal'));
                $event = $replacement ? 'authenticator_replaced' : 'authenticator_enrolled';
                $this->security->event($event, $user, ['factor_version' => $factor->version]);
                $this->security->notice($user, $event);

                return [...$this->auth->buildAuthPayload($user, $plain), 'recovery_codes' => $codes];
            });
    }

    public function cancel(string $token, string $audience, string $binding): array
    {
        return $this->challenges->run($token, $audience, $binding,
            ['login_totp', 'login_recovery', 'enrollment_identity', 'enrollment_setup', 'replacement_setup', 'step_up', 'step_up_grant'],
            function (User $user, $factor, AuthChallenge $challenge): array {
                $challenge->forceFill(['revoked_at' => now(), 'pending_secret_ciphertext' => null, 'identity_proof_hash' => null])->save();
                $this->security->event('authentication_challenge_cancelled', $user, ['challenge_id' => $challenge->id]);

                return ['message' => 'Authentication challenge cancelled.'];
            });
    }
}
