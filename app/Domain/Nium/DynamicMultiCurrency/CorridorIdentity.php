<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency;

use InvalidArgumentException;

/** Exact, immutable scope. Values are never inferred from runtime configuration. */
final readonly class CorridorIdentity
{
    public function __construct(
        public string $provider,
        public string $environment,
        public string $client,
        public string $legalEntity,
        public string $customer,
        public string $account,
        public string $fundingWallet,
        public string $sourceCurrency,
        public string $destinationCountry,
        public string $destinationCurrency,
        public string $payoutMethod,
        public string $customerType,
        public string $beneficiaryType,
        public string $contractVersion,
        public string $profileVersion,
    ) {
        foreach (get_object_vars($this) as $value) {
            if ($value === '' || trim($value) !== $value || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new InvalidArgumentException('Corridor dimensions must be explicit, nonempty values.');
            }
        }

        if (! in_array($environment, ['sandbox', 'production'], true)
            || preg_match('/^[A-Z]{3}$/D', $sourceCurrency) !== 1
            || preg_match('/^[A-Z]{2}$/D', $destinationCountry) !== 1
            || preg_match('/^[A-Z]{3}$/D', $destinationCurrency) !== 1) {
            throw new InvalidArgumentException('Corridor environment and currency/country codes must be explicit and canonical.');
        }

        foreach ([$payoutMethod, $customerType, $beneficiaryType] as $value) {
            if (preg_match('/^[A-Z][A-Z0-9_]*$/D', $value) !== 1) {
                throw new InvalidArgumentException('Corridor method and types must be explicit canonical tokens.');
            }
        }
    }

    public function equals(self $other): bool
    {
        return get_object_vars($this) === get_object_vars($other);
    }
}
