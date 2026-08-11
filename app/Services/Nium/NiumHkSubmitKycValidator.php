<?php

namespace App\Services\Nium;

use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

final class NiumHkSubmitKycValidator
{
    public function assert(array $payload): void
    {
        if (($payload['region'] ?? null) !== 'HK'
            || ! in_array($payload['entityType'] ?? null, ['applicant', 'individual_stakeholder'], true)
            || ($payload['isResident'] ?? null) !== false
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
            || ($document['issuanceCountry'] ?? null) !== 'VN'
            || ! $expiryIsExact
            || ! $expiry->isFuture()) {
            throw new RuntimeException('Invalid Nium HK biometric passport identity document.');
        }

        if (array_key_exists('fileIds', $document) || array_key_exists('proofOfAddressDocument', $payload)) {
            throw new RuntimeException('Biometric Submit KYC must not contain fileIds or proofOfAddress.');
        }
    }
}
