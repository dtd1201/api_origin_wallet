<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Nium\DynamicConfirmation\DynamicReservationLedger;
use App\Services\Nium\DynamicConfirmation\DynamicTransferConfirmationService;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

final class NiumDynamicConfirmationBoundaryTest extends TestCase
{
    public function test_defaults_are_off_without_resolving_evidence_or_touching_the_database(): void
    {
        $property = new ReflectionProperty(Env::class, 'repository');
        $saved = $property->getValue();
        $property->setValue(null, \Dotenv\Repository\RepositoryBuilder::createWithNoAdapters()->make());
        try {
            $defaults = require __DIR__.'/../../config/nium_dynamic_confirmation.php';
        } finally {
            $property->setValue(null, $saved);
        }
        $this->assertSame(['enabled' => false, 'automatic_dispatch_enabled' => false], $defaults);
        config(['nium_dynamic_confirmation' => $defaults]);
        Http::preventStrayRequests();
        Http::fake([]);
        Queue::fake();
        Bus::fake();

        try {
            app(DynamicTransferConfirmationService::class)->confirm(new User, 1, 1, 1, 'client-ref', str_repeat('a', 64));
            $this->fail('Default-off confirmation was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('dynamic_durable_confirmation_disabled', $exception->getMessage());
        }
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
    }

    public function test_production_dispatch_flag_requires_explicit_operator_authorization(): void
    {
        config(['nium_dynamic_confirmation.enabled' => true,
            'nium_dynamic_confirmation.automatic_dispatch_enabled' => true,
            'nium_dynamic_quotes.quote_contract_enabled' => true,
            'nium_dynamic_quotes.confirmation_contract_enabled' => true,
            'services.nium.base_url' => 'https://gateway.nium.com',
            'nium_dynamic_payouts.live_automatic_dispatch_authorized' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dynamic_live_dispatch_requires_operator_authorization');
        (new DynamicTransferConfirmationService(new DynamicReservationLedger))->confirm(
            new User, 1, 1, 1, 'client-ref', str_repeat('a', 64));
    }

    public function test_legacy_confirmation_paths_remain_unregistered_and_origin_admission_is_bound(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            $this->assertStringNotContainsString('dynamic-intents', $route->uri());
            $this->assertStringNotContainsString('dynamic-confirmation', $route->uri());
        }
        $this->assertInstanceOf(\App\Services\Nium\DynamicConfirmation\OriginDynamicPayoutEvidenceSource::class,
            app(\App\Services\Nium\DynamicConfirmation\DynamicPayoutEvidenceSource::class));
    }
}
