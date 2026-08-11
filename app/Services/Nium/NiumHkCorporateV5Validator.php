<?php

namespace App\Services\Nium;

use App\Models\KycProfile;
use Illuminate\Support\Arr;
use RuntimeException;

final class NiumHkCorporateV5Validator
{
    public const REQUIRED_DOCUMENT_MISSING = 'HOLD_HK_REQUIRED_DOCUMENT_MISSING';

    public const DOCUMENT_RECENCY_UNPROVEN = 'HOLD_HK_DOCUMENT_RECENCY_UNPROVEN';

    public const LATEST_FILING_UNPROVEN = 'HOLD_HK_LATEST_FILING_UNPROVEN';

    public const IDENTITY_ADDRESS_EVIDENCE_UNPROVEN = 'HOLD_HK_IDENTITY_ADDRESS_EVIDENCE_UNPROVEN';

    public static function documentRoleKey(string $position): string
    {
        return str_replace(['-', ' '], '_', strtolower(trim($position)));
    }

    /** Validate the locally provable HK Corporate Full V5 contract without resolving provider enums. */
    public function assert(KycProfile $profile, array $payload): void
    {
        foreach (['businessType', 'businessName', 'tradeName', 'businessRegistrationNumber', 'registeredDate', 'registeredCountry'] as $path) {
            $this->requireString($payload, $path);
        }

        if (($payload['type'] ?? null) !== 'corporate' || ($payload['region'] ?? null) !== 'HK' || ($payload['kycType'] ?? null) !== 'full') {
            throw new RuntimeException('Nium HK onboarding requires a corporate full KYC payload.');
        }

        if (($payload['businessType'] ?? null) !== 'private_company') {
            throw new RuntimeException('Nium HK Corporate Full V5 businessType must be private_company.');
        }

        if (! is_bool($payload['isMultiLayeredCompany'] ?? null)) {
            throw new RuntimeException('Nium HK Corporate Full requires isMultiLayeredCompany as a boolean.');
        }

        $this->assertAddresses($payload);
        $this->assertApplicant($payload);
        $this->assertStakeholders($payload);
        $this->assertNatureOfBusiness($payload);
        $this->assertExpectedAccountUsage($payload);
        $this->assertSizeOfBusiness($payload);
        $this->assertBankAccountDetails($payload);
        $this->assertDeclaration($payload);
        $this->assertDeviceDetails($payload);
        $this->assertWebsite($payload);
        $this->assertDocuments($profile, $payload);
    }

    private function assertWebsite(array $payload): void
    {
        $website = $payload['website'] ?? null;

        if (! filled($website)) {
            return;
        }

        if (! is_string($website)
            || trim($website) !== $website
            || filter_var($website, FILTER_VALIDATE_URL) === false
            || ! in_array(parse_url($website, PHP_URL_SCHEME), ['http', 'https'], true)
            || ! is_string(parse_url($website, PHP_URL_HOST))) {
            throw new RuntimeException('Nium HK Corporate Full website must be an absolute HTTP or HTTPS URL.');
        }
    }

    private function assertAddresses(array $payload): void
    {
        $addresses = $this->requireObject($payload, 'addresses');

        if (! is_bool($addresses['isBusinessAddressSameAsRegisteredAddress'] ?? null)) {
            throw new RuntimeException('hk_corporate_address_relationship_invalid');
        }

        $this->assertCorporateAddress($addresses['registeredAddress'] ?? null, 'addresses.registeredAddress');

        if ($addresses['isBusinessAddressSameAsRegisteredAddress']) {
            if (array_key_exists('businessAddress', $addresses)) {
                throw new RuntimeException('hk_corporate_business_address_conflict');
            }

            return;
        }

        $this->assertCorporateAddress($addresses['businessAddress'] ?? null, 'addresses.businessAddress');
    }

    private function assertApplicant(array $payload): void
    {
        foreach (['firstName', 'lastName', 'dateOfBirth', 'email', 'mobile', 'mobileCountryCode', 'nationality'] as $path) {
            $this->requireString($payload, "applicant.{$path}");
        }

        $this->assertAddress(Arr::get($payload, 'applicant.address'), 'applicant.address');
        $this->assertPositionList(Arr::get($payload, 'applicant.positions'), 'applicant.positions');
        $this->assertConditionalSharePercentage($payload['applicant'], 'applicant');
    }

    private function assertStakeholders(array $payload): void
    {
        $individuals = Arr::get($payload, 'stakeholders.individual');

        if (! is_array($individuals) || $individuals === [] || ! array_is_list($individuals)) {
            throw new RuntimeException('Nium HK Corporate Full requires stakeholders.individual as a non-empty array.');
        }

        foreach ($individuals as $index => $stakeholder) {
            if (! is_array($stakeholder) || array_is_list($stakeholder)) {
                throw new RuntimeException('Nium HK Corporate Full stakeholder entries must be objects.');
            }

            foreach (['firstName', 'lastName', 'dateOfBirth', 'nationality'] as $field) {
                $this->requireString($stakeholder, $field, "stakeholders.individual.{$index}.{$field}");
            }

            $this->assertAddress($stakeholder['address'] ?? null, "stakeholders.individual.{$index}.address");
            $this->assertPositionList($stakeholder['positions'] ?? null, "stakeholders.individual.{$index}.positions");
            $this->assertConditionalSharePercentage($stakeholder, "stakeholders.individual.{$index}");
        }
    }

