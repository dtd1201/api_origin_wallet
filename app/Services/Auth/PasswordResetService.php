<?php

namespace App\Services\Auth;

use App\Mail\PasswordResetCodeMail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class PasswordResetService
{
    public function __construct(private readonly AuthVerificationSecurity $verificationSecurity) {}

    public function sendResetCode(string $email): array
    {
        $user = User::query()
            ->where('email', $email)
            ->first();

        $response = [
            'message' => 'If the email exists in our system, a password reset code has been sent.',
            'email' => $email,
            'expires_in_minutes' => $this->passwordResetExpiryMinutes(),
        ];

        if ($user === null) {
            return $response;
        }

        $verificationCode = DB::transaction(function () use ($user): string {
            $passwordReset = DB::table('password_reset_tokens')
                ->where('email', $user->email)
                ->lockForUpdate()
                ->first();

            if ($this->passwordResetRequestIsThrottled($passwordReset)) {
                abort(429, 'Please wait before requesting another password reset code.');
            }

            $verificationCode = $this->generateVerificationCode();

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                [
                    'token' => Hash::make($verificationCode),
                    'created_at' => now(),
                    'verification_attempts' => 0,
                    'locked_until' => null,
                    'last_attempt_at' => null,
                ]
            );

            Mail::to($user->email)->send(
                new PasswordResetCodeMail(
                    fullName: (string) ($user->full_name ?? ''),
                    verificationCode: $verificationCode,
                    expiresInMinutes: $this->passwordResetExpiryMinutes(),
                )
            );

            return $verificationCode;
        });

        if ((bool) config('mail.expose_verification_code', false)) {
            $response['verification_code'] = $verificationCode;
        }

        return $response;
    }

    public function resetPassword(array $validated, ?string $ipAddress = null, ?string $userAgent = null): void
    {
        $this->verificationSecurity->enforceRateLimits(
            'password_reset',
            $validated['email'],
            $ipAddress,
            $userAgent,
        );

        $result = DB::transaction(function () use ($validated, $ipAddress, $userAgent): ?string {
            /** @var User|null $user */
            $user = User::query()
                ->where('email', $validated['email'])
                ->lockForUpdate()
                ->first();

            if ($user === null) {
                $this->verificationSecurity->record('password_reset_verification_failed', $validated['email'], null, $ipAddress, $userAgent, [
                    'reason' => 'user_missing',
                ]);

                return 'No user found for this email.';
            }

            $passwordReset = DB::table('password_reset_tokens')
                ->where('email', $validated['email'])
                ->lockForUpdate()
                ->first();

            if ($passwordReset === null) {
                $this->verificationSecurity->record('password_reset_verification_failed', $validated['email'], $user->id, $ipAddress, $userAgent, [
                    'reason' => 'pending_reset_missing_or_replayed',
                ]);

                return 'No pending password reset found for this email.';
            }

            if ($passwordReset->locked_until !== null && Carbon::parse($passwordReset->locked_until)->isFuture()) {
                $this->verificationSecurity->record('password_reset_verification_lockout', $validated['email'], $user->id, $ipAddress, $userAgent, [
                    'reason' => 'attempt_limit',
                ]);

                return 'Too many invalid password reset attempts. Please request a new code.';
            }

            if ($this->passwordResetTokenIsExpired($passwordReset)) {
                $this->verificationSecurity->record('password_reset_verification_failed', $validated['email'], $user->id, $ipAddress, $userAgent, [
                    'reason' => 'expired',
                ]);

                return 'Password reset code has expired.';
            }

            if (! Hash::check($validated['verification_code'], $passwordReset->token)) {
                $attempts = ((int) $passwordReset->verification_attempts) + 1;
                $lockedOut = $attempts >= $this->verificationSecurity->maxAttempts();

                DB::table('password_reset_tokens')
                    ->where('email', $validated['email'])
                    ->update([
                        'verification_attempts' => $attempts,
                        'last_attempt_at' => now(),
                        'locked_until' => $lockedOut
                            ? now()->addMinutes($this->verificationSecurity->lockoutMinutes())
                            : null,
                    ]);

                $this->verificationSecurity->record(
                    $lockedOut ? 'password_reset_verification_lockout' : 'password_reset_verification_failed',
                    $validated['email'],
                    $user->id,
                    $ipAddress,
                    $userAgent,
                    ['attempts' => $attempts, 'reason' => 'invalid_code']
                );

                if (! $lockedOut && $attempts >= $this->verificationSecurity->suspiciousAttemptThreshold()) {
                    $this->verificationSecurity->record('suspicious_verification_attempts', $validated['email'], $user->id, $ipAddress, $userAgent, [
                        'attempts' => $attempts,
                        'purpose' => 'password_reset',
                    ]);
                }

                return $lockedOut
                    ? 'Too many invalid password reset attempts. Please request a new code.'
                    : 'Invalid password reset code.';
            }

            $user->forceFill([
                'password_hash' => Hash::make($validated['password']),
            ])->save();

            $user->apiTokens()->delete();

            DB::table('password_reset_tokens')
                ->where('email', $validated['email'])
                ->delete();

            return null;
        });

        if ($result !== null) {
            abort(422, $result);
        }

        $this->verificationSecurity->clearAccountRateLimit('password_reset', $validated['email']);
    }

    private function generateVerificationCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function passwordResetExpiryMinutes(): int
    {
        return (int) config('auth.passwords.users.expire', 60);
    }

    private function passwordResetThrottleSeconds(): int
    {
        return (int) config('auth.passwords.users.throttle', 60);
    }

    private function passwordResetTokenIsExpired(object $passwordReset): bool
    {
        return Carbon::parse($passwordReset->created_at)
            ->addMinutes($this->passwordResetExpiryMinutes())
            ->isPast();
    }

    private function passwordResetRequestIsThrottled(?object $passwordReset): bool
    {
        if ($passwordReset === null) {
            return false;
        }

        return Carbon::parse($passwordReset->created_at)
            ->addSeconds($this->passwordResetThrottleSeconds())
            ->isFuture();
    }
}
