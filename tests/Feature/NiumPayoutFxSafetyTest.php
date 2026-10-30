<?php

namespace Tests\Feature;

use App\Models\FxQuote;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Nium\NiumPayoutAuditId;
use App\Services\Nium\NiumPayoutFxLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class NiumPayoutFxSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('services.nium.base_url', 'https://nium.test');
        config()->set('services.nium.client_id', 'client-test');
        config()->set('services.nium.auth', ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'test-only']);
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'test-only');
        config()->set('services.nium.payout_fx_enabled', true);
    }

    public static function invalidLockResponses(): array
    {
        return [
            'no audit' => [['audit_id' => null]],
            'modern quote ID' => [['audit_id' => 'quote-modern-123']],
            'exponent audit' => [['audit_id' => '1e3']],
            'decimal audit' => [['audit_id' => '112.5']],
            'zero audit' => [['audit_id' => 0]],
            'negative audit' => [['audit_id' => -112]],
            'overflow audit' => [['audit_id' => '9223372036854775808']],
            'zero rate' => [['fx_rate' => 0]],
            'negative rate' => [['fx_rate' => -1]],
            'no rate' => [['fx_rate' => null]],
            'no expiry' => [['hold_expiry_at' => null]],
            'invalid expiry' => [['hold_expiry_at' => 'not-an-expiry']],
            'expired' => [['hold_expiry_at' => '2020-01-01T00:00:00Z']],
            'inactive' => [['status' => 'EXPIRED']],
            'wrong source currency' => [['source_currency' => 'SGD']],
            'wrong destination currency' => [['destination_currency' => 'SGD']],
            'conflicting audit aliases' => [['auditId' => '113']],
            'conflicting rate aliases' => [['fxRate' => '0.916']],
            'conflicting example rate' => [['exchangeRate' => '0.916']],
            'wrong camel source currency' => [['sourceCurrency' => 'SGD']],
            'wrong camel destination currency' => [['destinationCurrency' => 'SGD']],
            'wrong response amount' => [['sourceAmount' => '10.00000001']],
            'relative expiry' => [['hold_expiry_at' => '+10 minutes']],
            'HTTP failure' => [[], 503],
        ];
    }

    #[DataProvider('invalidLockResponses')]
    public function test_invalid_legacy_lock_is_never_persisted(array $overrides, int $status = 200): void
    {
        $provider = IntegrationProvider::query()->create(['code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        $user = User::factory()->create();
        $user->providerAccounts()->create([
            'provider_id' => $provider->id, 'external_customer_id' => 'customer-test', 'external_account_id' => 'wallet-test',
            'status' => 'active', 'provider_status' => 'clear', 'reconciliation_status' => 'reconciled',
            'customer_id_verified_at' => now(), 'wallet_id_verified_at' => now(), 'provider_ids_verified_at' => now(),
        ]);
        Http::fake(['*lockExchangeRate*' => Http::response(array_replace([
            'audit_id' => 112, 'fx_rate' => '0.915', 'hold_expiry_at' => now()->addMinutes(10)->toISOString(), 'status' => 'ACTIVE',
        ], $overrides), $status)]);

        $this->expectException(RuntimeException::class);
        try {
            // Existing legacy transport fixture; this does not admit a new payout corridor.
            app(NiumPayoutFxLockService::class)->createLock($provider, $user, 'USD', 'EUR', 10);
        } finally {
            $this->assertSame(0, FxQuote::query()->count());
            Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        }
    }

    public static function invalidAuditBindings(): array
    {
        return [
            'mismatched authoritative ID' => ['112', '113', 'payout_fx_lock'],
            'missing authoritative ID' => ['112', null, 'payout_fx_lock'],
            'modern quote with numeric ID' => ['112', '112', 'modern_quote'],
            'modern quote with string ID' => ['quote-modern', '112', 'modern_quote'],
            'fractional ID' => ['112.5', '112.5', 'payout_fx_lock'],
            'exponent ID' => ['1e3', '1e3', 'payout_fx_lock'],
        ];
    }

    #[DataProvider('invalidAuditBindings')]
    public function test_only_matching_authoritative_integer_audit_ids_can_be_serialized(string $reference, ?string $auditId, string $type): void
    {
        $quote = new FxQuote(['quote_ref' => $reference, 'raw_data' => ['provider_fx_type' => $type, 'audit_id' => $auditId]]);
        $this->expectException(RuntimeException::class);
        NiumPayoutAuditId::fromQuote($quote);
    }

    public function test_valid_authoritative_audit_id_is_serialized_as_integer(): void
    {
        $quote = new FxQuote(['quote_ref' => '112', 'raw_data' => ['provider_fx_type' => 'payout_fx_lock', 'audit_id' => '112']]);
        $this->assertSame(112, NiumPayoutAuditId::fromQuote($quote));
    }
}
