<?php

namespace App\Services\Integrations\Support;

use Illuminate\Http\Request;

class StaticWebhookHeaderVerifier
{
    public function isValid(Request $request, string $headerName, string $expectedValue): bool
    {
        if ($headerName === '' || $expectedValue === '') {
            return false;
        }

        $receivedValue = (string) $request->header($headerName, '');

        return $receivedValue !== '' && hash_equals($expectedValue, $receivedValue);
    }
}
