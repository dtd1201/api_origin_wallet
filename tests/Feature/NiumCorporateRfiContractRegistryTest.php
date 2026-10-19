<?php

namespace Tests\Feature;

use App\Services\Nium\NiumCorporateRfiContractRegistry;
use ReflectionMethod;
use Tests\TestCase;

class NiumCorporateRfiContractRegistryTest extends TestCase
{
    public function test_verified_production_other_document_identity_matches_exactly(): void
    {
        $expected = [
            'environment' => 'production',
            'endpoint' => '/api/v1/client/{clientHashId}/corporate/rfi',
            'method' => 'POST',
            'request_schema_version' => 'legacy-corporate-rfi-v1-hk-operator-reviewed-20261016',
            'contract_version' => 'legacy-corporate-rfi-v1-hk-operator-reviewed-20261016',
            'template_fingerprint' => 'cec6ae9c8b0fe4dc77867126695a05708002289f9cce70ad0458581758d1d964',
            'required_field_shape_fingerprint' => '4c3e3dbb248a6d9f1ae0771060169601e90a3b1c20dc79a8d6a45333e0dc9fce',
        ];

        $this->assertSame($expected, $this->productionEntry());
        $this->assertTrue($this->contractRegistryMatches($expected, $this->productionEntry()));
        $this->assertCount(1, config('nium_rfi_contracts.corporate'));
    }

    public function test_verified_production_other_document_identity_rejects_every_mismatch(): void
    {
        $entry = $this->productionEntry();
        $mismatches = [
            'environment' => 'staging',
            'endpoint' => '/api/v1/client/{clientHashId}/corporate/rfi/changed',
            'method' => 'PUT',
            'request_schema_version' => 'unreviewed-request-version',
            'contract_version' => 'unreviewed-response-version',
            'template_fingerprint' => str_repeat('0', 64),
            'required_field_shape_fingerprint' => str_repeat('1', 64),
        ];

        foreach ($mismatches as $field => $value) {
            $this->assertFalse(
                $this->contractRegistryMatches($entry, [...$entry, $field => $value]),
                "Registry unexpectedly accepted mismatched {$field}.",
            );
        }
    }

    public function test_business_registration_template_is_not_registered(): void
    {
        $this->assertSame(
            ['cec6ae9c8b0fe4dc77867126695a05708002289f9cce70ad0458581758d1d964'],
            collect(config('nium_rfi_contracts.corporate'))->pluck('template_fingerprint')->all(),
        );
    }

    private function productionEntry(): array
    {
        return config('nium_rfi_contracts.corporate.0');
    }

    private function contractRegistryMatches(array $expected, array $actual): bool
    {
        $method = new ReflectionMethod(NiumCorporateRfiContractRegistry::class, 'matches');
        $method->setAccessible(true);

        return $method->invoke(app(NiumCorporateRfiContractRegistry::class), $expected, $actual);
    }
}
