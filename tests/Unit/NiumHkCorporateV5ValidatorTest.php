<?php

namespace Tests\Unit;

use App\Models\KycProfile;
use App\Services\Nium\NiumHkCorporateV5Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class NiumHkCorporateV5ValidatorTest extends TestCase
{
    public function test_private_company_business_type_is_accepted(): void
    {
        $this->validator()->assert(new KycProfile, $this->payload());

        $this->addToAssertionCount(1);
    }

    public function test_internal_private_company_business_type_is_rejected_as_outbound_value(): void
    {
        $payload = $this->payload();
        $payload['businessType'] = 'PRIVATE_COMPANY';

        $this->expectExceptionMessage('businessType must be private_company');
        $this->validator()->assert(new KycProfile, $payload);
    }

    public function test_missing_address_relationship_is_rejected(): void
    {
        $payload = $this->payload();
        unset($payload['addresses']['isBusinessAddressSameAsRegisteredAddress']);

        $this->expectExceptionMessage('hk_corporate_address_relationship_invalid');
        $this->validator()->assert(new KycProfile, $payload);
    }

    public function test_non_boolean_address_relationship_is_rejected(): void
    {
        $payload = $this->payload();
        $payload['addresses']['isBusinessAddressSameAsRegisteredAddress'] = 'true';

        $this->expectExceptionMessage('hk_corporate_address_relationship_invalid');
        $this->validator()->assert(new KycProfile, $payload);
    }

    public function test_false_address_relationship_accepts_a_complete_business_address(): void
    {
        $payload = $this->payload();
        $payload['addresses']['isBusinessAddressSameAsRegisteredAddress'] = false;
        $payload['addresses']['businessAddress'] = $payload['addresses']['registeredAddress'];

        $this->validator()->assert(new KycProfile, $payload);

        $this->addToAssertionCount(1);
    }

    #[DataProvider('missingDeviceFields')]
    public function test_each_required_device_field_is_enforced(string $field): void
    {
        $payload = $this->payload();
        unset($payload['deviceDetails'][$field]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("deviceDetails.{$field}");

        $this->validator()->assert(new KycProfile, $payload);
    }

    public function test_session_id_accepts_a_non_uuid_string(): void
    {
        $payload = $this->payload();
        $payload['deviceDetails']['sessionId'] = 'provider-session-string';

        $this->validator()->assert(new KycProfile, $payload);

        $this->addToAssertionCount(1);
    }

    public function test_malformed_canonical_declaration_timestamp_is_rejected(): void
    {
        $payload = $this->payload();
        $payload['applicantDeclarationTimestamp'] = '2026-08-10T00:00:00Z';

        $this->expectExceptionMessage('applicantDeclarationTimestamp in YYYY-MM-DD HH:MM:SS format');
        $this->validator()->assert(new KycProfile, $payload);
    }

    public function test_invalid_ipv4_is_rejected(): void
    {
        $payload = $this->payload();
        $payload['deviceDetails']['ipAddress'] = '2001:db8::1';

        $this->expectExceptionMessage('valid IPv4 address');
        $this->validator()->assert(new KycProfile, $payload);
    }

    public function test_false_address_relationship_requires_business_address(): void
    {
        $payload = $this->payload();
        $payload['addresses']['isBusinessAddressSameAsRegisteredAddress'] = false;

        $this->expectExceptionMessage('addresses.businessAddress');
        $this->validator()->assert(new KycProfile, $payload);
    }

    public function test_true_address_relationship_rejects_contradictory_business_address(): void
    {
        $payload = $this->payload();
        $payload['addresses']['businessAddress'] = $payload['addresses']['registeredAddress'];

        $this->expectExceptionMessage('hk_corporate_business_address_conflict');
        $this->validator()->assert(new KycProfile, $payload);
    }

    public function test_private_company_missing_nar1_or_nnc1_is_classified_hold(): void
    {
        $payload = $this->payload();
        $payload['documents'] = [['type' => 'business_registration_doc']];

        $this->expectExceptionMessage(NiumHkCorporateV5Validator::REQUIRED_DOCUMENT_MISSING.':nar1_or_nnc1');
        $this->validator()->assert(new KycProfile, $payload);
    }

    public function test_loa_exemption_normalization_matches_payload_position_normalization(): void
    {
        $payload = $this->payload();
        $payload['applicant']['positions'] = [['title' => 'ultimate-beneficial-owner']];
        $payload['applicant']['sharePercentage'] = 25;

        $this->validator()->assert(new KycProfile, $payload);

        $this->assertSame(
            'ultimate_beneficial_owner',
            NiumHkCorporateV5Validator::documentRoleKey($payload['applicant']['positions'][0]['title']),
        );
    }

    public function test_applicant_bound_loa_satisfies_non_exempt_applicant_requirement(): void
    {
        $payload = $this->payload();
        $payload['applicant']['positions'] = [['title' => 'compliance_officer']];
        $payload['applicant']['documents'] = [['type' => 'loa']];

        $this->validator()->assert(new KycProfile, $payload);

        $this->addToAssertionCount(1);
    }

    public function test_top_level_loa_does_not_satisfy_applicant_requirement(): void
    {
        $payload = $this->payload();
        $payload['applicant']['positions'] = [['title' => 'compliance_officer']];
        $payload['documents'][] = ['type' => 'loa'];

        $this->expectExceptionMessage(NiumHkCorporateV5Validator::REQUIRED_DOCUMENT_MISSING.':loa');
        $this->validator()->assert(new KycProfile, $payload);
    }

    public function test_registered_address_requires_postcode_but_not_state(): void
    {
        $payload = $this->payload();
        unset($payload['addresses']['registeredAddress']['state']);

        $this->validator()->assert(new KycProfile, $payload);
        unset($payload['addresses']['registeredAddress']['postcode']);

        $this->expectExceptionMessage('addresses.registeredAddress.postcode');
        $this->validator()->assert(new KycProfile, $payload);
    }

    public function test_ubo_applicant_requires_numeric_share_percentage(): void
    {
        $payload = $this->payload();
        $payload['applicant']['positions'] = [['title' => 'ubo']];

        $this->expectExceptionMessage('applicant.sharePercentage');
        $this->validator()->assert(new KycProfile, $payload);
    }

    public function test_ubo_applicant_with_numeric_share_percentage_passes_shape_gate(): void
    {
        $payload = $this->payload();
        $payload['applicant']['positions'] = [['title' => 'ubo']];
        $payload['applicant']['sharePercentage'] = 25.5;

        $this->validator()->assert(new KycProfile, $payload);

        $this->addToAssertionCount(1);
    }

    public function test_ubo_stakeholder_requires_numeric_share_percentage(): void
    {
        $payload = $this->payload();
        $payload['stakeholders']['individual'][0]['positions'] = [['title' => 'shareholder']];

        $this->expectExceptionMessage('stakeholders.individual.0.sharePercentage');
        $this->validator()->assert(new KycProfile, $payload);
    }

    public function test_routing_codes_are_required_and_each_entry_must_have_type_and_value(): void
    {
        $payload = $this->payload();
        unset($payload['bankAccountDetails']['routingCodes']);

        try {
            $this->validator()->assert(new KycProfile, $payload);
            $this->fail('Missing routingCodes should fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('routingCodes as a non-empty array', $exception->getMessage());
        }

        $payload = $this->payload();
        $payload['bankAccountDetails']['routingCodes'] = [['type' => 'SWIFT']];

        $this->expectExceptionMessage('routingCodes.0.value');
        $this->validator()->assert(new KycProfile, $payload);
    }

    public function test_missing_declaration_is_rejected(): void
    {
        $payload = $this->payload();
        unset($payload['applicantDeclaration']);

        $this->expectExceptionMessage('applicantDeclaration');
        $this->validator()->assert(new KycProfile, $payload);
    }

    #[DataProvider('requiredCorporateGroups')]
    public function test_required_corporate_groups_are_enforced(string $group): void
    {
        $payload = $this->payload();
        unset($payload[$group]);

        $this->expectExceptionMessage($group);
        $this->validator()->assert(new KycProfile, $payload);
    }

    public function test_trade_name_may_equal_business_name(): void
    {
        $payload = $this->payload();
        $payload['tradeName'] = $payload['businessName'];

        $this->validator()->assert(new KycProfile, $payload);

        $this->addToAssertionCount(1);
    }

    public function test_post_four_field_fingerprint_is_stable(): void
    {
        $this->assertSame(
            'db131543ebbd35b8',
            substr(hash('sha256', 'addresses.isBusinessAddressSameAsRegisteredAddress'), 0, 16),
        );
    }

    public static function missingDeviceFields(): array
    {
        return collect(['ipCountryCode', 'deviceInfo', 'ipAddress', 'sessionId'])
            ->mapWithKeys(fn (string $field): array => [$field => [$field]])
            ->all();
    }

    public static function requiredCorporateGroups(): array
    {
        return collect([
            'addresses',
            'applicant',
            'stakeholders',
            'natureOfBusiness',
            'expectedAccountUsage',
            'sizeOfBusiness',
            'bankAccountDetails',
            'deviceDetails',
            'documents',
        ])->mapWithKeys(fn (string $group): array => [$group => [$group]])->all();
    }

    private function validator(): NiumHkCorporateV5Validator
    {
        return app(NiumHkCorporateV5Validator::class);
    }

    private function payload(): array
    {
        $address = [
            'addressLine1' => 'Synthetic address',
            'city' => 'Hong Kong',
            'state' => 'Hong Kong',
            'postcode' => '999077',
            'country' => 'HK',
        ];
        $person = [
            'firstName' => 'Synthetic',
            'lastName' => 'Person',
            'dateOfBirth' => '1980-01-01',
            'email' => 'synthetic@example.test',
            'mobile' => '55555555',
            'mobileCountryCode' => '852',
            'nationality' => 'HK',
            'positions' => [['title' => 'director']],
            'address' => $address,
        ];
        $usage = [
            'monthlyTransactionVolume' => 'UNPROVEN_ENUM',
            'monthlyTransactions' => 'UNPROVEN_ENUM',
            'averageTransactionValue' => 'UNPROVEN_ENUM',
            'topTransactionCountries' => ['HK'],
        ];

        return [
            'type' => 'corporate',
            'region' => 'HK',
            'kycType' => 'full',
            'businessType' => 'private_company',
            'businessName' => 'Synthetic Company',
            'tradeName' => 'Synthetic Company',
            'businessRegistrationNumber' => 'SYNTHETIC',
            'registeredDate' => '2020-01-01',
            'registeredCountry' => 'HK',
            'website' => 'https://business.example.test',
            'isMultiLayeredCompany' => false,
            'addresses' => [
                'isBusinessAddressSameAsRegisteredAddress' => true,
                'registeredAddress' => $address,
            ],
            'applicant' => $person,
            'stakeholders' => ['individual' => [$person]],
            'natureOfBusiness' => [
                'operatingCountries' => ['HK'],
                'industryCodes' => ['UNPROVEN_ENUM'],
            ],
            'expectedAccountUsage' => [
                'intendedUses' => ['UNPROVEN_ENUM'],
                'credit' => $usage,
                'debit' => $usage,
            ],
            'sizeOfBusiness' => [
                'totalEmployees' => 'UNPROVEN_ENUM',
                'annualTurnover' => 'UNPROVEN_ENUM',
            ],
            'bankAccountDetails' => [
                'accountName' => 'Synthetic Company',
                'accountNumber' => 'SYNTHETIC',
                'bankCountry' => 'HK',
                'bankName' => 'Synthetic Bank',
                'currency' => 'HKD',
                'routingCodes' => [
                    ['type' => 'SWIFT', 'value' => 'SYNTHETIC'],
                ],
            ],
            'applicantDeclaration' => true,
            'applicantDeclarationTimestamp' => '2026-08-10 00:00:00',
            'deviceDetails' => [
                'ipCountryCode' => 'HK',
                'deviceInfo' => 'Synthetic browser',
                'ipAddress' => '192.0.2.1',
                'sessionId' => 'provider-session-string',
            ],
            'documents' => [
                ['type' => 'business_registration_doc'],
                ['type' => 'nar1'],
            ],
        ];
    }
}
