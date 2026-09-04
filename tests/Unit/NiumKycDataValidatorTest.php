<?php

namespace Tests\Unit;

use App\Models\User;
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

    public function test_zero_postal_code_passes_nium_preflight_validator(): void
    {
        $payload = $this->validPayload();
        $payload['addresses']['registeredAddress']['postcode'] = '00000';
        $payload['applicant']['address']['postcode'] = '00000';
        $payload['stakeholders']['individual'][0]['address']['postcode'] = '00000';

        app(NiumKycDataValidator::class)->assertPayload($payload);

        $this->addToAssertionCount(1);
    }

    public function test_numeric_postal_code_passes_nium_preflight_validator(): void
    {
        $payload = $this->validPayload();
        $payload['addresses']['registeredAddress']['postcode'] = '12345';

        app(NiumKycDataValidator::class)->assertPayload($payload);

        $this->addToAssertionCount(1);
    }

    public function test_legal_or_company_name_placeholder_still_fails_factual_validation(): void
    {
        $validator = app(NiumKycDataValidator::class);
        $method = new \ReflectionMethod($validator, 'assertFactual');

        foreach (['kycProfile.legalName', 'kycProfile.businessName'] as $path) {
            try {
                $method->invoke($validator, 'test', $path);
                $this->fail("{$path} should reject placeholder values.");
            } catch (\ReflectionException $exception) {
                throw $exception;
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('placeholder or test values are not allowed', $exception->getMessage());
            }
        }
    }

    public function test_business_registration_number_placeholder_still_fails(): void
    {
        $payload = $this->validPayload();
        $payload['businessRegistrationNumber'] = '00000000';
        $payload['documents'][0]['identificationNumber'] = '00000000';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('businessRegistrationNumber: placeholder or test values are not allowed.');

        app(NiumKycDataValidator::class)->assertPayload($payload);
    }

    public function test_missing_business_registration_document_number_is_populated_from_profile(): void
    {
        [$user, $document] = $this->businessSource('11223344', null);

        app(NiumKycDataValidator::class)->assertSource($user);

        $this->assertSame('11223344', $document->fresh()->document_number);
    }

    public function test_mismatched_business_registration_document_number_still_fails(): void
    {
        [$user] = $this->businessSource('11223344', '44332211');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('documents.business_registration.identificationNumber: must match businessRegistrationNumber.');

        app(NiumKycDataValidator::class)->assertSource($user);
    }

    public function test_missing_business_registration_number_still_fails(): void
    {
        [$user] = $this->businessSource(null, null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('businessRegistrationNumber');

        app(NiumKycDataValidator::class)->assertSource($user);
    }

    private function businessSource(?string $registrationNumber, ?string $documentNumber): array
    {
        $user = User::factory()->create();
        $profile = $user->kycProfile()->create([
            'status' => 'approved',
            'applicant_type' => 'business',
            'legal_name' => 'Acme Holdings Limited',
            'business_name' => 'Acme Holdings Limited',
            'business_registration_number' => $registrationNumber,
            'registered_country_code' => 'HK',
            'address_line1' => '88 Queensway',
            'city' => 'Hong Kong',
            'state' => 'Hong Kong',
            'postal_code' => '00000',
            'country_code' => 'HK',
            'metadata' => ['nium_region' => 'HK'],
        ]);
        $document = $profile->documents()->create([
            'type' => 'business_registration',
            'status' => 'approved',
            'file_url' => 'https://files.example.test/business-registration.pdf',
            'document_number' => $documentNumber,
            'issuing_country_code' => 'HK',
        ]);

        return [$user, $document];
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
