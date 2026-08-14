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

    public function buildManual(
        KycRelatedPerson $person,
        string $referenceId,
        KycDocument $identity,
        ?KycDocument $proofOfAddress,
    ): array {
        if ((int) $identity->kyc_related_person_id !== (int) $person->id
            || (int) $identity->kyc_profile_id !== (int) $person->kyc_profile_id
            || ($proofOfAddress !== null
                && ((int) $proofOfAddress->kyc_related_person_id !== (int) $person->id
                    || (int) $proofOfAddress->kyc_profile_id !== (int) $person->kyc_profile_id))) {
            throw new \RuntimeException('Manual KYC documents do not belong to the locked stakeholder.');
        }

        $factualIdentity = $this->identityResolver->resolve($person);
        $payload = [
            'entityReferenceId' => $referenceId,
            'entityType' => 'INDIVIDUAL_STAKEHOLDER',
            'kycMode' => 'MANUAL_KYC',
            'region' => 'HK',
            'proofOfIdentityDocument' => [$this->manualIdentityDocument($identity, $factualIdentity)],
        ];
        if ($proofOfAddress !== null) {
            $payload['proofOfAddressDocument'] = $this->manualDocument($proofOfAddress, 'proof_of_address');
        }

        $this->validator->assertManual($payload);

        return $payload;
    }

    public function buildManualGenerationFive(
        KycRelatedPerson $person,
        string $referenceId,
        KycDocument $identity,
        ?KycDocument $proofOfAddress,
    ): array {
        $payload = $this->buildManual($person, $referenceId, $identity, $proofOfAddress);
        $payload['entityType'] = 'individual_stakeholder';
        $payload['kycMode'] = 'manual_kyc';

        $this->validator->assertManualGenerationFive($payload);

        return $payload;
    }

    public function buildManualGenerationSix(
        KycRelatedPerson $person,
        string $referenceId,
        KycDocument $identity,
        ?KycDocument $proofOfAddress,
    ): array {
        $payload = $this->buildManualGenerationFive($person, $referenceId, $identity, $proofOfAddress);
        $payload['isResident'] = false;

        $this->validator->assertManualGenerationSix($payload);

        return $payload;
    }

    public function buildManualGenerationSeven(
        KycRelatedPerson $person,
        string $referenceId,
        KycDocument $identity,
        ?KycDocument $proofOfAddress,
    ): array {
        $payload = $this->buildManual($person, $referenceId, $identity, $proofOfAddress);
        $payload['kycMode'] = 'manual_kyc';

        $this->validator->assertManualGenerationSeven($payload);

        return $payload;
    }

    public function buildManualGenerationEight(
        KycRelatedPerson $person,
        string $referenceId,
        KycDocument $identity,
        ?KycDocument $proofOfAddress,
    ): array {
        $payload = $this->buildManual($person, $referenceId, $identity, $proofOfAddress);
        $payload['entityType'] = 'individual_stakeholder';

        $this->validator->assertManualGenerationEight($payload);

        return $payload;
    }

    private function manualDocument(KycDocument $document, string $defaultType): array
    {
        $metadata = (array) $document->metadata;

        return [
            'type' => $metadata['nium_document_type'] ?? $defaultType,
            'fileIds' => [$metadata['nium_file_id']],
        ];
    }

    private function manualIdentityDocument(KycDocument $document, array $identity): array
    {
        return [
            ...$this->manualDocument($document, 'passport'),
            'identificationNumber' => $identity['identification_number'],
            'expiryDate' => $identity['expiry_date'],
            'issuanceCountry' => $identity['issuance_country'],
        ];
    }
}
