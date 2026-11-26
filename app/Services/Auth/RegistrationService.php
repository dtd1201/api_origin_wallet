<?php

namespace App\Services\Auth;

use App\Mail\RegistrationVerificationCodeMail;
use App\Models\AuthRegistrationEvidence;
use App\Models\PendingRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class RegistrationService
{
    public function __construct(private readonly AuthSecurity $security) {}

    public function startRegistration(array $validated): array
    {
        $email = trim($validated['email']);
        $this->security->limit('registration', $email);
        return DB::transaction(function () use ($validated, $email): array {
            if (User::where('email', $email)->exists()) {
                return ['message' => 'If registration can proceed, check your verification email.',
                    'email' => $email, 'registration_version' => (string) Str::uuid(), 'expires_in_minutes' => 15];
            }
            $pending = PendingRegistration::where('email', $email)->lockForUpdate()->first();
            // Do not let another request replace the password/browser/version
            // underneath an already delivered verification email.
            if ($pending !== null && $pending->expires_at->gt(now())) {
                return ['message' => 'If registration can proceed, check your verification email.',
                    'email' => $email, 'registration_version' => $pending->registration_version,
                    'expires_in_minutes' => 15];
            }
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $version = (string) Str::uuid();
            $pending ??= new PendingRegistration;
            $pending->forceFill(['email' => $email, 'phone' => $validated['phone'] ?? null,
                'full_name' => $validated['full_name'] ?? null, 'password_hash' => Hash::make($validated['password']),
                'verification_code' => null, 'verification_code_hash' => Hash::make($code),
                'registration_version' => $version, 'client_binding_hash' => hash('sha256', $validated['client_binding']),
                'verification_attempts' => 0, 'expires_at' => now()->addMinutes(15), 'verified_at' => null])->save();
            $activation = URL::temporarySignedRoute('auth.register.activate', now()->addMinutes(15),
                ['email' => $email, 'code' => $code, 'registration_version' => $version]);
            Mail::to($email)->send(new RegistrationVerificationCodeMail(
                fullName: (string) ($validated['full_name'] ?? ''), verificationCode: $code,
                expiresInMinutes: 15, activationUrl: $activation));
            // Preserve registration debug behavior only; it cannot expose TOTP,
            // recovery codes or restricted authentication capabilities.
            return ['message' => 'If registration can proceed, check your verification email.', 'email' => $email,
                'registration_version' => $version, 'expires_in_minutes' => 15,
                ...((bool) config('mail.expose_verification_code', false) ? ['verification_code' => $code] : [])];
        });
    }

    public function completeRegistrationByCode(string $email, string $code, string $version, string $binding): User
    {
        return $this->complete($email, $code, $version, $binding);
    }

    public function verifyAndStartEnrollment(string $email, string $code, string $version, string $binding): array
    {
        // Registration already supplied the chosen password. The code must
        // match its immutable version and original browser. No link-click or
        // email-only login can enter this transaction.
        return $this->complete($email, $code, $version, $binding, true);
    }

    public function completeRegistrationByActivationLink(string $email, string $code, string $version): User
    {
        // A link click does not prove that the registration-initiating browser
        // owns the mailbox. No browser enrollment trust is conveyed by GET.
        return $this->complete($email, $code, $version, null);
    }

    private function complete(string $email, #[\SensitiveParameter] string $code, string $version, ?string $binding, bool $enroll = false): User|array
    {
        $this->security->limit('registration', $email);
        $result = DB::transaction(function () use ($email, $code, $version, $binding, $enroll): User|array|null {
            $pending = PendingRegistration::where('email', $email)->lockForUpdate()->first();
            if ($pending === null || $pending->expires_at->lte(now()) || $pending->verification_attempts >= 5
                || $pending->registration_version !== $version || $pending->verification_code_hash === null
                || ($binding !== null && ! hash_equals((string) $pending->client_binding_hash, hash('sha256', $binding)))
                || ! Hash::check($code, $pending->verification_code_hash)) {
                if ($pending !== null) {
                    $pending->forceFill(['verification_attempts' => $pending->verification_attempts + 1])->save();
                }
                $this->security->event('registration_verification_failed');
                return null;
            }
            if (User::where('email', $email)->exists()) {
                return null;
            }
            $user = User::create(['email' => $email, 'phone' => $pending->phone, 'full_name' => $pending->full_name,
                'password_hash' => $pending->password_hash, 'status' => 'pending', 'kyc_status' => 'pending']);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->refresh();
            $evidence = new AuthRegistrationEvidence;
            $evidence->forceFill(['user_id' => $user->id, 'registration_version' => $version,
                'email_hash' => hash('sha256', $user->email), 'password_fingerprint' => hash('sha256', $user->password_hash),
                'client_binding_hash' => $binding === null ? null : hash('sha256', $binding),
                'registration_proof_hash' => $pending->verification_code_hash, 'auth_epoch' => $user->auth_epoch,
                'verified_at' => now(), 'expires_at' => now()->addMinutes(30)])->save();
            $pending->delete();
            $this->security->event('registration_email_verified', $user);
            if ($enroll) {
                return app(AuthChallengeService::class)->create($user, null, 'user', 'enrollment_setup',
                    hash('sha256', $binding), now(), now()->addSeconds(config('totp.ceremony_seconds', 1200)), [
                        'trusted_identity_method' => 'new_registration', 'trusted_identity_verified_at' => now(),
                        'registration_evidence_id' => $evidence->id,
                    ]);
            }
            return $user;
        });
        abort_if($result === null, 422, 'Registration verification could not be completed.');
        return $result;
    }
}
