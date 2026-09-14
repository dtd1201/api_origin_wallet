<?php

namespace App\Services\Nium;

use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;
use Illuminate\Support\Str;

final class NiumHkSubmitKycValidator
{
    public function assertManualStakeholder(array $payload): void
    {
        $documents = $payload['proofOfIdentityDocument'] ?? null;
        $document = is_array($documents) && array_is_list($documents) && count($documents) === 1 ? $documents[0] : null;
        $expiry = $this->strictFutureDate($document['expiryDate'] ?? null);
        if (($payload['region'] ?? null) !== 'HK'
            || ($payload['entityType'] ?? null) !== 'individual_stakeholder'
            || ($payload['kycMode'] ?? null) !== 'manual_kyc'
            || ! is_string($payload['entityReferenceId'] ?? null) || trim($payload['entityReferenceId']) === ''
            || ! is_array($document)
            || ($document['type'] ?? null) !== 'passport'
            || ! is_string($document['identificationNumber'] ?? null) || trim($document['identificationNumber']) === ''
            || preg_match('/^[A-Z]{2}$/', (string) ($document['issuanceCountry'] ?? '')) !== 1
            || $expiry === null
            || ! is_array($document['fileIds'] ?? null) || count($document['fileIds']) !== 1
            || ! Str::isUuid($document['fileIds'][0] ?? null)) {
            throw new RuntimeException('Invalid Nium HK individual stakeholder manual KYC payload.');
        }
    }

    private function strictFutureDate(mixed $value): ?CarbonImmutable
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
        return $date !== false && $date->format('Y-m-d') === $value
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->isFuture() ? $date : null;
    }

    public function assert(array $payload): void
    {
        if (($payload['region'] ?? null) !== 'HK'
            || ! in_array($payload['entityType'] ?? null, ['applicant', 'individual_stakeholder'], true)
            || ! is_bool($payload['isResident'] ?? null)
            || ! is_string($payload['entityReferenceId'] ?? null)
            || trim($payload['entityReferenceId']) === ''
            || ($payload['kycMode'] ?? null) !== 'biometric_kyc') {
            throw new RuntimeException('Invalid Nium HK biometric Submit KYC entity payload.');
        }

        $documents = $payload['proofOfIdentityDocument'] ?? null;
        if (! is_array($documents) || count($documents) !== 1 || ! array_is_list($documents)) {
            throw new RuntimeException('Nium HK biometric Submit KYC requires exactly one identity document.');
        }

        $document = $documents[0];
        $expiryValue = $document['expiryDate'] ?? null;
        try {
            $expiry = is_string($expiryValue)
                ? CarbonImmutable::createFromFormat('!Y-m-d', $expiryValue)
                : false;
        } catch (Throwable) {
            $expiry = false;
        }
        $expiryErrors = CarbonImmutable::getLastErrors();
        $expiryIsExact = $expiry !== false
            && $expiry->format('Y-m-d') === $expiryValue
            && ($expiryErrors === false
                || ($expiryErrors['warning_count'] === 0 && $expiryErrors['error_count'] === 0));

        if (($document['type'] ?? null) !== 'passport'
            || ! is_string($document['identificationNumber'] ?? null)
            || trim($document['identificationNumber']) === ''
            || preg_match('/^[A-Z]{2}$/', (string) ($document['issuanceCountry'] ?? '')) !== 1
            || ! $expiryIsExact
            || ! $expiry->isFuture()) {
            throw new RuntimeException('Invalid Nium HK biometric passport identity document.');
        }

        if (array_key_exists('fileIds', $document) || array_key_exists('proofOfAddressDocument', $payload)) {
            throw new RuntimeException('Biometric Submit KYC must not contain fileIds or proofOfAddress.');
        }
    }
}
