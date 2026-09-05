<?php

namespace App\Services\Nium;

use App\Models\KycDocument;
use App\Models\KycProfile;
use App\Models\KycRelatedPerson;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

class NiumCustomerPayloadFactory
{
    private const HK_CORPORATE_V5_BUSINESS_TYPES = [
        'PRIVATE_COMPANY' => 'private_company',
    ];

    private const SG_CORPORATE_ADDRESS_RELATIONSHIP_INVALID = 'sg_corporate_address_relationship_invalid';

    private const SG_CORPORATE_BUSINESS_ADDRESS_INVALID = 'sg_corporate_business_address_invalid';

    private const SG_CORPORATE_BUSINESS_ADDRESS_CONFLICT = 'sg_corporate_business_address_conflict';

    private const SG_CORPORATE_BUSINESS_ADDRESS_KEYS = [
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'country_code',
    ];

    public function __construct(
        private readonly NiumCustomerDocumentResolver $documentResolver,
        private readonly NiumSgCorporateClientPolicy $sgCorporateClientPolicy,
        private readonly NiumRegionResolver $regionResolver,
        private readonly NiumHkCorporateV5Validator $hkCorporateV5Validator,
        private readonly NiumKycDataValidator $kycDataValidator,
    ) {}

    /**
     * Build a Customer Onboarding V5 request from the internally approved KYC record.
     * Provider identifiers and lifecycle fields are deliberately never accepted here.
     */
    public function build(User $user, string $externalReference): array
    {
        $user->loadMissing(['kycProfile.documents', 'kycProfile.relatedPersons.documents']);
        $profile = $this->approvedProfile($user);

        $metadata = (array) ($profile->metadata ?? []);
        $region = $this->regionResolver->resolve(
            $metadata['nium_region'] ?? null,
            $profile->registered_country_code,
            $profile->residence_country_code,
            $profile->country_code,
        );
        $kycType = strtolower((string) ($metadata['nium_kyc_type'] ?? 'minimum'));
        $this->validateRequiredSourceDataFor($user, $profile, $region, $kycType);

        $payload = $profile->applicant_type === 'business'
            ? $this->corporatePayload($user, $profile, $externalReference, $region, $kycType)
            : $this->individualPayload($user, $profile, $externalReference, $region, $kycType);

        $payload = $this->withoutProviderControlledFields($payload);
        $this->kycDataValidator->assertPayload($payload);

        return $payload;
    }

    /**
     * Validate required approved source data before document or customer API calls.
     */
    public function validateRequiredSourceData(User $user): void
    {
        $user->loadMissing(['kycProfile.documents', 'kycProfile.relatedPersons.documents']);
        $profile = $this->approvedProfile($user);
        $metadata = (array) ($profile->metadata ?? []);
        $region = $this->regionResolver->resolve(
            $metadata['nium_region'] ?? null,
            $profile->registered_country_code,
            $profile->residence_country_code,
            $profile->country_code,
        );
        $kycType = strtolower((string) ($metadata['nium_kyc_type'] ?? 'minimum'));

        $this->validateRequiredSourceDataFor($user, $profile, $region, $kycType);
    }

    private function individualPayload(
        User $user,
        KycProfile $profile,
        string $externalReference,
        string $region,
        string $kycType,
    ): array {
        [$firstName, $lastName] = $this->splitName($profile->legal_name ?: $user->full_name);
        [$mobileCountryCode, $mobile] = $this->mobileParts((string) $user->phone, $profile);
        $regionFields = $this->regionFields($profile, $region);

        $payload = $this->filter(array_merge($regionFields, [
            'type' => 'individual',
            'region' => $region,
            'kycType' => $kycType,
            'externalId' => $externalReference,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'dateOfBirth' => $profile->date_of_birth?->toDateString(),
            'email' => $this->email($user->email, 'nium_v5_fields.customer.email'),
            'mobileCountryCode' => $mobileCountryCode,
            'mobile' => $mobile,
            'nationality' => strtoupper((string) ($profile->nationality_country_code ?: $profile->country_code)),
            'billingAddress' => $this->address($profile),
            'documents' => $this->documents($this->documentResolver->profileDocuments($profile)),
        ]));

        if ($region === 'UK' && $kycType === 'minimum') {
            $this->requireFields($payload, [
                'annualIncome',
                'expectedAccountUsage',
                'incomeSourceType',
                'natureOfBusiness',
            ], 'UK individual minimum KYC');
        }

        return $payload;
    }

