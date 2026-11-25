<?php

namespace App\Services\Auth;

use App\Models\AuthChallenge;
use App\Models\User;
use App\Models\UserAuthFactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthChallengeService
{
    private static ?string $dummyHash = null;

    public function __construct(private readonly AuthSecurity $security) {}

    public function credentials(string $email, #[\SensitiveParameter] string $password, string $audience, #[\SensitiveParameter] string $binding): array
    {
        $this->security->limit('credentials', $email);
        // Same configured hashing cost for a synthetic unknown-account check;
        // initialize on both known and unknown paths, not only on a miss.
        $dummyHash = self::$dummyHash ??= Hash::make('origin-wallet-non-credential');
        $result = DB::transaction(function () use ($email, $password, $audience, $binding, $dummyHash): array {
            $user = User::where('email', trim($email))->lockForUpdate()->first();
            $hash = $user?->password_hash ?? $dummyHash;
            if (! Hash::check($password, $hash) || $user === null
                || ! in_array($audience, ['admin', 'user'], true)
                || ($audience === 'admin' && ! $user->isAdmin())) {
                $this->security->event('authentication_credentials_failed', $user, ['audience' => $audience]);

                return ['_auth_error' => true];
            }
            $factor = $this->lockFactor($user);
            // Each password ceremony is distinct; shared abuse counters are not reset.
            return $this->create($user, $factor, $audience, $factor === null ? 'enrollment_identity' : 'login_totp',
                hash('sha256', $binding), now(), now()->addSeconds(config('totp.ceremony_seconds', 1200)));
        });

        return $this->finish($result);
    }

    /** Internal entry; all callers hold the account lock (including new users). */
    public function create(User $user, ?UserAuthFactor $factor, string $audience, string $purpose,
        string $bindingHash, $credentialsAt, $deadline, array $extra = []): array
    {
        if (DB::transactionLevel() < 1 || ! in_array($audience, ['user', 'admin'], true)) {
            throw new \LogicException('Challenge requires an account transaction and audience.');
        }
        // A narrower authorization deadline may shorten, never extend, the
        // original password ceremony. Clamp the individual expiry as well.
        $deadline = $deadline->min($extra['ceremony_deadline'] ?? $deadline);
        unset($extra['ceremony_deadline']);
        $token = bin2hex(random_bytes(32));
        $setup = in_array($purpose, ['enrollment_setup', 'replacement_setup'], true);
        $expiry = now()->addSeconds(config($setup ? 'totp.enrollment_seconds' : 'totp.challenge_seconds', $setup ? 600 : 300));
        $expiry = $expiry->min($deadline);
        $challenge = new AuthChallenge;
        $challenge->forceFill([
            'user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'audience' => $audience,
            'purpose' => $purpose, 'client_binding_hash' => $bindingHash, 'auth_epoch' => $user->auth_epoch,
            'factor_id' => $factor?->id, 'factor_version' => $factor?->version,
            'credential_verified_at' => $credentialsAt, 'expires_at' => $expiry,
            'ceremony_deadline' => $deadline, 'max_attempts' => config('totp.max_attempts', 5),
            ...$extra,
        ])->save();
        $this->security->event('authentication_challenge_created', $user,
            ['challenge_id' => $challenge->id, 'purpose' => $purpose, 'audience' => $audience]);

        return $this->payload($challenge, $token);
    }

    public function transition(User $user, ?UserAuthFactor $factor, AuthChallenge $old, string $purpose, array $extra = []): array
    {
        $this->consume($old);

        return $this->create($user, $factor, $old->audience, $purpose, $old->client_binding_hash,
            $old->credential_verified_at, $old->ceremony_deadline, $extra);
    }

    public function payload(AuthChallenge $challenge, #[\SensitiveParameter] string $token): array
    {
        $action = match ($challenge->purpose) {
            'login_totp' => 'totp_required', 'login_recovery' => 'recovery_required',
            'enrollment_identity' => 'enrollment_identity_required', 'enrollment_setup' => 'enrollment_ready',
            'replacement_setup' => 'replacement_required', 'step_up' => 'step_up_required',
            'step_up_grant' => 'step_up_complete', default => throw new \LogicException('Invalid challenge purpose.'),
        };

        return ['next_action' => $action, 'challenge_token' => $token,
            'expires_in_seconds' => max(0, $challenge->expires_at->getTimestamp() - now()->getTimestamp()),
            'methods' => $challenge->purpose === 'login_totp' ? ['totp', 'recovery_code'] : []];
    }

    /** User → factor → challenge; failures commit before finish() aborts. */
    public function run(#[\SensitiveParameter] string $token, string $audience, #[\SensitiveParameter] string $binding,
        array $purposes, callable $operation): array
    {
        $snapshot = AuthChallenge::where('token_hash', hash('sha256', $token))->first();
        $account = $snapshot ? (string) $snapshot->user_id : 'unknown';
        $this->security->limit('verification', $account, $token);
        $result = DB::transaction(function () use ($snapshot, $token, $audience, $binding, $purposes, $operation): array {
            $user = $snapshot ? User::whereKey($snapshot->user_id)->lockForUpdate()->first() : null;
            $factor = $user ? $this->lockFactor($user) : null;
            $challenge = $user ? AuthChallenge::whereKey($snapshot->id)->lockForUpdate()->first() : null;
            if ($user === null || $challenge === null) {
                $this->security->event('authentication_challenge_failed', null, ['reason' => 'missing']);

                return ['_auth_error' => true];
            }
            $valid = hash_equals($challenge->token_hash, hash('sha256', $token))
                && hash_equals($challenge->client_binding_hash, hash('sha256', $binding))
                && $challenge->audience === $audience && in_array($audience, ['admin', 'user'], true)
                && in_array($challenge->purpose, $purposes, true)
                && $challenge->consumed_at === null && $challenge->revoked_at === null
                && $challenge->expires_at->gt(now()) && $challenge->ceremony_deadline->gt(now())
                && $challenge->credential_verified_at->lte(now())
                && $challenge->auth_epoch === $user->auth_epoch
                && $challenge->attempts < $challenge->max_attempts
                && (int) ($challenge->factor_id ?? 0) === (int) ($factor?->id ?? 0)
                && $challenge->factor_version === $factor?->version
                && ($audience !== 'admin' || $user->isAdmin());
            if (! $valid) {
                return $this->reject($user, $challenge, $challenge->expires_at->lte(now()) ? 'expired' : 'invalid_challenge');
            }

            return $operation($user, $factor, $challenge);
        });

        return $this->finish($result);
    }

    public function reject(User $user, AuthChallenge $challenge, string $reason): array
    {
        if ($challenge->consumed_at === null && $challenge->revoked_at === null) {
            $challenge->attempts++;
            $challenge->last_attempt_at = now();
            if ($challenge->attempts >= $challenge->max_attempts || $challenge->expires_at->lte(now())
                || $challenge->ceremony_deadline->lte(now())) {
                $challenge->revoked_at = now();
                $challenge->pending_secret_ciphertext = null;
                $challenge->identity_proof_hash = null;
            }
            $challenge->save();
        }
        $this->security->event('authentication_challenge_failed', $user, ['challenge_id' => $challenge->id,
            'purpose' => $challenge->purpose, 'audience' => $challenge->audience, 'reason' => $reason,
            'attempts' => $challenge->attempts]);

        return ['_auth_error' => true];
    }

    public function consume(AuthChallenge $challenge, array $evidence = []): void
    {
        $challenge->forceFill(['consumed_at' => now(), 'pending_secret_ciphertext' => null,
            'identity_proof_hash' => null, ...$evidence])->save();
        $this->security->event('authentication_challenge_consumed', null,
            ['challenge_id' => $challenge->id, 'purpose' => $challenge->purpose, 'audience' => $challenge->audience]);
    }

    public function lockFactor(User $user): ?UserAuthFactor
    {
        return UserAuthFactor::where('user_id', $user->id)->where('type', 'totp')->lockForUpdate()->first();
    }

    private function finish(array $result): array
    {
        if (isset($result['_auth_error'])) {
            abort(422, 'Authentication could not be completed.');
        }

        return $result;
    }
}
