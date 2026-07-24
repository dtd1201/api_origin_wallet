<?php

namespace App\Services\Nium;

use RuntimeException;

final class NiumSgCorporateClientPolicy
{
    public function requirements(): NiumSgCorporateClientRequirements
    {
        $configuration = config('services.nium.sg_corporate_client_schema');
        $requiredKeys = [
            'require_bank_account_details',
            'require_device_details',
            'require_routing_codes',
        ];

        if (
            ! is_array($configuration)
            || array_is_list($configuration)
            || collect($requiredKeys)->contains(
                fn (string $key): bool => ! array_key_exists($key, $configuration)
                    || ! is_bool($configuration[$key]),
            )
        ) {
            throw new RuntimeException(
                'Nium SG corporate client schema requirements are not configured.',
            );
        }

        return new NiumSgCorporateClientRequirements(
            requireBankAccountDetails: $configuration['require_bank_account_details'],
            requireDeviceDetails: $configuration['require_device_details'],
            requireRoutingCodes: $configuration['require_routing_codes'],
        );
    }
}

final readonly class NiumSgCorporateClientRequirements
{
    public function __construct(
        public bool $requireBankAccountDetails,
        public bool $requireDeviceDetails,
        public bool $requireRoutingCodes,
    ) {}
}
