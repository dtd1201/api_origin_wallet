<?php

namespace App\Services\Nium;

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
}
