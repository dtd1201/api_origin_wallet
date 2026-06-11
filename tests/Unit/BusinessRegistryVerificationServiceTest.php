<?php

namespace Tests\Unit;

use App\Services\Kyc\BusinessRegistryVerificationService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BusinessRegistryVerificationServiceTest extends TestCase
{
    public function test_it_verifies_eu_vat_number_through_vies(): void
    {
        config([
            'services.business_registry.eu_vies.endpoint' => 'https://vies.test/checkVatService',
            'services.business_registry.eu_vies.countries' => ['DE'],
        ]);

        Http::fake([
            'https://vies.test/checkVatService' => Http::response($this->viesResponse(valid: true), 200),
        ]);

        $result = app(BusinessRegistryVerificationService::class)
            ->verify('DE', null, 'DE123456789', 'Acme GmbH');

        $this->assertSame('verified', $result['status']);
        $this->assertSame('European Commission VIES', $result['source']);
        $this->assertSame('DE', $result['country_code']);
        $this->assertSame('123456789', $result['identifier']);
    }

    public function test_it_marks_vies_invalid_response_as_invalid(): void
    {
        config([
            'services.business_registry.eu_vies.endpoint' => 'https://vies.test/checkVatService',
            'services.business_registry.eu_vies.countries' => ['DE'],
        ]);

        Http::fake([
            'https://vies.test/checkVatService' => Http::response($this->viesResponse(valid: false), 200),
        ]);

        $result = app(BusinessRegistryVerificationService::class)
            ->verify('DE', null, 'DE000000000', 'Acme GmbH');

        $this->assertSame('invalid', $result['status']);
        $this->assertSame('VAT number was not found as valid in VIES.', $result['message']);
    }

    public function test_australia_requires_abn_lookup_guid_for_live_lookup(): void
    {
        config(['services.business_registry.au.guid' => null]);

        $result = app(BusinessRegistryVerificationService::class)
            ->verify('AU', '19415776361', null, 'Apple Australia Pty Limited');

        $this->assertSame('unavailable', $result['status']);
        $this->assertSame('Australian Business Register ABN Lookup', $result['source']);
    }

    public function test_vietnam_official_public_lookup_is_reported_as_unavailable_for_automation(): void
    {
        $result = app(BusinessRegistryVerificationService::class)
            ->verify('VN', '0312345678', '0312345678', 'Cong ty TNHH Test');

        $this->assertSame('unavailable', $result['status']);
        $this->assertSame('Vietnam General Department of Taxation', $result['source']);
    }

    private function viesResponse(bool $valid): string
    {
        $validValue = $valid ? 'true' : 'false';

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/">
  <env:Body>
    <ns2:checkVatResponse xmlns:ns2="urn:ec.europa.eu:taxud:vies:services:checkVat:types">
      <ns2:countryCode>DE</ns2:countryCode>
      <ns2:vatNumber>123456789</ns2:vatNumber>
      <ns2:requestDate>2026-06-05+07:00</ns2:requestDate>
      <ns2:valid>{$validValue}</ns2:valid>
      <ns2:name>Acme GmbH</ns2:name>
      <ns2:address>Berlin</ns2:address>
    </ns2:checkVatResponse>
  </env:Body>
</env:Envelope>
XML;
    }
}