    private function corporatePayload(
        User $user,
        KycProfile $profile,
        string $externalReference,
        string $region,
        string $kycType,
    ): array {
        $metadata = (array) ($profile->metadata ?? []);
        $applicant = $this->corporateApplicant($profile);

        $registeredDate = $metadata['registered_date'] ?? $metadata['business_registered_date'] ?? null;
        $businessType = $metadata['nium_business_type'] ?? $metadata['business_type'] ?? null;

        if (! filled($registeredDate) || ! filled($businessType)) {
            throw new RuntimeException('Corporate Nium onboarding requires registered_date and nium_business_type in the approved KYC metadata.');
        }

        if ($region === 'HK' && $kycType === 'full') {
            $businessType = $this->hkCorporateV5BusinessType($businessType);
        }

        $registeredAddress = $this->address($profile);
        $businessAddress = $registeredAddress;
        $addressRelationship = null;
        $hkApplicantPositions = null;

        if ($region === 'SG') {
            [$addressRelationship, $businessAddress] = $this->sgCorporateAddressSources($profile);
        } elseif ($region === 'HK' && $kycType === 'full') {
            [$addressRelationship, $businessAddress] = $this->hkCorporateAddressSources($profile);
            $hkApplicantPositions = $this->hkApplicantPositions($applicant);
        }

        $applicantPayload = $this->person(
            $applicant,
            $user->email,
            (string) $user->phone,
            $hkApplicantPositions === null ? ['director'] : [],
            'nium_v5_fields.applicant.email',
            true,
            normalizeNiumPositions: $region === 'SG',
            positionsOverride: $hkApplicantPositions,
        );

        $payload = $this->filter(array_merge($this->regionFields($profile, $region), [
            'type' => 'corporate',
            'region' => $region,
            'kycType' => $kycType,
            'externalId' => $externalReference,
            'businessName' => $profile->business_name,
            'businessRegistrationNumber' => $profile->business_registration_number,
            'businessType' => $businessType,
            'registeredCountry' => strtoupper((string) $profile->registered_country_code),
            'registeredDate' => $registeredDate,
            'website' => filled($metadata['business_website'] ?? null)
                ? $metadata['business_website']
                : Arr::get($metadata, 'nium_v5_fields.website'),
            'addresses' => $this->filter([
                'isBusinessAddressSameAsRegisteredAddress' => $addressRelationship,
                'businessAddress' => [
                    'addressLine1' => $profile->address_line1,
                    'city' => $profile->city,
                    'state' => $profile->state,
                    'postcode' => $profile->postal_code,
                    'country' => $profile->country_code,
                ],
                'registeredAddress' => [
                    'addressLine1' => $profile->address_line1,
                    'city' => $profile->city,
                    'state' => $profile->state,
                    'postcode' => $profile->postal_code,
                    'country' => $profile->country_code,
                ],
            ]),
            'applicant' => $applicantPayload,
            'stakeholders' => $this->stakeholders($profile, $applicant, $region === 'SG'),
            'documents' => $this->corporateDocuments($profile),
        ]));

        if ($region === 'SG') {
            $payload['tradeName'] = $this->requiredSgCorporateString($profile, 'tradeName');
            $payload = $this->normalizeSgCorporateCountryLists($payload);
        }

        if ($region === 'HK' && $kycType === 'full') {
            $payload['applicantDeclarationTimeStamp'] = $payload['applicantDeclarationTimestamp'] ?? null;
            unset($payload['applicantDeclarationTimestamp']);
            $payload['tradeName'] = $this->requiredHkCorporateTradeName($profile);
            $this->hkCorporateV5Validator->assert($profile, $payload);
        }

        if (in_array($region, ['UK', 'EU'], true) && $kycType === 'minimum') {
            $this->requireFields($payload, [
                'expectedAccountUsage',
                'natureOfBusiness',
                'sizeOfBusiness',
            ], "{$region} corporate minimum KYC");
        }

        return $payload;
    }

    private function hkCorporateV5BusinessType(mixed $businessType): string
    {
        if (! is_string($businessType) || ! array_key_exists($businessType, self::HK_CORPORATE_V5_BUSINESS_TYPES)) {
            throw new RuntimeException('Unsupported Nium HK Corporate Full V5 business type.');
        }

        return self::HK_CORPORATE_V5_BUSINESS_TYPES[$businessType];
    }

    private function person(
        KycRelatedPerson $person,
        ?string $fallbackEmail,
        string $fallbackPhone,
        array $fallbackPositions,
        string $emailPath,
        bool $emailRequired,
        bool $normalizeNiumPositions = false,
        ?array $positionsOverride = null,
    ): array {
        [$firstName, $lastName] = $this->splitName($person->legal_name);
        $metadata = (array) ($person->metadata ?? []);
        [$mobileCountryCode, $mobile] = $this->mobileParts(
            (string) ($metadata['phone'] ?? $fallbackPhone),
            $person,
        );

        return $this->filter([
            'externalId' => 'origin-wallet-person-'.$person->id,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'dateOfBirth' => $person->date_of_birth?->toDateString(),
            'email' => $this->email($metadata['email'] ?? $fallbackEmail, $emailPath, $emailRequired),
            'mobileCountryCode' => $mobileCountryCode,
            'mobile' => $mobile,
            'nationality' => strtoupper((string) ($person->nationality_country_code ?: $person->country_code)),
            'address' => $this->address($person),
            'positions' => $this->positions(
                $positionsOverride ?? (array) ($metadata['positions'] ?? $fallbackPositions),
                $normalizeNiumPositions,
            ),
            'sharePercentage' => $person->ownership_percentage !== null
                ? (float) $person->ownership_percentage
                : null,
            'documents' => $this->documents($this->documentResolver->relatedPersonDocuments($person)),
        ]);
    }

    private function stakeholders(
        KycProfile $profile,
        KycRelatedPerson $applicant,
        bool $normalizeNiumPositions = false,
    ): array {
        $individuals = $profile->relatedPersons
            ->reject(fn (KycRelatedPerson $person) => $person->is($applicant))
            ->map(function (KycRelatedPerson $person) use ($normalizeNiumPositions): array {
                return $this->person(
                    $person,
                    null,
                    '',
                    [$this->stakeholderFallbackPosition($person)],
                    'nium_v5_fields.stakeholders.individual[*].email',
                    false,
                    $normalizeNiumPositions,
                );
            })
            ->values()
            ->all();

        return $individuals === [] ? [] : ['individual' => $individuals];
    }

    private function documents(iterable $documents): array
    {
        return collect($documents)
            ->map(function (KycDocument $document): array {
                $metadata = (array) ($document->metadata ?? []);
                $fileId = is_string($metadata['nium_file_id'] ?? null)
                    ? trim($metadata['nium_file_id'])
                    : '';
                $state = strtoupper(trim((string) ($metadata['nium_file_state'] ?? '')));

                if ($fileId === '' || ! Str::isUuid($fileId) || $state !== 'AVAILABLE') {
                    throw new RuntimeException(
                        "KYC document [{$document->getKey()}] is not ready for Nium customer onboarding.",
                    );
                }

                $type = strtolower((string) $document->type);
                $type = match (true) {
                    str_contains($type, 'passport') => 'passport',
                    str_contains($type, 'national_id') => 'national_id',
                    str_contains($type, 'driver') => 'drivers_licence',
                    $type === 'business_registration' || $type === 'certificate_of_incorporation' => 'business_registration_doc',
                    default => $type,
                };

                return $this->filter([
                    'type' => $metadata['nium_document_type'] ?? $type,
                    'identificationNumber' => $document->document_number,
                    'issuanceCountry' => strtoupper((string) $document->issuing_country_code),
                    'expiryDate' => $document->expires_at?->toDateString(),
                    'fileIds' => [$fileId],
                ]);
            })
            ->filter(fn (array $document) => filled($document['identificationNumber'] ?? null) || filled($document['fileIds'] ?? null))
            ->unique(fn (array $document) => ($document['type'] ?? '').'|'.($document['identificationNumber'] ?? '').'|'.json_encode($document['fileIds'] ?? []))
            ->values()
            ->all();
    }

