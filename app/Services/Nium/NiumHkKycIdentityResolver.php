<?php

namespace App\Services\Nium;

use App\Models\KycRelatedPerson;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

final class NiumHkKycIdentityResolver
{
    private const APPROVED_SOURCE = 'operator_verified_factual_identity_v1';

    public function __construct(private readonly NiumCustomerDocumentResolver $documentResolver) {}

    public function resolve(KycRelatedPerson $person): array
    {
        $documents = $this->documentResolver->relatedPersonDocuments($person)
            ->filter(function ($document): bool {
                $type = strtolower(trim((string) (((array) $document->metadata)['nium_document_type'] ?? $document->type)));
                return in_array($type, ['passport', 'passport_front'], true);
            });
        $document = $documents->sortByDesc('id')->first();
        $metadata = $person->metadata;
        $identity = is_array($metadata) ? ($metadata['nium_biometric_identity'] ?? null) : null;

        if ($document !== null) {
            $resolved = $this->fromDocument($document, $person);
            if (is_array($identity) && ! array_is_list($identity) && $this->materiallyConflicts($resolved, $identity)) {
                throw new RuntimeException('Approved biometric identity document conflicts with factual identity metadata.');
            }
            return $resolved;
        }

        if (! is_array($identity) || array_is_list($identity)) {
            throw new RuntimeException('An approved passport document is required for HK biometric KYC.');
        }

        $number = $identity['identification_number'] ?? null;
        $expiryValue = $identity['expiry_date'] ?? null;
        $expiry = $this->exactDate($expiryValue);

        $type = strtolower(trim((string) ($identity['type'] ?? '')));
        $issuanceCountry = strtoupper(trim((string) ($identity['issuance_country'] ?? '')));
        $residenceCountry = strtoupper(trim((string) $person->residence_country_code));

        if ($type !== 'passport'
            || ! is_string($number)
            || trim($number) === ''
            || preg_match('/^[A-Z]{2}$/', $issuanceCountry) !== 1
            || preg_match('/^[A-Z]{2}$/', $residenceCountry) !== 1
            || $expiry === null
            || ! $expiry->isFuture()
            || ($identity['factual'] ?? null) !== true
            || ($identity['synthetic'] ?? null) !== false
            || ($identity['source'] ?? null) !== self::APPROVED_SOURCE) {
            throw new RuntimeException('Approved factual biometric identity metadata is invalid.');
        }

        return [
            'type' => $type,
            'identification_number' => $number,
            'issuance_country' => $issuanceCountry,
            'expiry_date' => $expiryValue,
            'is_resident' => $residenceCountry === 'HK',
        ];
    }

    private function fromDocument($document, KycRelatedPerson $person): array
    {
        $type = strtolower(trim((string) ((((array) $document->metadata)['nium_document_type'] ?? $document->type))));
        $number = trim((string) $document->document_number);
        $country = strtoupper(trim((string) $document->issuing_country_code));
        $residence = strtoupper(trim((string) $person->residence_country_code));
        if (! in_array($type, ['passport', 'passport_front'], true)
            || $number === ''
            || preg_match('/^[A-Z]{2}$/', $country) !== 1
            || preg_match('/^[A-Z]{2}$/', $residence) !== 1
            || $document->expires_at === null
            || ! $document->expires_at->isFuture()) {
            throw new RuntimeException('Approved passport identity document is invalid for HK biometric KYC.');
        }
        if ($residence === 'HK') {
            throw new RuntimeException('HK-resident biometric KYC requires an approved national ID document.');
        }
        return ['type' => 'passport', 'identification_number' => $number, 'issuance_country' => $country,
            'expiry_date' => $document->expires_at->toDateString(), 'is_resident' => $residence === 'HK'];
    }

    private function materiallyConflicts(array $resolved, array $legacy): bool
    {
        return (isset($legacy['identification_number']) && (string) $legacy['identification_number'] !== $resolved['identification_number'])
            || (isset($legacy['issuance_country']) && strtoupper((string) $legacy['issuance_country']) !== $resolved['issuance_country'])
            || (isset($legacy['expiry_date']) && (string) $legacy['expiry_date'] !== $resolved['expiry_date']);
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
