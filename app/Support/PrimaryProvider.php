<?php

namespace App\Support;

use App\Models\IntegrationProvider;
use Illuminate\Validation\ValidationException;

final class PrimaryProvider
{
    public const CODE = 'nium';

    public static function code(): string
    {
        return self::CODE;
    }

    public static function resolve(): IntegrationProvider
    {
        return IntegrationProvider::query()
            ->where('code', self::CODE)
            ->firstOrFail();
    }

    public static function resolveForRequest(?int $requestedProviderId): IntegrationProvider
    {
        $provider = self::resolve();

        if ($requestedProviderId !== null && $requestedProviderId !== $provider->id) {
            throw ValidationException::withMessages([
                'provider_id' => 'Origin Wallet currently routes all live payment infrastructure through Nium.',
            ]);
        }

        return $provider;
    }

    public static function isPrimary(IntegrationProvider $provider): bool
    {
        return strtolower((string) $provider->code) === self::CODE;
    }
}
