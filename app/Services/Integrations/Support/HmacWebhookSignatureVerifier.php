<?php

namespace App\Services\Integrations\Support;

class HmacWebhookSignatureVerifier
{
    public function isValid(string $payload, string $secret, string $receivedSignature, string $algorithm = 'sha256'): bool
    {
        if ($secret === '' || $receivedSignature === '') {
            return false;
        }

        $expectedHex = hash_hmac($algorithm, $payload, $secret);
        $expectedBase64 = base64_encode(hash_hmac($algorithm, $payload, $secret, true));

        $candidates = array_unique([
            $expectedHex,
            "{$algorithm}={$expectedHex}",
            strtoupper($expectedHex),
            "{$algorithm}=".strtoupper($expectedHex),
            $expectedBase64,
            "{$algorithm}={$expectedBase64}",
        ]);

        foreach ($candidates as $candidate) {
            if (hash_equals($candidate, trim($receivedSignature))) {
                return true;
            }
        }

        return false;
    }
}
