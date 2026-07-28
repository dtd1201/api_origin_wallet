<?php

namespace App\Services\Nium;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Throwable;

class NiumCustomerErrorMapper
{
    public function isDuplicateExternalId(Response $response): bool
    {
        return ! $response->successful()
            && in_array($this->code($response), ['customer_exists', 'duplicate_external_id'], true);
    }

    public function code(Response $response): ?string
    {
        $data = $response->json();

        if (! is_array($data)) {
            return null;
        }

        $code = Arr::get($data, 'errors.0.code')
            ?? Arr::get($data, 'errors.0.errorCode')
            ?? Arr::get($data, 'errorCode')
            ?? Arr::get($data, 'code');
        $normalized = strtolower(trim((string) $code));

        return $normalized !== '' ? $normalized : null;
    }

    public function codeFromThrowable(Throwable $exception): ?string
    {
        if ($exception instanceof NiumProviderRequestException) {
            return $exception->providerCode;
        }

        $message = strtolower($exception->getMessage());

        foreach ([
            'sg_corporate_address_relationship_invalid',
            'sg_corporate_business_address_invalid',
            'sg_corporate_business_address_conflict',
            'customer_exists',
            'duplicate_external_id',
            'timeout',
            'connection',
        ] as $code) {
            if (str_contains($message, $code)) {
                return $code;
            }
        }

        return null;
    }
}
