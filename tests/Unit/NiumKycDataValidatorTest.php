<?php

namespace Tests\Unit;

use App\Services\Nium\NiumKycDataValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\SeedsNiumCorporateConstants;
use Tests\TestCase;

class NiumKycDataValidatorTest extends TestCase
{
    use RefreshDatabase, SeedsNiumCorporateConstants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedNiumCorporateConstants();
    }

    public function test_business_registration_document_mismatch_is_rejected(): void
    {
        $payload = $this->validPayload();
        $payload['documents'][0]['identificationNumber'] = '87654321';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('documents.business_registration.identificationNumber');
        app(NiumKycDataValidator::class)->assertPayload($payload);
    }

    public function test_valid_customer_payload_passes_nium_preflight_validator(): void
    {
        app(NiumKycDataValidator::class)->assertPayload($this->validPayload());

        $this->addToAssertionCount(1);
    }

    private function validPayload(): array
    {
        $address = [
            'addressLine1' => '88 Queensway',
            'city' => 'Hong Kong',
            'state' => 'HK-HCW',
            'postcode' => '999077',
            'country' => 'HK',
        ];
        $person = [
            'dateOfBirth' => '1980-01-01',
            'nationality' => 'HK',
            'address' => $address,
            'documents' => [[
                'type' => 'passport',
                'identificationNumber' => 'K1234567',
                'issuanceCountry' => 'HK',
            ]],
        ];

        return [
            'type' => 'corporate',
            'region' => 'HK',
            'businessRegistrationNumber' => '12345678',
            'businessType' => 'PRIVATE_COMPANY',
            'natureOfBusiness' => [
                'industryCodes' => ['is112'],
                'operatingCountries' => ['HK'],
            ],
            'expectedAccountUsage' => [
                'intendedUses' => ['iu002'],
                'credit' => [
                    'averageTransactionValue' => 'tc001',
                    'monthlyTransactionVolume' => 'eu008',
                    'monthlyTransactions' => 'tc001',
                    'topTransactionCountries' => ['HK'],
                ],
                'debit' => [
                    'averageTransactionValue' => 'tc001',
                    'monthlyTransactionVolume' => 'eu008',
                    'monthlyTransactions' => 'tc001',
                    'topTransactionCountries' => ['HK'],
                ],
            ],
            'sizeOfBusiness' => ['annualTurnover' => 'SG011', 'totalEmployees' => 'EM009'],
            'addresses' => ['registeredAddress' => $address],
            'applicant' => $person,
            'stakeholders' => ['individual' => [$person]],
            'documents' => [[
                'type' => 'business_registration_doc',
                'identificationNumber' => '12345678',
            ]],
        ];
    }
}