    private function corporateDocuments(KycProfile $profile): array
    {
        return $this->documents(
            $this->documentResolver->profileDocuments($profile)
                ->reject(fn (KycDocument $document): bool => strtolower((string) $document->type) === 'proof_of_business_address'),
        );
    }

    private function address(object $subject): array
    {
        return $this->providerAddress([
            'address_line1' => $subject->address_line1,
            'address_line2' => $subject->address_line2,
            'city' => $subject->city,
            'state' => $subject->state,
            'postal_code' => $subject->postal_code,
            'country_code' => $subject->country_code,
        ]);
    }

    /**
     * @return array{0: bool, 1: array<string, mixed>}
     */
    private function sgCorporateAddressSources(KycProfile $profile): array
    {
        $fields = Arr::get((array) $profile->metadata, 'nium_v5_fields');
        $addresses = is_array($fields) ? ($fields['addresses'] ?? null) : null;

        if (
            ! is_array($addresses)
            || array_is_list($addresses)
            || ! array_key_exists('isBusinessAddressSameAsRegisteredAddress', $addresses)
            || ! is_bool($addresses['isBusinessAddressSameAsRegisteredAddress'])
        ) {
            throw new RuntimeException(self::SG_CORPORATE_ADDRESS_RELATIONSHIP_INVALID);
        }

        $relationship = $addresses['isBusinessAddressSameAsRegisteredAddress'];

        if ($relationship) {
            if (array_key_exists('businessAddress', $addresses)) {
                $this->validateEmptySgCorporateBusinessAddress($addresses['businessAddress']);
            }

            return [$relationship, $this->address($profile)];
        }

        if (! array_key_exists('businessAddress', $addresses)) {
            throw new RuntimeException(self::SG_CORPORATE_BUSINESS_ADDRESS_INVALID);
        }

        return [$relationship, $this->businessAddress($addresses['businessAddress'])];
    }

    /** @return array{0: bool, 1: ?array<string, mixed>} */
    private function hkCorporateAddressSources(KycProfile $profile): array
    {
        $addresses = Arr::get((array) $profile->metadata, 'nium_v5_fields.addresses');

        if (! is_array($addresses) || array_is_list($addresses) || ! is_bool($addresses['isBusinessAddressSameAsRegisteredAddress'] ?? null)) {
            throw new RuntimeException('hk_corporate_address_relationship_invalid');
        }

        if ($addresses['isBusinessAddressSameAsRegisteredAddress']) {
            return [true, $this->address($profile)];
        }

        if (! array_key_exists('businessAddress', $addresses)) {
            throw new RuntimeException('hk_corporate_business_address_invalid');
        }

        try {
            return [false, $this->businessAddress($addresses['businessAddress'])];
        } catch (RuntimeException) {
            throw new RuntimeException('hk_corporate_business_address_invalid');
        }
    }

    private function requiredHkCorporateTradeName(KycProfile $profile): string
    {
        $tradeName = Arr::get((array) $profile->metadata, 'nium_v5_fields.tradeName');

        if ($tradeName === null || (is_string($tradeName) && trim($tradeName) === '')) {
            $tradeName = $profile->business_name;
        }

        if (! is_string($tradeName) || trim($tradeName) === '') {
            throw new RuntimeException('Nium HK Corporate Full requires tradeName or businessName as a non-empty string.');
        }

        return trim($tradeName);
    }

    /** @return list<string> */
    private function hkApplicantPositions(KycRelatedPerson $applicant): array
    {
        $metadata = (array) ($applicant->metadata ?? []);
        $positions = $metadata['positions'] ?? null;

        if (! is_array($positions) || $positions === [] || ! array_is_list($positions)) {
            throw new RuntimeException('Nium HK Corporate Full requires approved applicant metadata.positions as a non-empty array.');
        }

        $normalized = [];

        foreach ($positions as $position) {
            if (! is_string($position) || trim($position) === '') {
                throw new RuntimeException('Nium HK Corporate Full requires approved applicant metadata.positions as non-empty strings.');
            }

            $position = trim($position);

            if (! in_array($position, $normalized, true)) {
                $normalized[] = $position;
            }
        }

        return $normalized;
    }

    private function validateEmptySgCorporateBusinessAddress(mixed $source): void
    {
        if ($source === null || $source === []) {
            return;
        }

        if (! is_array($source) || array_is_list($source)) {
            throw new RuntimeException(self::SG_CORPORATE_BUSINESS_ADDRESS_CONFLICT);
        }

        if (array_diff(array_keys($source), self::SG_CORPORATE_BUSINESS_ADDRESS_KEYS) !== []) {
            throw new RuntimeException(self::SG_CORPORATE_BUSINESS_ADDRESS_CONFLICT);
        }

        if (
            array_keys($source) === ['address_line2']
            && (
                $source['address_line2'] === null
                || (
                    is_string($source['address_line2'])
                    && trim($source['address_line2']) === ''
                )
            )
        ) {
            return;
        }

        throw new RuntimeException(self::SG_CORPORATE_BUSINESS_ADDRESS_CONFLICT);
    }