    private function assertNatureOfBusiness(array $payload): void
    {
        $details = $this->requireObject($payload, 'natureOfBusiness');
        $this->assertStringList($details['operatingCountries'] ?? null, 'natureOfBusiness.operatingCountries', true);
        $this->assertStringList($details['industryCodes'] ?? null, 'natureOfBusiness.industryCodes');
    }

    private function assertExpectedAccountUsage(array $payload): void
    {
        $usage = $this->requireObject($payload, 'expectedAccountUsage');
        $this->assertStringList($usage['intendedUses'] ?? null, 'expectedAccountUsage.intendedUses');

        foreach (['credit', 'debit'] as $direction) {
            $directionUsage = $usage[$direction] ?? null;

            if (! is_array($directionUsage) || $directionUsage === [] || array_is_list($directionUsage)) {
                throw new RuntimeException("Nium HK Corporate Full requires expectedAccountUsage.{$direction} as an object.");
            }

            foreach (['monthlyTransactionVolume', 'monthlyTransactions', 'averageTransactionValue'] as $field) {
                $this->requireString($directionUsage, $field, "expectedAccountUsage.{$direction}.{$field}");
            }

            $this->assertStringList($directionUsage['topTransactionCountries'] ?? null, "expectedAccountUsage.{$direction}.topTransactionCountries", true);
        }
    }

    private function assertSizeOfBusiness(array $payload): void
    {
        $size = $this->requireObject($payload, 'sizeOfBusiness');
        $this->requireString($size, 'totalEmployees', 'sizeOfBusiness.totalEmployees');
        $this->requireString($size, 'annualTurnover', 'sizeOfBusiness.annualTurnover');
    }

    private function assertBankAccountDetails(array $payload): void
    {
        $details = $this->requireObject($payload, 'bankAccountDetails');

        foreach (['accountName', 'accountNumber', 'bankCountry', 'bankName', 'currency'] as $field) {
            $this->requireString($details, $field, "bankAccountDetails.{$field}");
        }

        $routingCodes = $details['routingCodes'] ?? null;

        if (! is_array($routingCodes) || $routingCodes === [] || ! array_is_list($routingCodes)) {
            throw new RuntimeException('Nium HK Corporate Full requires bankAccountDetails.routingCodes as a non-empty array.');
        }

        foreach ($routingCodes as $index => $routingCode) {
            if (! is_array($routingCode) || array_is_list($routingCode)) {
                throw new RuntimeException("Nium HK Corporate Full requires bankAccountDetails.routingCodes.{$index} as an object.");
            }

            $this->requireString($routingCode, 'type', "bankAccountDetails.routingCodes.{$index}.type");
            $this->requireString($routingCode, 'value', "bankAccountDetails.routingCodes.{$index}.value");
        }
    }

