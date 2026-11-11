<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Nium\NiumDynamicQuoteFoundation;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

final class NiumDynamicQuoteFoundationTest extends TestCase
{
    public function test_default_off_does_not_load_evidence_or_publish_work(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        Queue::fake();
        // Evaluate defaults with an empty in-memory environment; preserve the real
        // environment repository even when the operator has explicitly set flags.
        $property = new \ReflectionProperty(\Illuminate\Support\Env::class, 'repository');
        $saved = $property->getValue();
        $property->setValue(null, \Dotenv\Repository\RepositoryBuilder::createWithNoAdapters()->make());
        try {
            $defaults = require __DIR__.'/../../config/nium_dynamic_quotes.php';
            $this->assertSame(['quote_contract_enabled' => false, 'confirmation_contract_enabled' => false], $defaults);
        } finally {
            $property->setValue(null, $saved);
        }
        config(['nium_dynamic_quotes.quote_contract_enabled' => false,
            'nium_dynamic_quotes.confirmation_contract_enabled' => false]);
        $called = false;
        $factory = function () use (&$called): never {
            $called = true;
            throw new InvalidArgumentException('must-not-load');
        };
        $service = new NiumDynamicQuoteFoundation;
        $this->assertSame('UNAVAILABLE', $service->review($factory)['status']);
        $this->assertSame('UNAVAILABLE', $service->confirm('synthetic-user', 'untrusted-client-hash',
            new DateTimeImmutable('2030-01-01T00:10:00Z'), $factory)['status']);
        $this->assertFalse($called);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_flags_do_not_supply_missing_provider_evidence_or_activate_options(): void
    {
        config(['nium_dynamic_quotes.quote_contract_enabled' => true,
            'nium_dynamic_quotes.confirmation_contract_enabled' => true]);
        $service = new NiumDynamicQuoteFoundation;
        $result = $service->review();
        $this->assertSame('UNAVAILABLE', $result['status']);
        $this->assertSame(['trusted_payout_evidence_unavailable'], $result['reason_codes']);
        $this->assertSame('DENIED', $result['dispatch']);
        $this->assertSame('UNAVAILABLE', $service->review(fn () => ['fee' => '0', 'rate' => '1'])['status']);
        $this->assertSame('UNAVAILABLE', $service->review(function (): never {
            throw new InvalidArgumentException('synthetic-sensitive-provider-diagnostic');
        })['status']);
        foreach (app('router')->getRoutes() as $route) {
            $this->assertStringNotContainsString('dynamic-transfer-options', $route->uri());
            $this->assertStringNotContainsString('dynamic-transfers', $route->uri());
        }
    }
}
