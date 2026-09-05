<?php

namespace App\Services\Nium;

use App\Models\KycDocument;
use App\Models\KycProfile;
use App\Models\NiumCorporateConstant;
use App\Models\User;
use DateTimeImmutable;
use RuntimeException;

final class NiumKycDataValidator
{
    public function assertSource(User $user): void
    {
        $user->loadMissing(['kycProfile.documents', 'kycProfile.relatedPersons.documents']);
        $profile = $user->kycProfile;

        if (! $profile instanceof KycProfile) {
            throw new RuntimeException('kycProfile: a persisted KYC profile is required.');
        }

        $region = strtoupper((string) (($profile->metadata ?? [])['nium_region'] ?? $profile->registered_country_code ?? $profile->country_code));
        $this->assertFactual($profile->legal_name, 'kycProfile.legalName');

        if ($profile->applicant_type === 'business') {
            $this->assertFactual($profile->business_name, 'kycProfile.businessName');

            $registrationDocument = $profile->documents->first(
                fn (KycDocument $document): bool => strtolower(trim((string) $document->type)) === 'business_registration'
            );
            $registrationNumber = trim((string) $profile->business_registration_number);

            if ($registrationDocument instanceof KycDocument
                && trim((string) $registrationDocument->document_number) === ''
                && $registrationNumber !== '') {
                $registrationDocument->update(['document_number' => $registrationNumber]);
            }
        }

        $this->assertAddressSource($profile, 'kycProfile', $region);

        if ($profile->applicant_type === 'individual') {
            $this->assertPersonSource($profile, 'kycProfile', $region);
        }

        foreach ($profile->relatedPersons as $index => $person) {
            $this->assertPersonSource($person, "relatedPersons.{$index}", $region);
        }

        $isHkCorporate = $profile->applicant_type === 'business'
            && strtoupper((string) $profile->registered_country_code) === 'HK';

        if (! $isHkCorporate) {
            return;
        }

        $brn = trim((string) $profile->business_registration_number);
        $this->assertFactual($brn, 'businessRegistrationNumber');

        if (preg_match('/^\d{8}$/', $brn) !== 1) {
            throw new RuntimeException('businessRegistrationNumber: HK corporate registration number must be exactly 8 digits.');
        }

        $document = $profile->documents->first(fn (KycDocument $document): bool => in_array(
            strtolower(trim((string) (($document->metadata ?? [])['nium_document_type'] ?? $document->type))),
            ['business_registration', 'business_registration_doc', 'certificate_of_incorporation'],
            true,
        ));

        if (! $document instanceof KycDocument || trim((string) $document->document_number) === '') {
            throw new RuntimeException('documents.business_registration.identificationNumber: a factual identification number is required.');
        }

        if (trim((string) $document->document_number) !== $brn) {
            throw new RuntimeException('documents.business_registration.identificationNumber: must match businessRegistrationNumber.');
        }
    }

