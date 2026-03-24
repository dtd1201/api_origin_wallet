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

    public function resetPassword(array $validated): void
    {
        DB::transaction(function () use ($validated): void {
            /** @var User|null $user */
            $user = User::query()
                ->where('email', $validated['email'])
                ->lockForUpdate()
                ->first();

            abort_if($user === null, 422, 'No user found for this email.');

            $passwordReset = DB::table('password_reset_tokens')
                ->where('email', $validated['email'])
                ->lockForUpdate()
                ->first();

            abort_if($passwordReset === null, 422, 'No pending password reset found for this email.');
            abort_if($this->passwordResetTokenIsExpired($passwordReset), 422, 'Password reset code has expired.');
            abort_if(! Hash::check($validated['verification_code'], $passwordReset->token), 422, 'Invalid password reset code.');

            $user->forceFill([
                'password_hash' => Hash::make($validated['password']),
            ])->save();

            $user->apiTokens()->delete();

            DB::table('password_reset_tokens')
                ->where('email', $validated['email'])
                ->delete();
        });
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
