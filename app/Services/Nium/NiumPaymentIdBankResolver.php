<?php

namespace App\Services\Nium;

use App\Models\User;

final class NiumPaymentIdBankResolver
{
    private const BANKS = [
        'HK' => [
            'USD' => 'DBS_HK',
        ],
    ];

    public function resolve(User $user, string $currency): ?string
    {
        $user->loadMissing('profile');

        $countryCode = strtoupper(trim((string) $user->profile?->country_code));
        $currency = strtoupper(trim($currency));

        return self::BANKS[$countryCode][$currency] ?? null;
    }
}
