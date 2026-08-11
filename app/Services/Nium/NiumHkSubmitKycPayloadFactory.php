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
        $payload = [
            'region' => 'HK',
            'entityType' => $entityType,
            'isResident' => false,
            'entityReferenceId' => $referenceId,
            'kycMode' => 'biometric_kyc',
            'proofOfIdentityDocument' => [[
                'type' => 'passport',
                'identificationNumber' => $identity['identification_number'],
                'issuanceCountry' => 'VN',
                'expiryDate' => $identity['expiry_date'],
            ]],
        ];

        $this->validator->assert($payload);

        return $payload;
    }
}