    public function assertPayload(array $payload): void
    {
        $this->assertCountry($payload['region'] ?? null, 'region');
        $region = (string) $payload['region'];

            if (($payload['type'] ?? null) === 'corporate') {
                $businessType = $payload['businessType'] ?? null;

                if (is_string($businessType)) {
                    $businessType = strtoupper($businessType);
                }

                $this->assertConstant(
                    $region,
                    'businessType',
                    $businessType,
                    'businessType'
                );
            $this->assertConstantList($region, 'industrySector', $payload['natureOfBusiness']['industryCodes'] ?? null, 'natureOfBusiness.industryCodes');
            $this->assertConstantList($region, 'countryOfOperation', $payload['natureOfBusiness']['operatingCountries'] ?? null, 'natureOfBusiness.operatingCountries');
            $this->assertConstantList($region, 'intendedUseOfAccount', $payload['expectedAccountUsage']['intendedUses'] ?? null, 'expectedAccountUsage.intendedUses');
            $this->assertConstant($region, 'annualTurnover', $payload['sizeOfBusiness']['annualTurnover'] ?? null, 'sizeOfBusiness.annualTurnover');
            $this->assertConstant($region, 'totalEmployees', $payload['sizeOfBusiness']['totalEmployees'] ?? null, 'sizeOfBusiness.totalEmployees');

            foreach (['credit', 'debit'] as $direction) {
                $usage = $payload['expectedAccountUsage'][$direction] ?? [];
                $this->assertConstant($region, 'averageTransactionValue', $usage['averageTransactionValue'] ?? null, "expectedAccountUsage.{$direction}.averageTransactionValue");
                $this->assertConstant($region, 'monthlyTransactionVolume', $usage['monthlyTransactionVolume'] ?? null, "expectedAccountUsage.{$direction}.monthlyTransactionVolume");
                $this->assertConstant($region, 'monthlyTransactions', $usage['monthlyTransactions'] ?? null, "expectedAccountUsage.{$direction}.monthlyTransactions");
                $this->assertConstantList($region, 'countryOfOperation', $usage['topTransactionCountries'] ?? null, "expectedAccountUsage.{$direction}.topTransactionCountries");
            }
        }

        if (($payload['type'] ?? null) === 'corporate' && ($payload['region'] ?? null) === 'HK') {
            $brn = $payload['businessRegistrationNumber'] ?? null;

            $this->assertFactual($brn, 'businessRegistrationNumber');

            if (! is_string($brn) || preg_match('/^\d{8}$/', $brn) !== 1) {
                throw new RuntimeException('businessRegistrationNumber: HK corporate registration number must be exactly 8 digits.');
            }

            $registration = collect($payload['documents'] ?? [])
            ->first(fn ($document): bool =>
                is_array($document)
                && in_array(
                    strtolower((string) ($document['type'] ?? null)),
                    [
                        'business_registration_doc',
                        'business_registration',
                        'certificate_of_incorporation',
                    ],
                    true
                )
            );
            $identificationNumber = is_array($registration) ? ($registration['identificationNumber'] ?? null) : null;

            if (! is_string($identificationNumber) || trim($identificationNumber) === '') {
                throw new RuntimeException('documents.business_registration.identificationNumber: a factual identification number is required.');
            }

            if ($identificationNumber !== $brn) {
                throw new RuntimeException('documents.business_registration.identificationNumber: must match businessRegistrationNumber.');
            }
        }

        foreach ($this->payloadPeople($payload) as [$person, $path]) {
            $this->assertDate($person['dateOfBirth'] ?? null, "{$path}.dateOfBirth");
            $this->assertCountry($person['nationality'] ?? null, "{$path}.nationality");
            $this->assertConstant($region, 'countryName', $person['nationality'] ?? null, "{$path}.nationality");
            $this->assertPayloadAddress($person['address'] ?? $person['billingAddress'] ?? null, "{$path}.address", $region);

            foreach (($person['documents'] ?? []) as $index => $document) {
                if (is_array($document)) {
                    $this->assertConstant($region, 'documentType', $document['type'] ?? null, "{$path}.documents.{$index}.type");
                }
                if (! is_array($document) || ! $this->isIdentityDocument((string) ($document['type'] ?? ''))) {
                    continue;
                }

                if (! filled($document['identificationNumber'] ?? null) || ! filled($document['issuanceCountry'] ?? null)) {
                    throw new RuntimeException("{$path}.documents.{$index}: type, identificationNumber, and issuanceCountry are required.");
                }
                $this->assertCountry($document['issuanceCountry'], "{$path}.documents.{$index}.issuanceCountry");
            }
        }

        foreach (($payload['addresses'] ?? []) as $key => $address) {
            if (is_array($address)) {
                $this->assertPayloadAddress($address, "addresses.{$key}", $region);
            }
        }

        foreach (($payload['documents'] ?? []) as $index => $document) {
            if (is_array($document)) {
                $this->assertConstant(
                    $region,
                    'documentType',
                    $document['type']
                        ?? $document['documentType']
                        ?? $document['document_type']
                        ?? data_get($document, 'metadata.nium_document_type'),
                    "documents.{$index}.type"
                );
            }
        }
    }

    private function assertPersonSource(object $person, string $path, string $region): void
    {
        $this->assertDate($person->date_of_birth?->toDateString(), "{$path}.dateOfBirth");
        $this->assertCountry($person->nationality_country_code, "{$path}.nationality");
        $this->assertAddressSource($person, $path, $region);

        foreach ($person->documents as $index => $document) {
            if (! $this->isIdentityDocument((string) $document->type)) {
                continue;
            }

            if (trim((string) $document->document_number) === '' || trim((string) $document->issuing_country_code) === '') {
                throw new RuntimeException("{$path}.documents.{$index}: identificationNumber and issuanceCountry are required.");
            }
            $this->assertCountry($document->issuing_country_code, "{$path}.documents.{$index}.issuanceCountry");
        }
    }

