<?php

namespace Tests\Feature;

use App\Models\FxQuote;
use App\Models\Transfer;
use App\Services\Nium\NiumPayoutFxLockContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class NiumPayoutFxLockContractTest extends TestCase
{
    public function test_binding_comparison_ignores_object_order_but_never_value_types_or_list_order(): void
    {
        $this->assertTrue(NiumPayoutFxLockContract::sameBinding(['a' => 1, 'b' => ['x' => '5', 'y' => true]],
            ['b' => ['y' => true, 'x' => '5'], 'a' => 1]));
        $this->assertFalse(NiumPayoutFxLockContract::sameBinding(['a' => 1], ['a' => '1']));
        $this->assertFalse(NiumPayoutFxLockContract::sameBinding(['a', 'b'], ['b', 'a']));
        $this->assertFalse(NiumPayoutFxLockContract::sameBinding(['a' => null], []));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(10, 0));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        $this->travelBack();
        parent::tearDown();
    }

    private function response(array $overrides = []): array
    {
        return array_replace([
            'audit_id' => '112', 'fx_rate' => '1.32', 'status' => 'ACTIVE',
            'hold_expiry_at' => now()->addMinutes(10)->toISOString(),
            'source_currency' => 'USD', 'destination_currency' => 'SGD',
        ], $overrides);
    }

    private function binding(): array
    {
        return ['client' => 'test-client', 'customer' => 'test-customer', 'wallet' => 'test-wallet', 'base_url' => 'https://nium.test'];
    }

    private function boundModels(): array
    {
        $contract = NiumPayoutFxLockContract::parse($this->response(), 'USD', 'SGD', '10');
        $transfer = new Transfer([
            'user_id' => 11, 'provider_id' => 7, 'source_currency' => 'USD', 'target_currency' => 'SGD',
            'source_amount' => '10', 'fx_rate' => '1.32',
        ]);
        $quote = new FxQuote([
            'user_id' => 11, 'provider_id' => 7, 'source_currency' => 'USD', 'target_currency' => 'SGD',
            'source_amount' => '10', 'net_rate' => '1.32', 'expires_at' => $contract['expires_at'], 'quote_ref' => '112',
            'raw_data' => ['provider_fx_type' => 'payout_fx_lock', 'audit_id' => '112',
                'provider_context' => $this->binding(), 'payout_fx_lock_contract' => $contract],
        ]);

        return [$quote, $transfer];
    }

    public function test_rate_only_response_never_generates_conversion_amount_or_fee(): void
    {
        $contract = NiumPayoutFxLockContract::parse($this->response(), 'USD', 'SGD', '10.00000000');
        $this->assertSame('10', $contract['source_amount']);
        $this->assertSame('1.32', $contract['rate']);
        $this->assertNull($contract['conversion_amount']);
        $this->assertArrayNotHasKey('fee_amount', $contract);
        [$quote, $transfer] = $this->boundModels();
        NiumPayoutFxLockContract::assertMatchesTransfer($quote, $transfer, $this->binding());
    }

    public function test_reported_production_shape_keeps_all_nine_rate_decimals_and_never_reuses_the_historical_audit(): void
    {
        $reported = json_decode(file_get_contents(base_path('tests/Fixtures/nium/usd-sgd-phase42-reported-fx.json')), true, flags: JSON_THROW_ON_ERROR);
        $response = $reported['reported_response_fields_without_identifiers'] + [
            // Synthetic stand-ins, never the actual Production hold identifiers.
            'audit_id' => 4242001, 'fx_hold_id' => 'synthetic-hold-only',
            'markup_rate' => '0', 'ecb_fx_rate' => '0', 'additionalFxMarkup' => '0',
        ];
        $this->travelTo(Carbon::parse('2026-10-30T07:00:00Z'));
        $contract = NiumPayoutFxLockContract::parse($response, 'USD', 'SGD', '5.00000000');
        $this->assertSame('1.275939120', $contract['rate']);
        $this->assertSame('4242001', $contract['audit_id']);
        $this->assertSame('2026-10-30T07:25:03.000000Z', $contract['expires_at']);
        $this->assertSame(2, $contract['version']);
        $this->assertNull($contract['conversion_amount']);
        $this->assertArrayNotHasKey('fee_amount', $contract);
        $this->travelTo(Carbon::parse('2026-10-30T07:25:04Z'));
        $this->expectException(RuntimeException::class);
        NiumPayoutFxLockContract::parse($response, 'USD', 'SGD', '5');
    }

    public static function incompleteSgdResponses(): array
    {
        return [
            'missing status' => ['status', null], 'wrong status' => ['status', 'EXPIRED'],
            'missing source' => ['source_currency', null], 'missing destination' => ['destination_currency', null],
            'wrong source' => ['source_currency', 'SGD'], 'wrong destination' => ['destination_currency', 'USD'],
            'binary float rate' => ['fx_rate', 1.275939120],
        ];
    }

    #[DataProvider('incompleteSgdResponses')]
    public function test_sgd_requires_explicit_active_currency_bound_exact_rate_evidence(string $key, mixed $value): void
    {
        $response = $this->response();
        if ($value === null) {
            unset($response[$key]);
        } else {
            $response[$key] = $value;
        }
        $this->expectException(RuntimeException::class);
        NiumPayoutFxLockContract::parse($response, 'USD', 'SGD', '5');
    }

    public function test_consumed_hold_cannot_be_reused(): void
    {
        [$quote, $transfer] = $this->boundModels();
        $quote->raw_data = array_merge($quote->raw_data, ['consumed_at' => now()->toISOString()]);
        $this->expectException(RuntimeException::class);
        NiumPayoutFxLockContract::assertMatchesTransfer($quote, $transfer, $this->binding());
    }

    public function test_documented_endpoint_example_aliases_preserve_explicit_provider_conversion_amount(): void
    {
        $contract = NiumPayoutFxLockContract::parse([
            'auditId' => '112', 'exchangeRate' => '1.32', 'expiresAt' => now()->addMinutes(10)->toISOString(), 'status' => 'ACTIVE',
            'sourceAmount' => 10, 'destinationAmount' => '13.15', 'sourceCurrency' => 'USD', 'destinationCurrency' => 'SGD',
        ], 'USD', 'SGD', '10');
        $this->assertSame('13.15', $contract['conversion_amount']);
        $this->assertSame('1.32', $contract['rate']);
    }

    public function test_response_amount_without_explicit_source_amount_binding_remains_unavailable(): void
    {
        $contract = NiumPayoutFxLockContract::parse($this->response(['destinationAmount' => '13.15']), 'USD', 'SGD', '10');
        $this->assertNull($contract['conversion_amount']);
    }

    public static function invalidResponses(): array
    {
        return [
            'conflicting audit aliases' => [['auditId' => '113']],
            'modern ID' => [['audit_id' => 'quote-modern']],
            'conflicting rate aliases' => [['fxRate' => '1.33']],
            'conflicting example rate' => [['exchangeRate' => '1.33']],
            'non-positive rate' => [['fx_rate' => '0']],
            'infinite rate' => [['fx_rate' => 'INF']],
            'rate would lose precision' => [['fx_rate' => '1.12345678901']],
            'wrong camel source' => [['sourceCurrency' => 'SGD']],
            'wrong camel destination' => [['destinationCurrency' => 'USD']],
            'wrong response amount' => [['sourceAmount' => '10.00000001']],
            'relative expiry' => [['hold_expiry_at' => '+10 minutes']],
            'expiry without timezone' => [['hold_expiry_at' => '2026-10-30T11:00:00']],
            'invalid calendar date' => [['hold_expiry_at' => '2026-02-30T11:00:00Z']],
            'conflicting expiries' => [['expiresAt' => '2026-10-30T10:20:00Z']],
            'expires exactly now' => [['hold_expiry_at' => '2026-10-30T10:00:00Z']],
            'expires before next storable second' => [['hold_expiry_at' => '2026-10-30T10:00:00.500Z']],
        ];
    }

    #[DataProvider('invalidResponses')]
    public function test_ambiguous_or_mismatched_provider_response_is_rejected(array $overrides): void
    {
        $this->expectException(RuntimeException::class);
        NiumPayoutFxLockContract::parse($this->response($overrides), 'USD', 'SGD', '10');
    }

    public static function mismatchedBindings(): array
    {
        return [
            'wrong client' => ['client'], 'wrong customer' => ['customer'],
            'wrong wallet' => ['wallet'], 'wrong environment' => ['base_url'],
        ];
    }

    #[DataProvider('mismatchedBindings')]
    public function test_lock_is_rejected_when_provider_context_changes(string $field): void
    {
        [$quote, $transfer] = $this->boundModels();
        $context = array_replace($this->binding(), [$field => 'changed']);
        $this->expectException(RuntimeException::class);
        NiumPayoutFxLockContract::assertMatchesTransfer($quote, $transfer, $context);
    }

    public function test_exact_amount_comparison_detects_changes_beyond_float_precision(): void
    {
        [$quote, $transfer] = $this->boundModels();
        $quote->source_amount = '999999999999.00000001';
        $transfer->source_amount = '999999999999.00000002';
        $this->expectException(RuntimeException::class);
        NiumPayoutFxLockContract::assertMatchesTransfer($quote, $transfer, $this->binding());
    }

    public function test_sgd_lock_without_context_is_never_accepted(): void
    {
        [$quote, $transfer] = $this->boundModels();
        $quote->raw_data = ['provider_fx_type' => 'payout_fx_lock', 'audit_id' => '112'];
        $this->expectException(RuntimeException::class);
        NiumPayoutFxLockContract::assertMatchesTransfer($quote, $transfer, $this->binding());
    }
}
