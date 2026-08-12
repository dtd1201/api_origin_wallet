<?php

namespace App\Services\Nium;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class NiumHkSubmitKycValidator
{
    public function assertManual(array $payload): void
    {
        if (($payload['region'] ?? null) !== 'HK'
            || ($payload['entityType'] ?? null) !== 'INDIVIDUAL_STAKEHOLDER'
            || ($payload['kycMode'] ?? null) !== 'MANUAL_KYC'
            || ! is_string($payload['entityReferenceId'] ?? null)
            || trim($payload['entityReferenceId']) === '') {
            throw new RuntimeException('Invalid Nium HK manual KYC stakeholder contract.');
        }

        $this->assertManualDocument($payload, 'proofOfIdentityDocument');
        if (array_key_exists('proofOfAddressDocument', $payload)) {
            $this->assertManualDocument($payload, 'proofOfAddressDocument');
        }
    }

    private function assertManualDocument(array $payload, string $field): void
    {
            $documents = $payload[$field] ?? null;
            $document = is_array($documents) && array_is_list($documents) && count($documents) === 1
                ? $documents[0]
                : null;
            if (! is_array($document)
                || ! is_string($document['type'] ?? null)
                || trim($document['type']) === ''
                || ! is_array($document['fileIds'] ?? null)
                || count($document['fileIds']) !== 1
                || ! Str::isUuid($document['fileIds'][0] ?? null)) {
                throw new RuntimeException("Nium HK manual KYC requires one provider-backed {$field}.");
            }
    }

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