    private function assertAddressSource(object $subject, string $path, string $region): void
    {
        $country = strtoupper(trim((string) $subject->country_code));
        $this->assertCountry($country, "{$path}.country");
        $this->assertSubdivision($region, $country, $subject->state, "{$path}.state");
        $this->assertPostalCode($subject->postal_code, "{$path}.postalCode");
        $this->assertFactual($subject->address_line1, "{$path}.addressLine1");
        $this->assertFactual($subject->city, "{$path}.city");
    }

    private function assertPayloadAddress(mixed $address, string $path, string $region): void
    {
        if (! is_array($address)) {
            throw new RuntimeException("{$path}: address is required.");
        }

        $country = $address['country'] ?? null;
        $this->assertCountry($country, "{$path}.country");
        $this->assertSubdivision($region, (string) $country, $address['state'] ?? null, "{$path}.state");
        $this->assertPostalCode($address['postcode'] ?? null, "{$path}.postcode");
        $this->assertFactual($address['addressLine1'] ?? null, "{$path}.addressLine1");
        $this->assertFactual($address['city'] ?? null, "{$path}.city");
    }

    private function assertCountry(mixed $value, string $path): void
    {
        if (! is_string($value) || preg_match('/^[A-Z]{2}$/', $value) !== 1) {
            throw new RuntimeException("{$path}: must be an uppercase ISO alpha-2 country code.");
        }
    }

    private function assertSubdivision(string $region, string $country, mixed $state, string $path): void
    {
        if ($state !== null && (! is_string($state) || trim($state) === '')) {
            throw new RuntimeException("{$path}: must be free text when provided.");
        }
    }

    private function assertPostalCode(mixed $value, string $path): void
    {
        if (! is_string($value) || preg_match('/^[A-Z0-9][A-Z0-9 -]{1,28}[A-Z0-9]$/i', trim($value)) !== 1) {
            throw new RuntimeException("{$path}: invalid postal code.");
        }
    }

    private function assertFactual(mixed $value, string $path): void
    {
        $normalized = strtolower(trim((string) $value));

        if ($normalized === '' || preg_match('/^(test|testing|sample|dummy|placeholder|unknown|n\/?a|none|nil|0+)$/i', $normalized) === 1) {
            throw new RuntimeException("{$path}: placeholder or test values are not allowed.");
        }
    }

    private function assertDate(mixed $value, string $path): void
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        if ($date === false || $date->format('Y-m-d') !== $value || $date >= new DateTimeImmutable('today')) {
            throw new RuntimeException("{$path}: must be a valid past date in YYYY-MM-DD format.");
        }
    }

    private function payloadPeople(array $payload): array
    {
        $people = [];

        if (($payload['type'] ?? null) === 'individual') {
            $people[] = [$payload, 'customer'];
        } elseif (is_array($payload['applicant'] ?? null)) {
            $people[] = [$payload['applicant'], 'applicant'];
        }

        foreach (($payload['stakeholders']['individual'] ?? []) as $index => $person) {
            if (is_array($person)) {
                $people[] = [$person, "stakeholders.individual.{$index}"];
            }
        }

        return $people;
    }

    private function isIdentityDocument(string $type): bool
    {
        $type = strtolower($type);

        return str_contains($type, 'passport')
            || str_contains($type, 'national_id')
            || str_contains($type, 'driver');
    }

    private function assertConstant(string $region, string $category, mixed $value, string $path): void
    {
        $record = NiumCorporateConstant::query()
        ->where('region', $region)
        ->where('customer_type', 'CORPORATE')
        ->where('constant_type', $category)
        ->first();

        $allowed = collect($record?->values ?? [])
            ->pluck('value')
            ->map(fn ($v) => strtolower((string) $v))
            ->toArray();

        \Log::info('NIUM CONSTANT CHECK', [
            'category' => $category,
            'value' => $value,
            'allowed' => $allowed,
        ]);

        if (! is_string($value) || ! in_array(strtolower((string) $value), $allowed, true)) {
            throw new RuntimeException("{$path}: value was not returned by Nium category {$category}.");
        }
    }

    private function assertConstantList(string $region, string $category, mixed $values, string $path): void
    {
        if (! is_array($values) || $values === []) {
            throw new RuntimeException("{$path}: requires Nium category {$category} values.");
        }

        foreach ($values as $value) {
            $this->assertConstant($region, $category, $value, $path);
        }
    }
}
