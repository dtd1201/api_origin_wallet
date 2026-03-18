<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleTokenVerifier
{
    public function verify(string $idToken): array
    {
        $response = Http::timeout(10)
            ->acceptJson()
            ->get('https://oauth2.googleapis.com/tokeninfo', [
                'id_token' => $idToken,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Google token is invalid.');
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Unable to verify Google token.');
        }

        $configuredClientId = (string) config('services.google.client_id', '');
        $audience = (string) ($payload['aud'] ?? '');
        $email = (string) ($payload['email'] ?? '');
        $emailVerified = filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOL);

        if ($configuredClientId === '') {
            throw new RuntimeException('Google client ID is not configured.');
        }

        if ($audience !== $configuredClientId) {
            throw new RuntimeException('Google token audience is invalid.');
        }

        if ($email === '' || ! $emailVerified) {
            throw new RuntimeException('Google account email is not verified.');
        }

        return [
            'email' => $email,
            'full_name' => (string) ($payload['name'] ?? ''),
            'google_subject' => (string) ($payload['sub'] ?? ''),
            'avatar' => (string) ($payload['picture'] ?? ''),
        ];
    }
}
