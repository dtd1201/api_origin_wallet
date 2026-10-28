<?php

namespace Tests\Unit;

use App\Services\Nium\NiumVirtualAccountAssignmentContract;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NiumVirtualAccountAssignmentContractTest extends TestCase
{
    #[DataProvider('supportedCategories')]
    public function test_supported_categories_preserve_the_version_specific_payload(string $version, string $category): void
    {
        $payload = NiumVirtualAccountAssignmentContract::payload($version, 'USD', $category, 'WIRES', 'JPM_SG');
        NiumVirtualAccountAssignmentContract::assertPayload($version, $payload, $version === 'v1' ? 'WIRES' : null);

        $this->assertSame($version === 'v1'
            ? ['currencyCode' => 'USD', 'accountCategory' => $category, 'bankName' => 'JPM_SG']
            : ['currency' => 'USD', 'accountCategory' => $category, 'accountType' => 'WIRES', 'bankName' => 'JPM_SG'], $payload);
    }

    public static function supportedCategories(): array
    {
        return [
            ['v1', 'SELF_FUNDING_ACCOUNT'], ['v1', 'COLLECTION_ACCOUNT'],
            ['v2', 'SELF_FUNDING_ACCOUNT'], ['v2', 'COLLECTION_ACCOUNT'], ['v2', 'SELF_FUNDING_AND_COLLECTION_ACCOUNT'],
        ];
    }

    #[DataProvider('unsupportedV1Categories')]
    public function test_v1_rejects_unsupported_categories_including_the_literal_null(mixed $category): void
    {
        $this->expectException(InvalidArgumentException::class);
        NiumVirtualAccountAssignmentContract::assertPayload('v1', [
            'currencyCode' => 'USD', 'accountCategory' => $category, 'bankName' => 'JPM_SG',
        ], 'WIRES');
    }

    public static function unsupportedV1Categories(): array
    {
        return [['SELF_FUNDING_AND_COLLECTION_ACCOUNT'], ['Null'], [null], ['UNSUPPORTED'], [''], ['SELF_FUNDING_ACCOUNT ']];
    }
}
