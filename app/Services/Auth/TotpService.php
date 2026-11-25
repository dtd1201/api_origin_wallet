<?php

namespace App\Services\Auth;

use App\Exceptions\AuthenticationServiceUnavailable;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Crypt;
use OTPHP\TOTP;
use Throwable;

/** All TOTP/encoding and QR primitives stay in the reviewed libraries. */
class TotpService
{
    public function newSecret(): string
    {
        return $this->crypto(function (): string {
            // OTPHP uses its CSPRNG secret factory (>=160 bits). Refuse an
            // incompatible implementation rather than accepting weaker output.
            $secret = TOTP::create(null, 30, 'sha1', 6)->getSecret();
            if (strlen($secret) < 32 || preg_match('/\A[A-Z2-7]+\z/D', $secret) !== 1) {
                throw new \RuntimeException('Invalid authenticator secret.');
            }

            return $secret;
        });
    }

    public function encrypt(#[\SensitiveParameter] string $secret): string
    {
        return $this->crypto(fn () => Crypt::encryptString($secret));
    }

    public function decrypt(#[\SensitiveParameter] string $ciphertext, ?string $keyVersion = 'app-v1'): string
    {
        if ($keyVersion !== 'app-v1') {
            throw new AuthenticationServiceUnavailable;
        }
        return $this->crypto(function () use ($ciphertext): string {
            $secret = Crypt::decryptString($ciphertext);
            $this->otp($secret);

            return $secret;
        });
    }

    public function matchingCounter(#[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $code, ?int $timestamp = null): ?int
    {
        if (preg_match('/\A[0-9]{6}\z/D', $code) !== 1) {
            return null;
        }

        return $this->crypto(function () use ($secret, $code, $timestamp): ?int {
            $otp = $this->otp($secret);
            $counter = intdiv($timestamp ?? now()->getTimestamp(), 30);
            $matches = [];
            foreach ([$counter - 1, $counter, $counter + 1] as $candidate) {
                if ($candidate >= 0 && hash_equals($otp->at($candidate * 30), $code)) {
                    $matches[] = $candidate;
                }
            }

            // An ambiguous decimal collision cannot select a second counter.
            return count($matches) === 1 ? $matches[0] : null;
        });
    }

    public function provisioning(#[\SensitiveParameter] string $secret, string $email): array
    {
        return $this->crypto(function () use ($secret, $email): array {
            $otp = $this->otp($secret);
            $otp->setIssuer('Origin Wallet');
            $otp->setLabel($email);
            $uri = $otp->getProvisioningUri();
            $writer = new Writer(new ImageRenderer(new RendererStyle(240), new SvgImageBackEnd));

            return ['otpauth_uri' => $uri, 'qr_svg' => $writer->writeString($uri), 'manual_key' => $secret];
        });
    }

    private function otp(#[\SensitiveParameter] string $secret): TOTP
    {
        if (strlen($secret) < 32 || preg_match('/\A[A-Z2-7]+\z/D', $secret) !== 1) {
            throw new \RuntimeException('Invalid authenticator secret.');
        }

        return TOTP::create($secret, 30, 'sha1', 6);
    }

    private function crypto(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable) {
            // Do not chain library exceptions containing provisioning material.
            throw new AuthenticationServiceUnavailable;
        }
    }
}
