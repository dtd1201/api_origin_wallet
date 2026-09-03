<?php

namespace App\Services\Auth;

use App\Models\AuthSecurityEvent;
use Illuminate\Support\Facades\RateLimiter;

class AuthVerificationSecurity
{
    public function enforceRateLimits(string $purpose, string $email, ?string $ipAddress, ?string $userAgent): void
    {
        $keys = [
            $this->rateKey($purpose, 'account', $this->accountIdentifier($email)) => (int) config('auth.verification.account_rate_limit', 10),
            $this->rateKey($purpose, 'ip', $ipAddress ?: 'unknown') => (int) config('auth.verification.ip_rate_limit', 30),
        ];
        $decaySeconds = (int) config('auth.verification.rate_decay_seconds', 60);

        foreach ($keys as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                $this->record('verification_rate_limited', $email, null, $ipAddress, $userAgent, [
                    'purpose' => $purpose,
                    'retry_after_seconds' => RateLimiter::availableIn($key),
                ]);

                abort(429, 'Too many verification attempts. Please try again later.');
            }
        }

        foreach (array_keys($keys) as $key) {
            RateLimiter::hit($key, $decaySeconds);
        }
    }

    public function clearAccountRateLimit(string $purpose, string $email): void
    {
        RateLimiter::clear($this->rateKey($purpose, 'account', $this->accountIdentifier($email)));
    }

    public function record(
        string $eventType,
        string $email,
        ?int $userId,
        ?string $ipAddress,
        ?string $userAgent,
        array $metadata = [],
    ): void {
        AuthSecurityEvent::query()->create([
            'user_id' => $userId,
            'event_type' => $eventType,
            'account_identifier' => $this->accountIdentifier($email),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
            'metadata' => $metadata ?: null,
        ]);
    }

    public function maxAttempts(): int
    {
        return (int) config('auth.verification.max_attempts', 5);
    }

    public function lockoutMinutes(): int
    {
        return (int) config('auth.verification.lockout_minutes', 15);
    }

    public function suspiciousAttemptThreshold(): int
    {
        return (int) config('auth.verification.suspicious_attempt_threshold', 3);
    }

    private function accountIdentifier(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    private function rateKey(string $purpose, string $dimension, string $value): string
    {
        return "auth-verification:{$purpose}:{$dimension}:{$value}";
    }
}
