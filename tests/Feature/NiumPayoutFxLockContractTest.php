<?php

namespace Tests\Feature;

use App\Models\FxQuote;
use App\Models\Transfer;
use App\Services\Nium\NiumPayoutFxLockContract;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class NiumPayoutFxLockContractTest extends TestCase
{
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

    public function test_reordered_persisted_context_and_snapshot_objects_keep_the_same_binding(): void
    {
        [$quote, $transfer] = $this->boundModels();
        $data = $quote->raw_data;
        $data['provider_context'] = array_reverse($data['provider_context'], true);
        $data['payout_fx_lock_contract'] = array_reverse($data['payout_fx_lock_contract'], true);
        $quote->raw_data = $data;

        $this->assertNotSame($this->binding(), $quote->raw_data['provider_context']);
        NiumPayoutFxLockContract::assertMatchesTransfer($quote, $transfer, $this->binding());
    }

    public static function strictBindingComparisons(): array
    {
        return [
            'nested object order' => [
                ['items' => [['wallet' => 'w', 'client' => 'c'], ['amount' => '10', 'currency' => 'USD']]],
                ['items' => [['client' => 'c', 'wallet' => 'w'], ['currency' => 'USD', 'amount' => '10']]], true,
            ],
            'ordered list changed' => [['a', 'b'], ['b', 'a'], false],
            'ordered object list changed' => [[['id' => 'a'], ['id' => 'b']], [['id' => 'b'], ['id' => 'a']], false],
            'list versus object' => [['a', 'b'], ['first' => 'a', 'second' => 'b'], false],
            'list versus sparse keys' => [['a', 'b'], [0 => 'a', 2 => 'b'], false],
            'string versus integer' => [['amount' => '10'], ['amount' => 10], false],
            'integer versus float' => [['version' => 1], ['version' => 1.0], false],
            'string versus boolean' => [['client' => 'c'], ['client' => true], false],
            'missing null member' => [['id' => 'a', 'optional' => null], ['id' => 'a'], false],
            'additional null member' => [['id' => 'a'], ['id' => 'a', 'optional' => null], false],
        ];
    }

    #[DataProvider('strictBindingComparisons')]
    public function test_binding_comparison_preserves_list_order_and_scalar_types(array $actual, array $expected, bool $matches): void
    {
        $this->assertSame($matches, NiumPayoutFxLockContract::sameBinding($actual, $expected));
    }

    public static function malformedPersistedBindingNodes(): array
    {
        $values = [
            'null' => [null], 'boolean' => [false], 'string' => ['test-client'], 'empty' => [[]],
            'ordered list instead of object' => [['test-client', 'test-customer', 'test-wallet', 'https://nium.test']],
        ];
        $cases = [];
        foreach (['provider_context', 'payout_fx_lock_contract'] as $node) {
            foreach ($values as $name => [$value]) {
                $cases[$node.' '.$name] = [$node, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('malformedPersistedBindingNodes')]
    public function test_malformed_persisted_binding_node_is_rejected(string $node, mixed $value): void
    {
        [$quote, $transfer] = $this->boundModels();
        NiumPayoutFxLockContract::assertMatchesTransfer($quote, $transfer, $this->binding());
        $data = $quote->raw_data;
        $data[$node] = $value;
        $quote->raw_data = $data;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nium payout FX lock client, customer, wallet or response binding does not match.');
        NiumPayoutFxLockContract::assertMatchesTransfer($quote, $transfer, $this->binding());
    }

    public static function invalidPersistedContextMembers(): array
    {
        $cases = [
            'additional identity alias' => [['clientHashId' => 'test-client'], []],
            'renamed identity with same member count' => [['clientHashId' => 'test-client'], ['client']],
            'nested object instead of wallet' => [['wallet' => ['id' => 'test-wallet']], []],
            'ordered list instead of wallet' => [['wallet' => ['test-wallet']], []],
        ];
        foreach (['client', 'customer', 'wallet', 'base_url'] as $field) {
            $cases['missing '.$field] = [[], [$field]];
            $cases['changed '.$field] = [[$field => 'changed'], []];
            $cases['null '.$field] = [[$field => null], []];
            $cases['boolean '.$field] = [[$field => true], []];
        }

        return $cases;
    }

    #[DataProvider('invalidPersistedContextMembers')]
    public function test_persisted_context_requires_exact_identity_members(array $changes, array $removed): void
    {
        [$quote, $transfer] = $this->boundModels();
        NiumPayoutFxLockContract::assertMatchesTransfer($quote, $transfer, $this->binding());
        $data = $quote->raw_data;
        $data['provider_context'] = array_replace($data['provider_context'], $changes);
        foreach ($removed as $key) {
            unset($data['provider_context'][$key]);
        }
        $quote->raw_data = $data;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nium payout FX lock client, customer, wallet or response binding does not match.');
        NiumPayoutFxLockContract::assertMatchesTransfer($quote, $transfer, $this->binding());
    }

    public function test_documented_endpoint_example_aliases_preserve_explicit_provider_conversion_amount(): void
    {
        $contract = NiumPayoutFxLockContract::parse([
            'auditId' => '112', 'exchangeRate' => 1.32, 'expiresAt' => now()->addMinutes(10)->toISOString(),
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
        NiumPayoutFxLockContract::assertMatchesTransfer($quote, $transfer, $this->binding());
        $context = array_replace($this->binding(), [$field => 'changed']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nium payout FX lock client, customer, wallet or response binding does not match.');
        NiumPayoutFxLockContract::assertMatchesTransfer($quote, $transfer, $context);
    }

    public static function changedResponseBindings(): array
    {
        return [
            'version' => ['version', 2],
            'audit ID' => ['audit_id', '113'],
            'source currency' => ['source_currency', 'EUR'],
            'target currency' => ['target_currency', 'EUR'],
            'source amount' => ['source_amount', '10.00000001'],
            'rate' => ['rate', '1.3200000001'],
            'expiry' => ['expires_at', '2026-10-30T10:11:00.000000Z'],
            'string version' => ['version', '1'],
            'integer audit ID' => ['audit_id', 112],
            'integer source amount' => ['source_amount', 10],
            'float rate' => ['rate', 1.32],
            'malformed expiry' => ['expires_at', ['value' => '2026-10-30T10:10:00.000000Z']],
        ];
    }

    #[DataProvider('changedResponseBindings')]
    public function test_changed_stored_response_binding_is_rejected(string $field, mixed $value): void
    {
        [$quote, $transfer] = $this->boundModels();
        // First prove the control is valid, so an unrelated broken fixture cannot
        // make every negative case pass for the wrong reason.
        NiumPayoutFxLockContract::assertMatchesTransfer($quote, $transfer, $this->binding());
        $data = $quote->raw_data;
        $this->assertNotSame($value, $data['payout_fx_lock_contract'][$field]);
        $data['payout_fx_lock_contract'][$field] = $value;
        $quote->raw_data = $data;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nium payout FX lock client, customer, wallet or response binding does not match.');
        NiumPayoutFxLockContract::assertMatchesTransfer($quote, $transfer, $this->binding());
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
