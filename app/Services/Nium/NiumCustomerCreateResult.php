<?php

namespace App\Services\Nium;

use InvalidArgumentException;

final readonly class NiumCustomerCreateResult
{
    private const INVALID_RESPONSE_CATEGORIES = [
        'create_response_shape_invalid',
        'create_identifiers_missing',
        'create_external_reference_mismatch',
        'create_identifier_conflict',
    ];

    private const FAILED_CATEGORIES = [
        'create_lookup_provenance_invalid',
        'payload_hash_mismatch',
        'payload_verification_failed',
        'create_transport_failed',
        'create_mapper_failed',
        'create_authentication_failed',
        'create_rate_limited',
        'create_provider_server_error',
        'create_provider_failed',
    ];

    private function __construct(
        public NiumCustomerCreateState $state,
        public ?int $httpStatus,
        public bool $customerIdentifierPresent,
        public bool $walletIdentifierPresent,
        public ?string $failureCategory,
    ) {
        self::assertHttpStatus($httpStatus);
    }

    public static function created(int $httpStatus): self
    {
        return new self(
            NiumCustomerCreateState::Created,
            $httpStatus,
            true,
            true,
            null,
        );
    }

    public static function duplicate(int $httpStatus): self
    {
        return new self(
            NiumCustomerCreateState::Duplicate,
            $httpStatus,
            false,
            false,
            'duplicate_external_id',
        );
    }

    public static function invalidResponse(
        ?int $httpStatus,
        bool $customerIdentifierPresent,
        bool $walletIdentifierPresent,
        string $failureCategory,
    ): self {
        self::assertFailureCategory($failureCategory, self::INVALID_RESPONSE_CATEGORIES);

        return new self(
            NiumCustomerCreateState::InvalidResponse,
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
            NiumCustomerCreateState::Failed,
            $httpStatus,
            false,
            false,
            $failureCategory,
        );
    }

    public function isPersistable(): bool
    {
        return $this->state === NiumCustomerCreateState::Created;
    }

    private static function assertHttpStatus(?int $httpStatus): void
    {
        if ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599)) {
            throw new InvalidArgumentException('Nium customer create result HTTP status is invalid.');
        }
    }

    private static function assertFailureCategory(string $failureCategory, array $allowed): void
    {
        if (! in_array($failureCategory, $allowed, true)) {
            throw new InvalidArgumentException('Nium customer create result failure category is invalid.');
        }
    }
}