    private function businessAddress(mixed $source): array
    {
        if (! is_array($source) || $source === [] || array_is_list($source)) {
            throw new RuntimeException(self::SG_CORPORATE_BUSINESS_ADDRESS_INVALID);
        }

        if (array_diff(array_keys($source), self::SG_CORPORATE_BUSINESS_ADDRESS_KEYS) !== []) {
            throw new RuntimeException(self::SG_CORPORATE_BUSINESS_ADDRESS_INVALID);
        }

        $selected = Arr::only($source, self::SG_CORPORATE_BUSINESS_ADDRESS_KEYS);

        foreach (['address_line1', 'city', 'state', 'postal_code', 'country_code'] as $field) {
            if (! is_string($selected[$field] ?? null) || trim($selected[$field]) === '') {
                throw new RuntimeException(self::SG_CORPORATE_BUSINESS_ADDRESS_INVALID);
            }
        }

        if (
            array_key_exists('address_line2', $selected)
            && $selected['address_line2'] !== null
            && ! is_string($selected['address_line2'])
        ) {
            throw new RuntimeException(self::SG_CORPORATE_BUSINESS_ADDRESS_INVALID);
        }

        $maximumLengths = [
            'address_line1' => 255,
            'address_line2' => 255,
            'city' => 100,
            'state' => 100,
            'postal_code' => 30,
        ];

        foreach ($maximumLengths as $field => $maximumLength) {
            if (
                is_string($selected[$field] ?? null)
                && mb_strlen(trim($selected[$field])) > $maximumLength
            ) {
                throw new RuntimeException(self::SG_CORPORATE_BUSINESS_ADDRESS_INVALID);
            }
        }

        $selected = array_map(
            static fn (mixed $value): mixed => is_string($value) ? trim($value) : $value,
            $selected,
        );
        $selected['country_code'] = strtoupper($selected['country_code']);

        if (preg_match('/^[A-Z]{2}$/', $selected['country_code']) !== 1) {
            throw new RuntimeException(self::SG_CORPORATE_BUSINESS_ADDRESS_INVALID);
        }

        return $this->providerAddress($selected);
    }

    private function providerAddress(array $source): array
    {
        return $this->filter([
            'addressLine1' => $source['address_line1'] ?? null,
            'addressLine2' => $source['address_line2'] ?? null,
            'city' => $source['city'] ?? null,
            'state' => $source['state'] ?? null,
            'postcode' => $source['postal_code'] ?? null,
            'country' => strtoupper((string) ($source['country_code'] ?? '')),
        ]);
    }

    private function mobileParts(string $phone, object $subject): array
    {
        $metadata = (array) ($subject->metadata ?? []);
        $country = strtoupper((string) ($subject->country_code ?? $subject->residence_country_code ?? ''));
        $defaultCallingCodes = [
            'AU' => '61', 'BR' => '55', 'CA' => '1', 'DE' => '49', 'ES' => '34',
            'FR' => '33', 'GB' => '44', 'HK' => '852', 'ID' => '62', 'IT' => '39',
            'JP' => '81', 'MX' => '52', 'NL' => '31', 'NZ' => '64', 'SG' => '65',
            'US' => '1', 'VN' => '84',
        ];
        $countryCode = preg_replace(
            '/\D+/',
            '',
            (string) ($metadata['mobile_country_code'] ?? $defaultCallingCodes[$country] ?? ''),
        ) ?: '';
        $digits = preg_replace('/\D+/', '', $phone) ?: '';

        if ($countryCode !== '' && str_starts_with($digits, $countryCode)) {
            $digits = substr($digits, strlen($countryCode));
        }

        return [$countryCode !== '' ? $countryCode : null, $digits !== '' ? $digits : null];
    }

