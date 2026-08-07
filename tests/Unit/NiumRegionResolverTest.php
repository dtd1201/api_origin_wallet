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
        ];
    }

    public function test_configured_regulatory_region_requires_consistent_factual_country(): void
    {
        config()->set('services.nium.regulatory_region', ' hk ');
        $resolver = app(NiumRegionResolver::class);

        $this->assertSame('HK', $resolver->resolve(null, 'HK', null, 'HK'));
        $this->assertSame('HK', $resolver->resolve('hk', 'HK', null, 'HK'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(NiumRegionResolver::REGION_MISMATCH);
        $resolver->resolve(null, 'SG', null, 'SG');
    }

    #[DataProvider('configuredRegionCountryProvider')]
    public function test_configured_region_accepts_its_canonical_factual_country(
        string $configuredRegion,
        string $factualCountry,
        string $expectedRegion,
    ): void {
        config()->set('services.nium.regulatory_region', $configuredRegion);

        $this->assertSame(
            $expectedRegion,
            app(NiumRegionResolver::class)->resolve(null, $factualCountry, null, null),
        );
    }

    public static function configuredRegionCountryProvider(): array
    {
        return [
            'HK company' => ['HK', 'HK', 'HK'],
            'SG company' => ['SG', 'SG', 'SG'],
            'UK company' => ['UK', 'GB', 'UK'],
        ];
    }

    public function test_explicit_region_conflicting_with_configured_region_fails(): void
    {
        config()->set('services.nium.regulatory_region', 'HK');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(NiumRegionResolver::REGION_MISMATCH);

        app(NiumRegionResolver::class)->resolve('SG', 'HK', null, null);
    }

    public function test_validation_classifier_cannot_turn_configured_country_mismatch_into_sg(): void
    {
        config()->set('services.nium.regulatory_region', 'HK');

        $this->assertSame(
            NiumRegionResolver::INVALID_REGION,
            app(NiumRegionResolver::class)->resolveForValidation(null, 'SG', null, null),
        );
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
    public function test_invalid_country_input_fails_without_implicit_sg_fallback_or_runtime_diagnostic(
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
            app(NiumRegionResolver::class)->resolve(null, $country, null, null);
            $this->fail('Expected invalid country input to fail without an SG fallback.');
        } catch (RuntimeException $exception) {
            $this->assertSame(NiumRegionResolver::INVALID_REGION, $exception->getMessage());
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
            'unsupported string' => ['ZZ'],
            'missing' => [null],
        ];
    }
}
