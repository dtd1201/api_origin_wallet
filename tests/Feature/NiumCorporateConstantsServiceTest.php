<?php

namespace Tests\Feature;

use App\Models\NiumCorporateConstant;
use App\Models\User;
use App\Services\Nium\NiumCorporateConstantsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NiumCorporateConstantsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.nium.base_url', 'https://gateway.sandbox.nium.com');
        config()->set('services.nium.client_id', 'client_hash_123');
        config()->set('services.nium.api_key', 'test-key');
        config()->set('services.nium.corporate_constants_endpoint', '/api/v2/client/{clientHashId}/onboarding/constants');
        config()->set('services.nium.auth', [
            'mode' => 'header',
            'header_name' => 'x-api-key',
            'header_value' => 'test-key',
        ]);
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'partner-key');
    }

    public function test_country_name_response_creates_iso_country_options(): void
    {
        Http::fake(['*' => Http::response([
            ['description' => 'Hong Kong', 'code' => 'HK'],
        ])]);

        $user = User::factory()->create();
        $service = app(NiumCorporateConstantsService::class);

        $this->assertSame([['label' => 'Hong Kong', 'value' => 'HK']], $service->values($user, 'HK', 'countryName')['values']);
        $this->assertSame('cache', $service->values($user, 'HK', 'countryName')['source']);
        $this->assertDatabaseHas('nium_corporate_constants', [
            'region' => 'HK',
            'customer_type' => 'CORPORATE',
            'country_code' => '',
            'constant_type' => 'countryName',
        ]);
        Http::assertSentCount(1);
    }

    public function test_unknown_subdivision_category_is_rejected_without_calling_nium(): void
    {
        Http::fake();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported Nium corporate constant category.');

        app(NiumCorporateConstantsService::class)
            ->values(User::factory()->create(), 'HK', 'isoState', 'HK');
    }
}
