<?php

namespace Tests\Unit;

use App\Services\Nium\NiumKycDataValidator;
use RuntimeException;
use Tests\TestCase;

class NiumKycDataValidatorTest extends TestCase
{
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
