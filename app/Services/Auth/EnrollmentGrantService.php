<?php

namespace App\Services\Auth;

use App\Models\ApiToken;
use App\Models\AuthEnrollmentGrant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** No public issue/approve endpoint. Only the separately approved operator workflow may call issue(). */
class EnrollmentGrantService
{
    public function __construct(private readonly AuthSecurity $security, private readonly AuthTokenEligibility $eligibility) {}

    public function issue(int $userId, string $audience, string $purpose, CarbonImmutable $expiresAt,
        string $evidenceReference, array $approverTokenIds): string
    {
        $policy = $purpose === 'migration' ? 'migration' : 'assisted';
        abort_unless(config('totp.'.$policy.'.enabled') && filled(config('totp.'.$policy.'.policy_reference')), 403, 'Enrollment policy is inactive.');
        abort_unless(in_array($purpose, ['migration', 'initial_enrollment', 'replacement'], true)
            && in_array($audience, ['admin', 'user'], true) && $expiresAt->gt(now())
            && strlen($evidenceReference) >= 8 && strlen($evidenceReference) <= 255, 422, 'Invalid enrollment authorization.');

        return DB::transaction(function () use ($userId, $audience, $purpose, $expiresAt, $evidenceReference, $approverTokenIds, $policy): string {
            $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $factor = app(AuthChallengeService::class)->lockFactor($user);
            abort_if(($purpose !== 'replacement' && $factor !== null) || ($purpose === 'replacement' && $factor === null), 422);
            abort_if($purpose === 'migration' && ($audience !== 'user' || $user->isAdmin()), 403);
            abort_if($audience === 'admin' && ! $user->isAdmin(), 403);
            if ($purpose === 'migration') {
                abort_unless(config('totp.migration.deadline') && $expiresAt->lte(CarbonImmutable::parse(config('totp.migration.deadline'))), 422);
            } else {
                abort_unless($expiresAt->lte(now()->addMinutes(15)), 422);
            }
            $approvers = [];
            foreach (array_unique($approverTokenIds) as $tokenId) {
                $token = ApiToken::find($tokenId);
                $approver = $token?->user;
                abort_unless($approver && $approver->id !== $user->id
                    && in_array($approver->id, (array) config('totp.assisted.approver_user_ids'), true)
                    && $approver->hasRole('super_admin') && $this->eligibility->allows($token, $approver, 'admin')
                    && $token->mfa_verified_at->gte(now()->subMinutes(5)), 403, 'Independent recent operator approval is required.');
                $approvers[] = $approver->id;
            }
            $approvers = array_values(array_unique($approvers));
            abort_unless(count($approvers) >= max(2, (int) config('totp.assisted.required_approvals', 2)), 403, 'Independent approvals are required.');
            AuthEnrollmentGrant::where('user_id', $user->id)->where('purpose', $purpose)->where('audience', $audience)
                ->whereNull('consumed_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $token = bin2hex(random_bytes(32));
            $grant = new AuthEnrollmentGrant;
            $grant->forceFill(['user_id' => $user->id, 'token_hash' => hash('sha256', $token),
                'audience' => $audience, 'purpose' => $purpose, 'auth_epoch' => $user->auth_epoch,
                'email_hash' => hash('sha256', $user->email), 'policy_reference' => config('totp.'.$policy.'.policy_reference'),
                'evidence_reference' => $evidenceReference, 'approver_ids' => $approvers,
                'approved_at' => now(), 'expires_at' => $expiresAt])->save();
            $this->security->event('enrollment_grant_approved', $user, ['grant_id' => $grant->id,
                'purpose' => $purpose, 'audience' => $audience, 'actor_ids' => $approvers]);

            return $token;
        });
    }

    public function find(#[\SensitiveParameter] string $token): ?AuthEnrollmentGrant
    {
        return AuthEnrollmentGrant::where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
    }

    /** Public statement to approve independently; no capability or secret. */
    public function approvalMessage(User $user, string $audience, string $purpose, CarbonImmutable $expiresAt,
        string $evidenceReference, string $nonce): string
    {
        $policy = $purpose === 'migration' ? 'migration' : 'assisted';

        return json_encode(['context' => 'origin-wallet-enrollment-grant-v1', 'user_id' => $user->id,
            'email_hash' => hash('sha256', $user->email), 'auth_epoch' => $user->auth_epoch,
            'audience' => $audience, 'purpose' => $purpose, 'expires_at' => $expiresAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'policy_reference' => config('totp.'.$policy.'.policy_reference'),
            'evidence_reference' => $evidenceReference, 'nonce' => $nonce], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Bootstrap without grandfathering an unenrolled portal session. Two
     * independent approved operators sign the exact current account/version
     * statement using standard libsodium Ed25519. No HTTP approval endpoint,
     * private-key handling, automatic approval or default identities.
     */
    public function issueSigned(int $userId, string $audience, string $purpose, CarbonImmutable $expiresAt,
        string $evidenceReference, string $nonce, array $attestations): string
    {
        $policy = $purpose === 'migration' ? 'migration' : 'assisted';
        abort_unless(config('totp.'.$policy.'.enabled') && filled(config('totp.'.$policy.'.policy_reference')), 403, 'Enrollment policy is inactive.');
        abort_unless(function_exists('sodium_crypto_sign_verify_detached'), 503, 'Independent approval verification is unavailable.');
        abort_unless(in_array($audience, ['admin', 'user'], true) && in_array($purpose, ['migration', 'initial_enrollment', 'replacement'], true)
            && preg_match('/\A[0-9a-f]{64}\z/D', $nonce) === 1 && strlen($evidenceReference) >= 8 && strlen($evidenceReference) <= 255
            && $expiresAt->gt(now()), 422, 'Invalid enrollment authorization.');

        return DB::transaction(function () use ($userId, $audience, $purpose, $expiresAt, $evidenceReference, $nonce, $attestations, $policy): string {
            $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $factor = app(AuthChallengeService::class)->lockFactor($user);
            abort_if(($purpose === 'replacement') !== ($factor !== null), 422);
            abort_if(($purpose === 'migration' && ($user->isAdmin() || $audience !== 'user')) || ($audience === 'admin' && ! $user->isAdmin()), 403);
            if ($purpose === 'migration') {
                abort_unless(config('totp.migration.deadline') && $expiresAt->lte(CarbonImmutable::parse(config('totp.migration.deadline'))), 422);
            } else {
                abort_unless($expiresAt->lte(now()->addMinutes(15)), 422);
            }
            $message = $this->approvalMessage($user, $audience, $purpose, $expiresAt, $evidenceReference, $nonce);
            $digest = hash('sha256', $message);
            abort_if(AuthEnrollmentGrant::where('approval_digest', $digest)->exists(), 422, 'Approval statement has already been used.');
            $approvers = [];
            $keys = [];
            $configured = (array) config('totp.assisted.operator_public_keys');
            foreach ($attestations as $attestation) {
                $operator = $configured[$attestation['operator_id'] ?? ''] ?? null;
                $key = is_array($operator) ? base64_decode($operator['public_key'] ?? '', true) : false;
                $signature = base64_decode($attestation['signature'] ?? '', true);
                $actor = is_array($operator) ? User::find($operator['user_id'] ?? 0) : null;
                abort_unless($actor !== null && $actor->id !== $user->id && $actor->isAdmin()
                    && $key !== false && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                    && $signature !== false && strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES
                    && sodium_crypto_sign_verify_detached($signature, $message, $key), 403, 'Independent signed approval is required.');
                $approvers[] = $actor->id;
                $keys[] = hash('sha256', $key);
            }
            $required = max(2, (int) config('totp.assisted.required_approvals', 2));
            abort_unless(count(array_unique($approvers)) >= $required && count(array_unique($keys)) >= $required, 403, 'Independent signed approvals are required.');
            AuthEnrollmentGrant::where('user_id', $user->id)->where('purpose', $purpose)->where('audience', $audience)
                ->whereNull('revoked_at')->whereNull('consumed_at')->update(['revoked_at' => now()]);
            $token = bin2hex(random_bytes(32));
            $grant = new AuthEnrollmentGrant;
            $grant->forceFill(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'approval_digest' => $digest,
                'audience' => $audience, 'purpose' => $purpose, 'auth_epoch' => $user->auth_epoch,
                'email_hash' => hash('sha256', $user->email), 'policy_reference' => config('totp.'.$policy.'.policy_reference'),
                'evidence_reference' => $evidenceReference, 'approver_ids' => array_values(array_unique($approvers)),
                'approved_at' => now(), 'expires_at' => $expiresAt])->save();
            $this->security->event('enrollment_grant_approved', $user, ['grant_id' => $grant->id, 'actor_ids' => $approvers,
                'audience' => $audience, 'purpose' => $purpose]);

            return $token;
        });
    }
}
