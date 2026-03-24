<?php

namespace App\Services\Auth;

use App\Mail\RegistrationVerificationCodeMail;
use App\Models\PendingRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class RegistrationService
{
    public function startRegistration(array $validated): array
    {
        $verificationCode = DB::transaction(function () use ($validated): string {
            $verificationCode = $this->generateVerificationCode();
            $activationUrl = URL::temporarySignedRoute(
                'auth.register.activate',
                now()->addMinutes(15),
                [
                    'email' => $validated['email'],
                    'code' => $verificationCode,
                ]
            );

            PendingRegistration::query()->updateOrCreate(
                ['email' => $validated['email']],
                [
                    'phone' => $validated['phone'] ?? null,
                    'full_name' => $validated['full_name'] ?? null,
                    'password_hash' => Hash::make($validated['password']),
                    'verification_code' => $verificationCode,
                    'expires_at' => now()->addMinutes(15),
                    'verified_at' => null,
                ]
            );

            Mail::to($validated['email'])->send(
                new RegistrationVerificationCodeMail(
                    fullName: (string) ($validated['full_name'] ?? ''),
                    verificationCode: $verificationCode,
                    expiresInMinutes: 15,
                    activationUrl: $activationUrl,
                )
            );

            return $verificationCode;
        });

        $response = [
            'message' => 'Verification link sent to email. Please verify to complete registration.',
            'email' => $validated['email'],
            'expires_in_minutes' => 15,
        ];

        if ((bool) config('mail.expose_verification_code', false)) {
            $response['verification_code'] = $verificationCode;
        }

        return $response;
    }

    public function completeRegistrationByCode(string $email, string $verificationCode): User
    {
        return DB::transaction(function () use ($email, $verificationCode): User {
            $pendingRegistration = PendingRegistration::query()
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            abort_if($pendingRegistration === null, 422, 'No pending registration found for this email.');
            abort_if($pendingRegistration->expires_at->isPast(), 422, 'Verification code has expired.');
            abort_if($pendingRegistration->verification_code !== $verificationCode, 422, 'Invalid verification code.');

            $user = User::create([
                'email' => $pendingRegistration->email,
                'phone' => $pendingRegistration->phone,
                'full_name' => $pendingRegistration->full_name,
                'password_hash' => $pendingRegistration->password_hash,
                'status' => 'pending',
                'kyc_status' => 'pending',
            ]);

            $pendingRegistration->delete();

            return $user;
        });
    }

    public function completeRegistrationByActivationLink(string $email, string $verificationCode): User
    {
        return DB::transaction(function () use ($email, $verificationCode): User {
            $pendingRegistration = PendingRegistration::query()
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            abort_if($pendingRegistration === null, 422, 'No pending registration found for this email.');
            abort_if($pendingRegistration->expires_at->isPast(), 422, 'Verification link has expired.');
            abort_if($pendingRegistration->verification_code !== $verificationCode, 422, 'Verification link is invalid.');

            $user = User::create([
                'email' => $pendingRegistration->email,
                'phone' => $pendingRegistration->phone,
                'full_name' => $pendingRegistration->full_name,
                'password_hash' => $pendingRegistration->password_hash,
                'status' => 'pending',
                'kyc_status' => 'pending',
            ]);

            $pendingRegistration->delete();

            return $user;
        });
    }

    private function generateVerificationCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
