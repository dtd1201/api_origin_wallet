<?php

namespace App\Services\Nium;

use App\Models\KycDocument;
use App\Models\KycRelatedPerson;

final class NiumHkSubmitKycPayloadFactory
{
    public function __construct(
        private readonly NiumHkKycIdentityResolver $identityResolver,
        private readonly NiumHkSubmitKycValidator $validator,
    ) {}

    public function build(KycRelatedPerson $person, string $entityType, string $referenceId): array
    {
        $identity = $this->identityResolver->resolve($person);
        if ($entityType === 'individual_stakeholder') {
            if ($identity['is_resident'] === true) {
                throw new \RuntimeException('HK-resident stakeholder manual KYC requires an approved national ID document.');
            }
            $payload = [
                'region' => 'HK', 'entityType' => $entityType, 'entityReferenceId' => $referenceId,
                'kycMode' => 'manual_kyc',
                'proofOfIdentityDocument' => [[
                    'type' => $identity['type'], 'identificationNumber' => $identity['identification_number'],
                    'issuanceCountry' => $identity['issuance_country'], 'expiryDate' => $identity['expiry_date'],
                    'fileIds' => [$identity['file_id']],
                ]],
            ];
            $this->validator->assertManualStakeholder($payload);

            return $payload;
        }
        $payload = [
            'region' => 'HK',
            'entityType' => $entityType,
            'isResident' => $identity['is_resident'],
            'entityReferenceId' => $referenceId,
            'kycMode' => 'biometric_kyc',
            'proofOfIdentityDocument' => [[
                'type' => $identity['type'],
                'identificationNumber' => $identity['identification_number'],
                'issuanceCountry' => $identity['issuance_country'],
                'expiryDate' => $identity['expiry_date'],
            ]],
        ];

        $this->validator->assert($payload);

        return $payload;
    }

    public function buildApplicantManual(
        KycDocument $document,
        string $referenceId,
        bool $isResident,
        ?array $proofOfAddressDocument = null,
    ): array {
        $metadata = (array) $document->metadata;
        $payload = [
            'region' => 'HK',
            'entityType' => 'applicant',
            'entityReferenceId' => $referenceId,
            'kycMode' => 'manual_kyc',
            'isResident' => $isResident,
            'proofOfIdentityDocument' => [[
                'type' => 'passport',
                'identificationNumber' => (string) $document->document_number,
                'issuanceCountry' => strtoupper((string) $document->issuing_country_code),
                'expiryDate' => $document->expires_at?->format('Y-m-d'),
                'fileIds' => [(string) ($metadata['nium_file_id'] ?? '')],
            ]],
        ];
        if ($proofOfAddressDocument !== null) {
            $payload['proofOfAddressDocument'] = $proofOfAddressDocument;
        }

        $this->validator->assertManualApplicant($payload);

        return $payload;
    }
}
