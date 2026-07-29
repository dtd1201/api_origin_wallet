<?php

namespace App\Services\Nium;

use InvalidArgumentException;

final readonly class NiumCustomerLookupResult
{
    private const AMBIGUOUS_CATEGORIES = [
        'lookup_customers_missing',
        'lookup_customers_not_list',
        'lookup_customer_count_invalid',
        'lookup_external_reference_mismatch',
        'lookup_identifiers_missing',
        'lookup_identifier_conflict',
    ];

    private const FAILED_CATEGORIES = [
        'lookup_provider_provenance_invalid',
        'lookup_transport_failed',
        'lookup_response_decode_failed',
        'lookup_authentication_failed',
        'lookup_rate_limited',
        'lookup_provider_server_error',
        'lookup_provider_failed',
    ];

    private function __construct(
        public NiumCustomerLookupState $state,
        public ?int $httpStatus,
        public bool $customerIdentifierPresent,
        public bool $walletIdentifierPresent,
        public ?string $failureCategory,
    ) {
        self::assertHttpStatus($httpStatus);
    }

    public static function existing(int $httpStatus): self
    {
        return new self(
            NiumCustomerLookupState::Existing,
            $httpStatus,
            true,
            true,
            null,
        );
    }

    public static function absent(int $httpStatus): self
    {
        return new self(
            NiumCustomerLookupState::Absent,
            $httpStatus,
            false,
            false,
            null,
        );
    }

    public static function ambiguous(
        ?int $httpStatus,
        bool $customerIdentifierPresent,
        bool $walletIdentifierPresent,
        string $failureCategory,
    ): self {
        self::assertFailureCategory($failureCategory, self::AMBIGUOUS_CATEGORIES);

        return new self(
            NiumCustomerLookupState::Ambiguous,
            $httpStatus,
            $customerIdentifierPresent,
            $walletIdentifierPresent,
            $failureCategory,
        );
    }

    public static function failed(?int $httpStatus, string $failureCategory): self
    {
        self::assertFailureCategory($failureCategory, self::FAILED_CATEGORIES);

        return new self(
            NiumCustomerLookupState::Failed,
            $httpStatus,
            false,
            false,
            $failureCategory,
        );
    }

    public function isPersistable(): bool
    {
        return $this->state === NiumCustomerLookupState::Existing;
    }

    private static function assertHttpStatus(?int $httpStatus): void
    {
        if ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599)) {
            throw new InvalidArgumentException('Nium customer lookup result HTTP status is invalid.');
        }
    }

    private static function assertFailureCategory(string $failureCategory, array $allowed): void
    {
        if (! in_array($failureCategory, $allowed, true)) {
            throw new InvalidArgumentException('Nium customer lookup result failure category is invalid.');
        }
    }
}
