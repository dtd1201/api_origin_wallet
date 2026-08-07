<?php

namespace Tests\Unit;

use App\Services\Nium\NiumHkCustomerPayloadGate;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class NiumHkCustomerPayloadGateTest extends TestCase
{
    public function test_configured_factual_and_payload_hk_pass_together(): void
    {
        NiumHkCustomerPayloadGate::assertRegions('HK', 'HK', 'HK', 'HK', 'HK', 'HK');
        $this->addToAssertionCount(1);
    }

    #[DataProvider('mismatches')]
    public function test_any_configured_or_factual_mismatch_fails_closed(array $regions): void
    {
        $this->expectException(RuntimeException::class);
        NiumHkCustomerPayloadGate::assertRegions(...$regions);
    }

    public static function mismatches(): array
    {
        return [
            'configured unset with factual HK' => [[null, 'HK', 'HK', 'HK', 'HK', 'HK']],
            'configured SG with factual HK' => [['SG', 'HK', 'HK', 'HK', 'HK', 'HK']],
            'configured HK with factual SG' => [['HK', 'SG', 'SG', 'SG', 'HK', 'HK']],
        ];
    }

    public function test_snapshot_base64_round_trip_is_exact_and_malformed_input_fails(): void
    {
        $snapshot = json_encode([['id' => 18, 'value' => "quote's \"safe\""]], JSON_THROW_ON_ERROR);
        $encoded = base64_encode($snapshot);
        $this->assertSame($snapshot, base64_decode($encoded, true));
        $this->assertFalse(base64_decode('%%%not-base64%%%', true));
        $this->assertNotSame($snapshot, base64_decode(base64_encode('[{"id":19}]'), true));
    }
}
