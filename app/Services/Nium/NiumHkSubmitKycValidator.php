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

        $this->assertManualIdentityDocument($payload);
        if (array_key_exists('proofOfAddressDocument', $payload)) {
            $this->assertManualProofOfAddressDocument($payload);
        }
    }

    public function assertManualGenerationFive(array $payload): void
    {
        if (($payload['region'] ?? null) !== 'HK'
            || ($payload['entityType'] ?? null) !== 'individual_stakeholder'
            || ($payload['kycMode'] ?? null) !== 'manual_kyc'
            || array_key_exists('isResident', $payload)
            || ! is_string($payload['entityReferenceId'] ?? null)
            || trim($payload['entityReferenceId']) === '') {
            throw new RuntimeException('Invalid exact Nium HK generation #5 manual KYC stakeholder contract.');
        }

        $this->assertManualIdentityDocument($payload);
        $this->assertManualProofOfAddressDocument($payload);
    }

    public function assertManualGenerationSix(array $payload): void
    {
        if (($payload['region'] ?? null) !== 'HK'
            || ($payload['entityType'] ?? null) !== 'individual_stakeholder'
            || ($payload['kycMode'] ?? null) !== 'manual_kyc'
            || ! array_key_exists('isResident', $payload)
            || $payload['isResident'] !== false
            || ! is_string($payload['entityReferenceId'] ?? null)
            || trim($payload['entityReferenceId']) === '') {
            throw new RuntimeException('Invalid exact Nium HK generation #6 manual KYC stakeholder contract.');
        }

        $this->assertManualIdentityDocument($payload);
        $this->assertManualProofOfAddressDocument($payload);
    }

    public function assertManualGenerationSeven(array $payload): void
    {
        if (($payload['region'] ?? null) !== 'HK'
            || ($payload['entityType'] ?? null) !== 'INDIVIDUAL_STAKEHOLDER'
            || ($payload['kycMode'] ?? null) !== 'manual_kyc'
            || array_key_exists('isResident', $payload)
            || ! is_string($payload['entityReferenceId'] ?? null)
            || trim($payload['entityReferenceId']) === '') {
            throw new RuntimeException('Invalid exact Nium HK generation #7 manual KYC stakeholder contract.');
        }

        $this->assertManualIdentityDocument($payload);
        $this->assertManualProofOfAddressDocument($payload);
    }

    public function assertManualGenerationEight(array $payload): void
    {
        if (($payload['region'] ?? null) !== 'HK'
            || ($payload['entityType'] ?? null) !== 'individual_stakeholder'
            || ($payload['kycMode'] ?? null) !== 'MANUAL_KYC'
            || array_key_exists('isResident', $payload)
            || ! is_string($payload['entityReferenceId'] ?? null)
            || trim($payload['entityReferenceId']) === '') {
            throw new RuntimeException('Invalid exact Nium HK generation #8 manual KYC stakeholder contract.');
        }

        $this->assertManualIdentityDocument($payload);
        $this->assertManualProofOfAddressDocument($payload);
    }

    private function assertManualIdentityDocument(array $payload): void
    {
        $documents = $payload['proofOfIdentityDocument'] ?? null;
        $document = is_array($documents) && array_is_list($documents) && count($documents) === 1
            ? $documents[0]
            : null;
        $this->assertProviderBackedDocument($document, 'proofOfIdentityDocument');
        $expiry = $this->exactDate($document['expiryDate'] ?? null);
        if (($document['type'] ?? null) !== 'passport'
            || ! is_string($document['identificationNumber'] ?? null)
            || trim($document['identificationNumber']) === ''
            || $expiry === null
            || ! $expiry->isFuture()
            || ($document['issuanceCountry'] ?? null) !== 'VN') {
            throw new RuntimeException('Invalid Nium HK manual passport identity document.');
        }
    }

    private function assertManualProofOfAddressDocument(array $payload): void
    {
        $document = $payload['proofOfAddressDocument'] ?? null;
        if (! is_array($document) || array_is_list($document)) {
            throw new RuntimeException('Nium HK manual KYC requires one provider-backed proofOfAddressDocument object.');
        }
        $this->assertProviderBackedDocument($document, 'proofOfAddressDocument');
    }

    private function assertProviderBackedDocument(mixed $document, string $field): void
    {
        if (! is_array($document)
            || ! is_string($document['type'] ?? null)
            || trim($document['type']) === ''
            || ! is_array($document['fileIds'] ?? null)
            || ! array_is_list($document['fileIds'])
            || count($document['fileIds']) !== 1
            || ! Str::isUuid($document['fileIds'][0] ?? null)) {
            throw new RuntimeException("Nium HK manual KYC requires one provider-backed {$field}.");
        }
    }

    private function exactDate(mixed $value): ?CarbonImmutable
    {
        try {
            $date = is_string($value) ? CarbonImmutable::createFromFormat('!Y-m-d', $value) : false;
        } catch (Throwable) {
            return null;
        }
        $errors = CarbonImmutable::getLastErrors();

        return $date !== false && $date->format('Y-m-d') === $value
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                ? $date
                : null;
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