    private function assertDeclaration(array $payload): void
    {
        if (($payload['applicantDeclaration'] ?? null) !== true) {
            throw new RuntimeException('Nium HK Corporate Full requires applicantDeclaration as boolean true.');
        }

        if (array_key_exists('applicantDeclarationTimestamp', $payload)) {
            throw new RuntimeException('Nium HK Corporate Full must not contain applicantDeclarationTimestamp.');
        }

        $this->requireString($payload, 'applicantDeclarationTimeStamp');

        $timestamp = $payload['applicantDeclarationTimeStamp'];
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $timestamp);
        $errors = \DateTimeImmutable::getLastErrors();

        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d H:i:s') !== $timestamp) {
            throw new RuntimeException('Nium HK Corporate Full requires applicantDeclarationTimeStamp in YYYY-MM-DD HH:MM:SS format.');
        }
    }

    private function assertDeviceDetails(array $payload): void
    {
        $details = $this->requireObject($payload, 'deviceDetails');

        foreach (['ipCountryCode', 'deviceInfo', 'ipAddress', 'sessionId'] as $field) {
            $this->requireString($details, $field, "deviceDetails.{$field}");
        }

        if (preg_match('/^[A-Z]{2}$/', $details['ipCountryCode']) !== 1) {
            throw new RuntimeException('Nium HK Corporate Full deviceDetails.ipCountryCode must be an ISO alpha-2 code.');
        }

        if (filter_var($details['ipAddress'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException('Nium HK Corporate Full deviceDetails.ipAddress must be a valid IPv4 address.');
        }
    }

    private function assertDocuments(KycProfile $profile, array $payload): void
    {
        $documents = $payload['documents'] ?? null;

        if (! is_array($documents) || $documents === [] || ! array_is_list($documents)) {
            throw new RuntimeException('Nium HK Corporate Full requires top-level corporate documents.');
        }

        $types = collect($documents)
            ->pluck('type')
            ->filter(fn ($type): bool => is_string($type))
            ->map(fn (string $type): string => strtolower(trim($type)))
            ->values();
        $missing = [];

        if (! $types->contains('business_registration_doc')) {
            $missing[] = 'business_registration_doc';
        }

        if (strtolower($payload['businessType']) === 'private_company' && ! $types->intersect(['nar1', 'nnc1'])->count()) {
            $missing[] = 'nar1_or_nnc1';
        }

        if (! filled($payload['website'] ?? null) && ! $types->contains('proof_of_business')) {
            $missing[] = 'proof_of_business';
        }

        if (($payload['isMultiLayeredCompany'] ?? false) && $types->intersect(['corporate_structure', 'ownership_chart'])->isEmpty()) {
            $missing[] = 'corporate_structure';
        }

        $applicantPositions = collect(Arr::get($payload, 'applicant.positions', []))
            ->pluck('title')
            ->filter(fn ($position): bool => is_string($position))
            ->map(fn (string $position): string => self::documentRoleKey($position));

        $applicantDocumentTypes = collect(Arr::get($payload, 'applicant.documents', []))
            ->pluck('type')
            ->filter(fn ($type): bool => is_string($type))
            ->map(fn (string $type): string => strtolower(trim($type)));

        if ($applicantPositions->intersect(['director', 'ubo', 'ultimate_beneficial_owner', 'partner'])->isEmpty() && ! $applicantDocumentTypes->contains('loa')) {
            $missing[] = 'loa';
        }

        if ($missing !== []) {
            throw new RuntimeException(self::REQUIRED_DOCUMENT_MISSING.':'.implode(',', $missing));
        }
    }

    private function assertAddress(mixed $address, string $path): void
    {
        if (! is_array($address) || $address === [] || array_is_list($address)) {
            throw new RuntimeException("Nium HK Corporate Full requires {$path} as an object.");
        }

        foreach (['addressLine1', 'city', 'state', 'country'] as $field) {
            $this->requireString($address, $field, "{$path}.{$field}");
        }

        if (preg_match('/^[A-Z]{2}$/', $address['country']) !== 1) {
            throw new RuntimeException("Nium HK Corporate Full requires {$path}.country as an ISO alpha-2 code.");
        }
    }

    private function assertCorporateAddress(mixed $address, string $path): void
    {
        if (! is_array($address) || $address === [] || array_is_list($address)) {
            throw new RuntimeException("Nium HK Corporate Full requires {$path} as an object.");
        }

        foreach (['addressLine1', 'city', 'postcode', 'country'] as $field) {
            $this->requireString($address, $field, "{$path}.{$field}");
        }

        if (array_key_exists('state', $address) && (! is_string($address['state']) || trim($address['state']) === '')) {
            throw new RuntimeException("Nium HK Corporate Full requires {$path}.state as a non-empty string when supplied.");
        }

        if (preg_match('/^[A-Z]{2}$/', $address['country']) !== 1) {
            throw new RuntimeException("Nium HK Corporate Full requires {$path}.country as an ISO alpha-2 code.");
        }
    }

    private function assertConditionalSharePercentage(array $person, string $path): void
    {
        $positions = collect($person['positions'] ?? [])
            ->pluck('title')
            ->filter(fn ($position): bool => is_string($position))
            ->map(fn (string $position): string => self::documentRoleKey($position));

        if (
            $positions->intersect(['ubo', 'ultimate_beneficial_owner', 'shareholder'])->isNotEmpty()
            && ! is_int($person['sharePercentage'] ?? null)
            && ! is_float($person['sharePercentage'] ?? null)
        ) {
            throw new RuntimeException("Nium HK Corporate Full requires {$path}.sharePercentage as a number for UBO or shareholder positions.");
        }
    }

    private function assertPositionList(mixed $positions, string $path): void
    {
        if (! is_array($positions) || $positions === [] || ! array_is_list($positions)) {
            throw new RuntimeException("Nium HK Corporate Full requires {$path} as a non-empty array.");
        }

        foreach ($positions as $position) {
            if (! is_array($position) || array_is_list($position)) {
                throw new RuntimeException("Nium HK Corporate Full requires {$path} entries as objects.");
            }

            $this->requireString($position, 'title', $path.'.*.title');
        }
    }

    private function assertStringList(mixed $value, string $path, bool $countryCodes = false): void
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            throw new RuntimeException("Nium HK Corporate Full requires {$path} as a non-empty array.");
        }

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '' || ($countryCodes && preg_match('/^[A-Z]{2}$/', $item) !== 1)) {
                throw new RuntimeException("Nium HK Corporate Full contains an invalid value at {$path}.");
            }
        }
    }

    private function requireObject(array $payload, string $path): array
    {
        $value = Arr::get($payload, $path);

        if (! is_array($value) || $value === [] || array_is_list($value)) {
            throw new RuntimeException("Nium HK Corporate Full requires {$path} as an object.");
        }

        return $value;
    }

    private function requireString(array $payload, string $path, ?string $displayPath = null): string
    {
        $value = Arr::get($payload, $path);

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('Nium HK Corporate Full requires '.($displayPath ?? $path).' as a non-empty string.');
        }

        return trim($value);
    }
}
