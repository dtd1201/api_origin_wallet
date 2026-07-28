<?php

namespace Tests\Unit;

use App\Services\Nium\NiumRegionResolver;
use ErrorException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class NiumRegionResolverTest extends TestCase
{
    #[DataProvider('validRegionProvider')]
    public function test_resolves_supported_explicit_regions_and_country_fallbacks(
        mixed $explicitRegion,
        mixed $registeredCountry,
        mixed $residenceCountry,
        mixed $country,
        string $expected,
    ): void {
        $this->assertSame(
            $expected,
            app(NiumRegionResolver::class)->resolve(
                $explicitRegion,
                $registeredCountry,
                $residenceCountry,
                $country,
            ),
        );
    }

    public static function validRegionProvider(): array
    {
        return [
            'null explicit and SG country' => [null, 'SG', null, null, 'SG'],
            'lowercase explicit SG' => ['sg', 'US', null, null, 'SG'],
            'trimmed lowercase explicit SG' => [' sg ', 'US', null, null, 'SG'],
            'explicit US' => ['US', 'SG', null, null, 'US'],
            'explicit EU' => ['EU', 'SG', null, null, 'EU'],
            'explicit UK' => ['UK', 'SG', null, null, 'UK'],
            'explicit NL' => ['NL', 'SG', null, null, 'NL'],
            'explicit AU' => ['AU', 'SG', null, null, 'AU'],
            'explicit NZ' => ['NZ', 'SG', null, null, 'NZ'],
            'explicit CA' => ['CA', 'SG', null, null, 'CA'],
            'explicit HK' => ['HK', 'SG', null, null, 'HK'],
            'explicit JP' => ['JP', 'SG', null, null, 'JP'],
            'explicit MX' => ['MX', 'SG', null, null, 'MX'],
            'explicit BR' => ['BR', 'SG', null, null, 'BR'],
            'explicit ID' => ['ID', 'SG', null, null, 'ID'],
            'GB country fallback' => [null, 'GB', null, null, 'UK'],
            'NL country fallback' => [null, 'NL', null, null, 'NL'],
            'European country fallback' => [null, 'DE', null, null, 'EU'],
            'directly supported country fallback' => [null, 'US', null, null, 'US'],
            'unsupported country fallback' => [null, 'ZZ', null, null, 'SG'],
        ];
    }

    #[DataProvider('invalidExplicitRegionProvider')]
    public function test_invalid_explicit_region_fails_with_one_safe_exception_and_no_runtime_diagnostic(
        mixed $explicitRegion,
    ): void {
        $diagnostics = [];
        set_error_handler(static function (
            int $severity,
            string $message,
        ) use (&$diagnostics): never {
            $diagnostics[] = [$severity, $message];

            throw new ErrorException($message, 0, $severity);
        });

        try {
            app(NiumRegionResolver::class)->resolve($explicitRegion, 'SG', null, null);
            $this->fail('Expected the explicit region to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(NiumRegionResolver::INVALID_REGION, $exception->getMessage());
            $this->assertStringNotContainsString('synthetic_unknown_region', $exception->getMessage());
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $diagnostics);
    }

    public static function invalidExplicitRegionProvider(): array
    {
        return [
            'empty string' => [''],
            'whitespace string' => ['   '],
            'unsupported string' => ['synthetic_unknown_region'],
            'integer zero' => [0],
            'integer one' => [1],
            'boolean false' => [false],
            'boolean true' => [true],
            'empty array' => [[]],
            'list array' => [['synthetic_unknown_region']],
            'associative array' => [['region' => 'synthetic_unknown_region']],
            'nested array' => [[['region' => 'synthetic_unknown_region']]],
            'object' => [(object) ['region' => 'synthetic_unknown_region']],
        ];
    }

    #[DataProvider('invalidCountryProvider')]
    public function test_invalid_country_input_defaults_to_sg_without_runtime_diagnostic(
        mixed $country,
    ): void {
        $diagnostics = [];
        set_error_handler(static function (
            int $severity,
            string $message,
        ) use (&$diagnostics): never {
            $diagnostics[] = [$severity, $message];

            throw new ErrorException($message, 0, $severity);
        });

        try {
            $this->assertSame(
                'SG',
                app(NiumRegionResolver::class)->resolve(null, $country, null, null),
            );
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $diagnostics);
    }

    public static function invalidCountryProvider(): array
    {
        return [
            'integer' => [1],
            'boolean' => [true],
            'array' => [['SG']],
            'object' => [(object) ['country' => 'SG']],
        ];
    }
}