    private function splitName(?string $name): array
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];

        if (count($parts) < 2) {
            throw new RuntimeException('Nium onboarding requires both first and last name.');
        }

        $lastName = (string) array_pop($parts);

        return [implode(' ', $parts), $lastName];
    }

    private function withoutProviderControlledFields(array $payload): array
    {
        foreach (['customerHashId', 'walletHashId', 'walletHashIds', 'status', 'subStatus', 'complianceStatus'] as $field) {
            unset($payload[$field]);
        }

        return $payload;
    }

    private function approvedProfile(User $user): KycProfile
    {
        $profile = $user->kycProfile;

        if ($profile === null || ! in_array(strtolower((string) $profile->status), ['approved', 'verified'], true)) {
            throw new RuntimeException('An approved internal KYC/KYB profile is required for Nium onboarding.');
        }

        return $profile;
    }

    private function validateRequiredSourceDataFor(
        User $user,
        KycProfile $profile,
        string $region,
        string $kycType,
    ): void {
        if ($profile->applicant_type === 'business') {
            $applicant = $this->corporateApplicant($profile);
            $applicantMetadata = (array) ($applicant->metadata ?? []);
            $this->email(
                $applicantMetadata['email'] ?? $user->email,
                'nium_v5_fields.applicant.email',
            );

            foreach ($profile->relatedPersons->reject(fn (KycRelatedPerson $person) => $person->is($applicant)) as $stakeholder) {
                $stakeholderMetadata = (array) ($stakeholder->metadata ?? []);
                $this->email(
                    $stakeholderMetadata['email'] ?? null,
                    'nium_v5_fields.stakeholders.individual[*].email',
                    false,
                );
            }
        } else {
            $this->email($user->email, 'nium_v5_fields.customer.email');
        }

        if ($profile->applicant_type === 'business' && $region === 'HK') {
            if ($kycType !== 'full') {
                throw new RuntimeException('Nium HK corporate onboarding requires approved KYC metadata field nium_kyc_type to be full.');
            }

            $this->hkCorporateAddressSources($profile);
            $this->validateHkCorporateMetadata($profile);
            $this->validateHkCorporateDocumentSources($profile);

            return;
        }

        if ($profile->applicant_type !== 'business' || $region !== 'SG') {
            return;
        }

        if ($kycType !== 'full') {
            throw new RuntimeException(
                'Nium SG corporate onboarding requires approved KYC metadata field nium_kyc_type to be full.',
            );
        }

        $fields = Arr::get((array) $profile->metadata, 'nium_v5_fields', []);

        if (! is_array($fields) || array_is_list($fields)) {
            throw new RuntimeException('Approved KYC metadata nium_v5_fields must be an object.');
        }

        $this->requiredSgCorporateString($profile, 'tradeName');
        $this->sgCorporateAddressSources($profile);

        if (($fields['applicantDeclaration'] ?? null) !== true) {
            throw new RuntimeException(
                'Nium SG corporate full KYC requires approved KYC metadata field '
                .'nium_v5_fields.applicantDeclaration as boolean true.',
            );
        }

        if (! $this->isNiumDateTime($fields['applicantDeclarationTimeStamp'] ?? null)) {
            throw new RuntimeException(
                'Nium SG corporate full KYC requires approved KYC metadata field '
                .'nium_v5_fields.applicantDeclarationTimeStamp in YYYY-MM-DD HH:MM:SS format.',
            );
        }

        if (! is_bool($fields['isMultiLayeredCompany'] ?? null)) {
            throw new RuntimeException(
                'Nium SG corporate full KYC requires approved KYC metadata field '
                .'nium_v5_fields.isMultiLayeredCompany as a boolean.',
            );
        }

        $clientRequirements = $this->sgCorporateClientPolicy->requirements();
        $natureOfBusiness = $this->requiredSgCorporateObject($profile, 'natureOfBusiness');
        $industryCodes = $natureOfBusiness['industryCodes'] ?? null;

        if (! $this->isNonEmptyStringList($industryCodes)) {
            throw new RuntimeException(
                'Nium SG corporate full KYC requires approved KYC metadata field '
                .'nium_v5_fields.natureOfBusiness.industryCodes as a non-empty array of Nium Corporate Constants.',
            );
        }

        $this->normalizeIsoCountryList(
            $natureOfBusiness['operatingCountries'] ?? null,
            'nium_v5_fields.natureOfBusiness.operatingCountries',
        );

        $expectedAccountUsage = $this->requiredSgCorporateObject($profile, 'expectedAccountUsage');
        $intendedUses = $expectedAccountUsage['intendedUses'] ?? null;

        if (! $this->isNonEmptyStringList($intendedUses)) {
            throw new RuntimeException(
                'Nium SG corporate full KYC requires approved KYC metadata field '
                .'nium_v5_fields.expectedAccountUsage.intendedUses as a non-empty array of Nium Corporate Constants.',
            );
        }

        foreach (['credit', 'debit'] as $direction) {
            $usage = $expectedAccountUsage[$direction] ?? null;

            if (! is_array($usage) || $usage === [] || array_is_list($usage)) {
                throw new RuntimeException(
                    'Nium SG corporate full KYC requires approved KYC metadata field '
                    ."nium_v5_fields.expectedAccountUsage.{$direction} as an object.",
                );
            }

            foreach (['averageTransactionValue', 'monthlyTransactionVolume', 'monthlyTransactions'] as $field) {
                if (! is_string($usage[$field] ?? null) || trim($usage[$field]) === '') {
                    throw new RuntimeException(
                        'Nium SG corporate full KYC requires approved KYC metadata field '
                        ."nium_v5_fields.expectedAccountUsage.{$direction}.{$field} "
                        .'as a Nium Corporate Constant.',
                    );
                }
            }

            $this->normalizeIsoCountryList(
                $usage['topTransactionCountries'] ?? null,
                "nium_v5_fields.expectedAccountUsage.{$direction}.topTransactionCountries",
            );
        }

        $sizeOfBusiness = $this->requiredSgCorporateObject($profile, 'sizeOfBusiness');

        if (
            ! is_string($sizeOfBusiness['annualTurnover'] ?? null)
            || trim($sizeOfBusiness['annualTurnover']) === ''
        ) {
            throw new RuntimeException(
                'Nium SG corporate full KYC requires approved KYC metadata field '
                .'nium_v5_fields.sizeOfBusiness.annualTurnover as a Nium Corporate Constant.',
            );
        }

        if (
            ! is_string($sizeOfBusiness['totalEmployees'] ?? null)
            || trim($sizeOfBusiness['totalEmployees']) === ''
        ) {
            throw new RuntimeException(
                'Nium SG corporate full KYC requires approved KYC metadata field '
                .'nium_v5_fields.sizeOfBusiness.totalEmployees as a Nium Corporate Constant.',
            );
        }

        $bankAccountDetailsSupplied = array_key_exists('bankAccountDetails', $fields);

        if (
            $clientRequirements->requireBankAccountDetails
            || $clientRequirements->requireRoutingCodes
            || $bankAccountDetailsSupplied
        ) {
            $this->validateBankAccountDetails(
                $this->requiredSgCorporateObject($profile, 'bankAccountDetails'),
                $clientRequirements->requireRoutingCodes,
            );
        }

        if (
            $clientRequirements->requireDeviceDetails
            || array_key_exists('deviceDetails', $fields)
        ) {
            $this->validateDeviceDetails(
                $this->requiredSgCorporateObject($profile, 'deviceDetails'),
            );
        }

        $this->requireAddressState($profile, 'addresses.registeredAddress.state');

        $applicant = $this->corporateApplicant($profile);
        $this->requireAddressState($applicant, 'applicant.address.state');
        $this->positions(
            (array) (((array) $applicant->metadata)['positions'] ?? ['director']),
            true,
        );

        foreach ($profile->relatedPersons->reject(fn (KycRelatedPerson $person) => $person->is($applicant)) as $stakeholder) {
            if ($this->address($stakeholder) !== []) {
                $this->requireAddressState($stakeholder, 'stakeholders.individual[*].address.state');
            }

            $this->positions(
                (array) (((array) $stakeholder->metadata)['positions'] ?? [
                    $this->stakeholderFallbackPosition($stakeholder),
                ]),
                true,
            );
        }
    }

    private function email(mixed $value, string $path, bool $required = true): ?string
    {
        $email = is_string($value) ? trim($value) : '';

        if ($email === '' && ! $required) {
            return null;
        }

        $separator = strrpos($email, '@');
        $localPart = $separator === false ? '' : substr($email, 0, $separator);
        $domain = $separator === false ? '' : strtolower(substr($email, $separator + 1));

        if (
            $email === ''
            || strlen($email) > 254
            || strlen($localPart) > 64
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || $domain === 'example.invalid'
            || str_ends_with($domain, '.invalid')
        ) {
            throw new RuntimeException("Invalid Nium V5 email at {$path}.");
        }

        return $email;
    }

    private function corporateApplicant(KycProfile $profile): KycRelatedPerson
    {
        $applicant = $profile->relatedPersons->first(
            fn (KycRelatedPerson $person) => in_array(strtolower((string) $person->relationship_type), [
                'applicant', 'authorized_representative', 'authorised_representative',
            ], true)
        );

        if ($applicant === null) {
            throw new RuntimeException('A corporate Nium onboarding request requires an approved applicant or authorized representative.');
        }

        return $applicant;
    }

    private function requireAddressState(object $subject, string $path): void
    {
        if (! is_string($subject->state) || trim($subject->state) === '') {
            throw new RuntimeException(
                "Nium SG corporate full KYC requires approved internal address field {$path} as a string.",
            );
        }
    }

    private function requiredSgCorporateObject(KycProfile $profile, string $field): array
    {
        $value = Arr::get((array) $profile->metadata, "nium_v5_fields.{$field}");

        if (! is_array($value) || $value === [] || array_is_list($value)) {
            throw new RuntimeException(
                'Nium SG corporate full KYC requires approved KYC metadata field '
                ."nium_v5_fields.{$field} as an object.",
            );
        }

        return $value;
    }

    private function validateHkCorporateMetadata(KycProfile $profile): void
    {
        $fields = Arr::get((array) $profile->metadata, 'nium_v5_fields');

        if (! is_array($fields) || array_is_list($fields)) {
            throw new RuntimeException('Approved HK KYC metadata nium_v5_fields must be an object.');
        }

        $this->requiredHkCorporateTradeName($profile);

        if (($fields['applicantDeclaration'] ?? null) !== true) {
            throw new RuntimeException('Nium HK Corporate Full requires nium_v5_fields.applicantDeclaration as boolean true.');
        }

        $declarationTimestamp = array_key_exists('applicantDeclarationTimestamp', $fields)
            ? $fields['applicantDeclarationTimestamp']
            : ($fields['applicantDeclarationTimeStamp'] ?? null);

        if (! $this->isNiumDateTime($declarationTimestamp)) {
            throw new RuntimeException('Nium HK Corporate Full requires nium_v5_fields.applicantDeclarationTimestamp in YYYY-MM-DD HH:MM:SS format.');
        }

        if (! is_bool($fields['isMultiLayeredCompany'] ?? null)) {
            throw new RuntimeException('Nium HK Corporate Full requires nium_v5_fields.isMultiLayeredCompany as a boolean.');
        }

        foreach (['natureOfBusiness', 'expectedAccountUsage', 'sizeOfBusiness', 'bankAccountDetails', 'deviceDetails'] as $field) {
            if (! is_array($fields[$field] ?? null) || $fields[$field] === [] || array_is_list($fields[$field])) {
                throw new RuntimeException("Nium HK Corporate Full requires nium_v5_fields.{$field} as an object.");
            }
        }

        $deviceDetails = $fields['deviceDetails'];

        foreach (['ipCountryCode', 'deviceInfo', 'ipAddress', 'sessionId'] as $field) {
            if (! is_string($deviceDetails[$field] ?? null) || trim($deviceDetails[$field]) === '') {
                throw new RuntimeException("Nium HK Corporate Full requires nium_v5_fields.deviceDetails.{$field} as a non-empty string.");
            }
        }

        if (filter_var($deviceDetails['ipAddress'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException('Nium HK Corporate Full requires nium_v5_fields.deviceDetails.ipAddress as a valid IPv4 address.');
        }
    }

    private function validateHkCorporateDocumentSources(KycProfile $profile): void
    {
        $businessDocuments = $this->documentResolver->profileDocuments($profile);
        $types = $businessDocuments->map(fn (KycDocument $document): string => $this->documentType($document));
        $missing = [];

        if (! $types->intersect(['business_registration', 'business_registration_doc', 'certificate_of_incorporation'])->count()) {
            $missing[] = 'business_registration_doc';
        }

        $businessType = strtolower(trim((string) (Arr::get((array) $profile->metadata, 'nium_business_type') ?? Arr::get((array) $profile->metadata, 'business_type'))));

        if ($businessType === 'private_company' && $types->intersect(['nar1', 'nnc1'])->isEmpty()) {
            $missing[] = 'nar1_or_nnc1';
        }

        $website = Arr::get((array) $profile->metadata, 'business_website') ?? Arr::get((array) $profile->metadata, 'nium_v5_fields.website');

        if (! filled($website) && ! $types->contains('proof_of_business')) {
            $missing[] = 'proof_of_business';
        }

        if (Arr::get((array) $profile->metadata, 'nium_v5_fields.isMultiLayeredCompany') === true && $types->intersect(['corporate_structure', 'ownership_chart'])->isEmpty()) {
            $missing[] = 'corporate_structure';
        }

        $applicant = $this->corporateApplicant($profile);
        $positions = collect($this->hkApplicantPositions($applicant))
            ->map(fn (string $position): string => NiumHkCorporateV5Validator::documentRoleKey($position));
        $applicantDocumentTypes = $this->documentResolver->relatedPersonDocuments($applicant)
            ->map(fn (KycDocument $document): string => $this->documentType($document));

        if ($positions->intersect(['director', 'ubo', 'ultimate_beneficial_owner', 'partner'])->isEmpty() && ! $applicantDocumentTypes->contains('loa')) {
            $missing[] = 'loa';
        }

        if ($missing !== []) {
            throw new RuntimeException(NiumHkCorporateV5Validator::REQUIRED_DOCUMENT_MISSING.':'.implode(',', $missing));
        }

        $businessRegistration = $businessDocuments->first(fn (KycDocument $document): bool => in_array(
            $this->documentType($document),
            ['business_registration', 'business_registration_doc', 'certificate_of_incorporation'],
            true,
        ));

        if (
            ! $businessRegistration instanceof KycDocument
            || $businessRegistration->issued_at === null
            || $businessRegistration->issued_at->isFuture()
            || $businessRegistration->issued_at->lt(today()->subMonthsNoOverflow(12))
        ) {
            throw new RuntimeException(NiumHkCorporateV5Validator::DOCUMENT_RECENCY_UNPROVEN);
        }

        if ($businessType === 'private_company') {
            $filing = $businessDocuments->first(fn (KycDocument $document): bool => in_array($this->documentType($document), ['nar1', 'nnc1'], true));
            $filingMetadata = $filing instanceof KycDocument ? (array) $filing->metadata : [];

            if (($filingMetadata['is_most_recent_filing'] ?? null) !== true) {
                throw new RuntimeException(NiumHkCorporateV5Validator::LATEST_FILING_UNPROVEN);
            }
        }
    }

    private function documentType(KycDocument $document): string
    {
        $metadata = (array) ($document->metadata ?? []);

        return strtolower(trim((string) ($metadata['nium_document_type'] ?? $document->type)));
    }

    private function requiredSgCorporateString(KycProfile $profile, string $field): string
    {
        $value = Arr::get((array) $profile->metadata, "nium_v5_fields.{$field}");

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException(
                'Nium SG corporate full KYC requires approved KYC metadata field '
                ."nium_v5_fields.{$field} as a non-empty string.",
            );
        }

        return trim($value);
    }

    private function isNonEmptyStringList(mixed $value): bool
    {
        return is_array($value)
            && $value !== []
            && array_is_list($value)
            && ! collect($value)->contains(
                fn ($item): bool => ! is_string($item) || trim($item) === '',
            );
    }

    private function isNiumDateTime(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        return $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d H:i:s') === $value;
    }

    private function normalizeIsoCountryList(mixed $value, string $path): array
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            throw new RuntimeException(
                "Nium SG corporate full KYC requires approved KYC metadata field {$path} "
                .'as a non-empty array of ISO alpha-2 country codes.',
            );
        }

        $normalized = [];

        foreach ($value as $country) {
            if (! is_string($country)) {
                throw new RuntimeException(
                    "Nium SG corporate full KYC requires approved KYC metadata field {$path} "
                    .'as a non-empty array of ISO alpha-2 country codes.',
                );
            }

            $country = strtoupper(trim($country));

            if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
                throw new RuntimeException(
                    "Nium SG corporate full KYC requires approved KYC metadata field {$path} "
                    .'as a non-empty array of ISO alpha-2 country codes.',
                );
            }

            if (! in_array($country, $normalized, true)) {
                $normalized[] = $country;
            }
        }

        if ($normalized === []) {
            throw new RuntimeException(
                "Nium SG corporate full KYC requires approved KYC metadata field {$path} "
                .'as a non-empty array of ISO alpha-2 country codes.',
            );
        }

        return $normalized;
    }

    private function validateBankAccountDetails(array $details, bool $requireRoutingCodes): void
    {
        foreach (['accountName', 'accountNumber', 'bankCountry', 'currency'] as $field) {
            if (! is_string($details[$field] ?? null) || trim($details[$field]) === '') {
                throw new RuntimeException(
                    'Nium SG corporate full KYC requires approved KYC metadata field '
                    ."nium_v5_fields.bankAccountDetails.{$field} as a non-empty string.",
                );
            }
        }

        if (preg_match('/^[A-Z]{2}$/', $details['bankCountry']) !== 1) {
            throw new RuntimeException(
                'Nium SG corporate full KYC requires approved KYC metadata field '
                .'nium_v5_fields.bankAccountDetails.bankCountry as an ISO alpha-2 country code.',
            );
        }

        if (preg_match('/^[A-Z]{3}$/', $details['currency']) !== 1) {
            throw new RuntimeException(
                'Nium SG corporate full KYC requires approved KYC metadata field '
                .'nium_v5_fields.bankAccountDetails.currency as an ISO 4217 currency code.',
            );
        }

        foreach (['bankAccountType', 'bankName'] as $optionalField) {
            if (
                array_key_exists($optionalField, $details)
                && (! is_string($details[$optionalField]) || trim($details[$optionalField]) === '')
            ) {
                throw new RuntimeException(
                    'Nium SG corporate full KYC requires approved KYC metadata field '
                    ."nium_v5_fields.bankAccountDetails.{$optionalField} as a non-empty string when supplied.",
                );
            }
        }

        $routingCodes = $details['routingCodes'] ?? null;

        if ($routingCodes === null && ! $requireRoutingCodes) {
            return;
        }

        if (! is_array($routingCodes) || $routingCodes === [] || ! array_is_list($routingCodes)) {
            throw new RuntimeException(
                'Nium SG corporate full KYC requires approved KYC metadata field '
                .'nium_v5_fields.bankAccountDetails.routingCodes as a non-empty array.',
            );
        }

        foreach ($routingCodes as $routingCode) {
            if (
                ! is_array($routingCode)
                || array_is_list($routingCode)
                || ! is_string($routingCode['type'] ?? null)
                || trim($routingCode['type']) === ''
                || ! is_string($routingCode['value'] ?? null)
                || trim($routingCode['value']) === ''
            ) {
                throw new RuntimeException(
                    'Nium SG corporate full KYC requires each approved '
                    .'nium_v5_fields.bankAccountDetails.routingCodes entry to contain type and value strings.',
                );
            }
        }
    }

    private function normalizeSgCorporateCountryLists(array $payload): array
    {
        Arr::set(
            $payload,
            'natureOfBusiness.operatingCountries',
            $this->normalizeIsoCountryList(
                Arr::get($payload, 'natureOfBusiness.operatingCountries'),
                'nium_v5_fields.natureOfBusiness.operatingCountries',
            ),
        );

        foreach (['credit', 'debit'] as $direction) {
            $path = "expectedAccountUsage.{$direction}.topTransactionCountries";
            Arr::set(
                $payload,
                $path,
                $this->normalizeIsoCountryList(
                    Arr::get($payload, $path),
                    "nium_v5_fields.{$path}",
                ),
            );
        }

        return $payload;
    }

    private function validateDeviceDetails(array $details): void
    {
        foreach (['ipCountryCode', 'deviceInfo', 'ipAddress', 'sessionId'] as $field) {
            if (! is_string($details[$field] ?? null) || trim($details[$field]) === '') {
                throw new RuntimeException(
                    'Nium SG corporate full KYC requires approved KYC metadata field '
                    ."nium_v5_fields.deviceDetails.{$field} as a non-empty string.",
                );
            }
        }

        if (preg_match('/^[A-Z]{2}$/', $details['ipCountryCode']) !== 1) {
            throw new RuntimeException(
                'Nium SG corporate full KYC requires approved KYC metadata field '
                .'nium_v5_fields.deviceDetails.ipCountryCode as an ISO alpha-2 country code.',
            );
        }

        if (filter_var($details['ipAddress'], FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException(
                'Nium SG corporate full KYC requires approved KYC metadata field '
                .'nium_v5_fields.deviceDetails.ipAddress as a valid IP address.',
            );
        }

        if (! Str::isUuid($details['sessionId'])) {
            throw new RuntimeException(
                'Nium SG corporate full KYC requires approved KYC metadata field '
                .'nium_v5_fields.deviceDetails.sessionId as a valid UUID.',
            );
        }
    }

    private function positions(array $positions, bool $normalizeNiumPositions): array
    {
        if (
            $normalizeNiumPositions
            && (
                $positions === []
                || collect($positions)->contains(
                    fn ($title): bool => ! is_string($title) || trim($title) === '',
                )
            )
        ) {
            throw new RuntimeException(
                'Nium SG corporate positions must be a non-empty array of supported role strings.',
            );
        }

        $mapped = collect($positions)
            ->filter(fn ($title): bool => is_string($title) && trim($title) !== '')
            ->map(function (string $title) use ($normalizeNiumPositions): array {
                if (! $normalizeNiumPositions) {
                    return ['title' => $title];
                }

                $sourceRole = strtolower(trim($title));
                $sourceRole = str_replace(['-', ' '], '_', $sourceRole);
                $niumRole = match ($sourceRole) {
                    'director' => 'DIRECTOR',
                    'beneficial_owner', 'ultimate_beneficial_owner', 'ubo' => 'UBO',
                    'shareholder' => 'SHAREHOLDER',
                    'signatory' => 'SIGNATORY',
                    default => null,
                };

                if ($niumRole === null) {
                    throw new RuntimeException(
                        'Unsupported Nium SG corporate stakeholder position.',
                    );
                }

                return ['title' => $niumRole];
            });

        return ($normalizeNiumPositions ? $mapped->unique('title') : $mapped)
            ->values()
            ->all();
    }

    private function stakeholderFallbackPosition(KycRelatedPerson $person): string
    {
        $relationship = strtolower(trim((string) $person->relationship_type));
        $relationship = str_replace(['-', ' '], '_', $relationship);

        return match (true) {
            str_contains($relationship, 'beneficial'), str_contains($relationship, 'ubo') => 'beneficial_owner',
            str_contains($relationship, 'shareholder') => 'shareholder',
            str_contains($relationship, 'signatory') => 'signatory',
            str_contains($relationship, 'director') => 'director',
            default => $relationship,
        };
    }

    private function regionFields(KycProfile $profile, string $region): array
    {
        $fields = Arr::get((array) $profile->metadata, 'nium_v5_fields', []);

        if (! is_array($fields)) {
            throw new RuntimeException('Approved KYC metadata nium_v5_fields must be an object.');
        }

        $regionFields = Arr::only($fields, [
            'annualIncome',
            'applicantDeclaration',
            'applicantDeclarationTimeStamp',
            'bankAccountDetails',
            'birthCountry',
            'deviceDetails',
            'expectedAccountUsage',
            'incomeSourceType',
            'isMultiLayeredCompany',
            'isPep',
            'listedExchange',
            'natureOfBusiness',
            'otaDetails',
            'segment',
            'sizeOfBusiness',
            'sourceOfFunds',
            'stockSymbol',
            'tags',
            'taxDetails',
            'tradeName',
            'trustType',
            'website',
        ]);

        if ($region === 'HK') {
            unset($regionFields['applicantDeclarationTimeStamp']);
            $regionFields['applicantDeclarationTimestamp'] = array_key_exists('applicantDeclarationTimestamp', $fields)
                ? $fields['applicantDeclarationTimestamp']
                : ($fields['applicantDeclarationTimeStamp'] ?? null);
        }

        return $regionFields;
    }

    private function requireFields(array $payload, array $fields, string $context): void
    {
        $missing = collect($fields)
            ->reject(fn (string $field): bool => filled($payload[$field] ?? null))
            ->values()
            ->all();

        if ($missing !== []) {
            throw new RuntimeException(
                "Nium {$context} requires approved KYC metadata fields: ".implode(', ', $missing).'.',
            );
        }
    }

    private function filter(array $payload): array
    {
        return array_filter($payload, static fn ($value) => $value !== null && $value !== '' && $value !== []);
    }
}
