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
        config()->set('services.nium.corporate_constants_endpoint', '/api/v5/client/{clientHashId}/corporate/constants');
        config()->set('services.nium.auth', [
            'mode' => 'header',
            'header_name' => 'x-api-key',
            'header_value' => 'test-key',
        ]);
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'partner-key');
    }

    public function test_fetches_and_caches_vietnam_state_constants(): void
    {
        Http::fake(['*' => Http::response([
            ['name' => 'Phu Yen', 'value' => 'VN-70'],
        ])]);

        $user = User::factory()->create();
        $service = app(NiumCorporateConstantsService::class);

        $this->assertSame('VN-70', $service->subdivisions($user, 'HK', 'VN')['values'][0]['value']);
        $this->assertSame('cache', $service->subdivisions($user, 'HK', 'VN')['source']);
        $this->assertDatabaseHas('nium_corporate_constants', [
            'region' => 'HK',
            'customer_type' => 'CORPORATE',
            'country_code' => 'VN',
            'constant_type' => 'STATE',
        ]);
        Http::assertSentCount(1);
    }

    public function test_successful_empty_result_is_cached_without_fallback(): void
    {
        Http::fake(['*' => Http::response([])]);

        $result = app(NiumCorporateConstantsService::class)
            ->subdivisions(User::factory()->create(), 'HK', 'ZZ');

        $this->assertSame([], $result['values']);
        $this->assertSame('nium', $result['source']);
        $this->assertSame([], NiumCorporateConstant::query()->firstOrFail()->values);
    }
}
