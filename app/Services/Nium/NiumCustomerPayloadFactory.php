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
    public function __construct(
        private readonly NiumCustomerDocumentResolver $documentResolver,
    ) {}

    /**
     * Build a Customer Onboarding V5 request from the internally approved KYC record.
     * Provider identifiers and lifecycle fields are deliberately never accepted here.
     */
    public function build(User $user, string $externalReference): array
    {
        $user->loadMissing(['kycProfile.documents', 'kycProfile.relatedPersons.documents']);
        $profile = $user->kycProfile;

        if ($profile === null || ! in_array(strtolower((string) $profile->status), ['approved', 'verified'], true)) {
            throw new RuntimeException('An approved internal KYC/KYB profile is required for Nium onboarding.');
        }

        $metadata = (array) ($profile->metadata ?? []);
        $region = strtoupper((string) ($metadata['nium_region'] ?? $this->regionFor($profile)));
        $kycType = strtolower((string) ($metadata['nium_kyc_type'] ?? 'minimum'));

        $payload = $profile->applicant_type === 'business'
            ? $this->corporatePayload($user, $profile, $externalReference, $region, $kycType)
            : $this->individualPayload($user, $profile, $externalReference, $region, $kycType);

        return $this->withoutProviderControlledFields($payload);
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
        $regionFields = $this->regionFields($profile);

        $payload = $this->filter(array_merge($regionFields, [
            'type' => 'individual',
            'region' => $region,
            'kycType' => $kycType,
            'externalId' => $externalReference,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'dateOfBirth' => $profile->date_of_birth?->toDateString(),
            'email' => $user->email,
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
        $applicant = $profile->relatedPersons->first(
            fn (KycRelatedPerson $person) => in_array(strtolower((string) $person->relationship_type), [
                'applicant', 'authorized_representative', 'authorised_representative',
            ], true)
        );

        if ($applicant === null) {
            throw new RuntimeException('A corporate Nium onboarding request requires an approved applicant or authorized representative.');
        }

        $registeredDate = $metadata['registered_date'] ?? $metadata['business_registered_date'] ?? null;
        $businessType = $metadata['nium_business_type'] ?? $metadata['business_type'] ?? null;

        if (! filled($registeredDate) || ! filled($businessType)) {
            throw new RuntimeException('Corporate Nium onboarding requires registered_date and nium_business_type in the approved KYC metadata.');
        }

        $payload = $this->filter(array_merge($this->regionFields($profile), [
            'type' => 'corporate',
            'region' => $region,
            'kycType' => $kycType,
            'externalId' => $externalReference,
            'businessName' => $profile->business_name,
            'businessRegistrationNumber' => $profile->business_registration_number,
            'businessType' => $businessType,
            'registeredCountry' => strtoupper((string) $profile->registered_country_code),
            'registeredDate' => $registeredDate,
            'website' => $metadata['business_website'] ?? null,
            'addresses' => [
                'registeredAddress' => $this->address($profile),
                'businessAddress' => $this->address($profile),
            ],
            'applicant' => $this->person($applicant, $user->email, (string) $user->phone, ['director']),
            'stakeholders' => $this->stakeholders($profile, $applicant),
            'documents' => $this->documents($this->documentResolver->profileDocuments($profile)),
        ]));

        if (in_array($region, ['UK', 'EU'], true) && $kycType === 'minimum') {
            $this->requireFields($payload, [
                'expectedAccountUsage',
                'natureOfBusiness',
                'sizeOfBusiness',
            ], "{$region} corporate minimum KYC");
        }

        return $payload;
    }

    private function person(KycRelatedPerson $person, ?string $fallbackEmail, string $fallbackPhone, array $fallbackPositions): array
    {
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
            'email' => $metadata['email'] ?? $fallbackEmail,
            'mobileCountryCode' => $mobileCountryCode,
            'mobile' => $mobile,
            'nationality' => strtoupper((string) ($person->nationality_country_code ?: $person->country_code)),
            'address' => $this->address($person),
            'positions' => collect((array) ($metadata['positions'] ?? $fallbackPositions))
                ->filter()
                ->map(fn (string $title) => ['title' => $title])
                ->values()
                ->all(),
            'sharePercentage' => $person->ownership_percentage !== null
                ? (float) $person->ownership_percentage
                : null,
            'documents' => $this->documents($this->documentResolver->relatedPersonDocuments($person)),
        ]);
    }

    private function stakeholders(KycProfile $profile, KycRelatedPerson $applicant): array
    {
        $individuals = $profile->relatedPersons
            ->reject(fn (KycRelatedPerson $person) => $person->is($applicant))
            ->map(function (KycRelatedPerson $person): array {
                $relationship = strtolower((string) $person->relationship_type);
                $position = str_contains($relationship, 'beneficial') || str_contains($relationship, 'ubo')
                    ? 'ultimate_beneficial_owner'
                    : 'director';

                return $this->person($person, null, '', [$position]);
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

    private function address(object $subject): array
    {
        return $this->filter([
            'addressLine1' => $subject->address_line1,
            'addressLine2' => $subject->address_line2,
            'city' => $subject->city,
            'state' => $subject->state,
            'postcode' => $subject->postal_code,
            'country' => strtoupper((string) $subject->country_code),
        ]);
    }

    private function regionFor(KycProfile $profile): string
    {
        $country = strtoupper((string) ($profile->registered_country_code ?: $profile->residence_country_code ?: $profile->country_code));

        if ($country === 'GB') {
            return 'UK';
        }

        if ($country === 'NL') {
            return 'NL';
        }

        if (in_array($country, ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HU', 'IE', 'IS', 'IT', 'LI', 'LT', 'LU', 'LV', 'MT', 'NO', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK'], true)) {
            return 'EU';
        }

        return in_array($country, ['SG', 'US', 'AU', 'NZ', 'CA', 'HK', 'JP', 'MX', 'BR', 'ID'], true)
            ? $country
            : 'SG';
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

    private function regionFields(KycProfile $profile): array
    {
        $fields = Arr::get((array) $profile->metadata, 'nium_v5_fields', []);

        if (! is_array($fields)) {
            throw new RuntimeException('Approved KYC metadata nium_v5_fields must be an object.');
        }

        return Arr::only($fields, [
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
