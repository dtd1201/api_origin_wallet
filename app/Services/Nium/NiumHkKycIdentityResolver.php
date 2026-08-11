<?php

namespace App\Services\Nium;

use App\Models\KycRelatedPerson;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

final class NiumHkKycIdentityResolver
{
    private const APPROVED_SOURCE = 'operator_verified_factual_identity_v1';

    public function resolve(KycRelatedPerson $person): array
    {
        $metadata = $person->metadata;
        $identity = is_array($metadata) ? ($metadata['nium_biometric_identity'] ?? null) : null;

        if (! is_array($identity) || array_is_list($identity)) {
            throw new RuntimeException('Approved factual biometric identity metadata is required.');
        }

        $number = $identity['identification_number'] ?? null;
        $expiryValue = $identity['expiry_date'] ?? null;
        $expiry = $this->exactDate($expiryValue);

        if (($identity['type'] ?? null) !== 'passport'
            || ! is_string($number)
            || trim($number) === ''
            || ($identity['issuance_country'] ?? null) !== 'VN'
            || $expiry === null
            || ! $expiry->isFuture()
            || ($identity['factual'] ?? null) !== true
            || ($identity['synthetic'] ?? null) !== false
            || ($identity['source'] ?? null) !== self::APPROVED_SOURCE) {
            throw new RuntimeException('Approved factual biometric identity metadata is invalid.');
        }

        return [
            'identification_number' => $number,
            'expiry_date' => $expiryValue,
        ];
    }

    private function exactDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        $errors = CarbonImmutable::getLastErrors();

        return $date !== false
            && $date->format('Y-m-d') === $value
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                ? $date
                : null;
    }
}
