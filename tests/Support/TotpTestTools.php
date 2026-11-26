<?php

namespace Tests\Support;

use App\Models\AuthChallenge;
use App\Models\User;
use App\Services\Auth\AuthChallengeService;
use Illuminate\Support\Facades\DB;
use OTPHP\TOTP;

trait TotpTestTools
{
    protected string $binding = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function otpCode(string $secret = MfaFixture::SECRET, int $offset = 0): string
    {
        return TOTP::create($secret, 30, 'sha1', 6)->at(now()->getTimestamp() + $offset * 30);
    }

    protected function invalidOtp(): string
    {
        $valid = [$this->otpCode(MfaFixture::SECRET, -1), $this->otpCode(), $this->otpCode(MfaFixture::SECRET, 1)];
        for ($i = 0; $i < 4; $i++) {
            $candidate = str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            if (! in_array($candidate, $valid, true)) {
                return $candidate;
            }
        }
        throw new \LogicException('Unreachable synthetic code selection.');
    }

    protected function loginChallenge(User $user, string $audience = 'user'): string
    {
        return $this->postJson($this->authPath($audience).'/login', ['email' => $user->email,
            'password' => 'password', 'client_binding' => $this->binding])->assertAccepted()->json('challenge_token');
    }

    protected function authPath(string $audience = 'user'): string
    {
        return $audience === 'admin' ? '/api/admin/auth' : '/api/auth';
    }

    protected function ceremonyPost(string $path, string $capability, array $extra = [])
    {
        return $this->withHeader('X-Auth-Client-Binding', $this->binding)->postJson($path,
            ['challenge_token' => $capability, ...$extra]);
    }

    /** A synthetic bound trusted registration, never a production-policy bypass. */
    protected function newCustomerEvidence(User $user): void
    {
        $user->refresh()->forceFill(['email_verified_at' => now()])->save();
        $row = new \App\Models\AuthRegistrationEvidence;
        $row->forceFill(['user_id' => $user->id, 'registration_version' => (string) \Illuminate\Support\Str::uuid(),
            'email_hash' => hash('sha256', $user->email), 'password_fingerprint' => hash('sha256', $user->password_hash),
            'client_binding_hash' => hash('sha256', $this->binding),
            'registration_proof_hash' => \Illuminate\Support\Facades\Hash::make('123456'),
            'auth_epoch' => $user->auth_epoch, 'verified_at' => now(), 'expires_at' => now()->addMinutes(30)])->save();
    }

    protected function storedChallenge(string $token): AuthChallenge
    {
        return AuthChallenge::where('token_hash', hash('sha256', $token))->firstOrFail();
    }
}
