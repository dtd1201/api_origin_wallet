<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Nium\NiumBeneficiaryJsonSchemaNormalizer;
use App\Services\Nium\NiumBeneficiaryPreflightValidator;
use App\Services\Nium\NiumBeneficiaryRequirementsResult;
use App\Services\Nium\NiumBeneficiaryRequirementsService;
use App\Services\Nium\NiumBeneficiarySchemaException;
use App\Services\Nium\NiumBeneficiarySchemaPattern;
use App\Services\Nium\NiumService;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NiumBeneficiaryProductionSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        config()->set('services.nium.beneficiary_validation_schema_endpoint', '/validationSchemas');
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    public function test_fixture_preserves_every_production_constraint_and_pattern_entry(): void
    {
        // Only identifiers, descriptive annotations and error-message prose were sanitized.
        // Fingerprints came from the untouched original; include errorMessage.pattern in the pattern check.
        $strip = function ($value) use (&$strip) {
            if (is_array($value)) {
                return array_map($strip, $value);
            }
            if (is_object($value)) {
                $result = new \stdClass;
                foreach ($value as $key => $item) {
                    if (! in_array($key, ['$id', 'title', 'description', '$comment', 'default', 'examples', 'errorMessage'], true)) {
                        $result->$key = $strip($item);
                    }
                }

                return $result;
            }

            return $value;
        };
        $patterns = [];
        $walk = function ($value, $path) use (&$walk, &$patterns) {
            if (is_array($value) || is_object($value)) {
                foreach ($value as $key => $item) {
                    if ($key === 'pattern') {
                        $patterns[$path.'.pattern'] = $item;
                    }
                    $walk($item, $path.'.'.$key);
                }
            }
        };
        $walk($this->fixture(), 'response');
        $this->assertCount(14, $patterns);
        $this->assertSame('22ca76166d5b8c211a875cd18cab53611abeab478589c05e21765d681cdcfe82', hash('sha256', json_encode($strip($this->fixture()), JSON_THROW_ON_ERROR)));
        $this->assertSame('40e81e21717d435ff5e76df19141aacd2d19c172339f804c3e1d4688770f85a2', hash('sha256', json_encode($patterns, JSON_THROW_ON_ERROR)));
    }

    public function test_complete_production_response_normalizes_without_changing_patterns_or_routing(): void
    {
        $service = $this->requirementsService();
        $schema = $service->requirements(new User, $this->corridor());
        $schema->assertTrusted(3600);
        $this->assertSame(['SWIFT'], $schema->routingCodeTypes);
        $fields = array_column($schema->fields, null, 'name');
        $source = $this->fixture()[0];
        $this->assertCount(3, $source->allOf);
        foreach ($source->required as $name) {
            $this->assertTrue($fields[$name]['required'], $name);
        }
        $patternCount = 0;
        foreach ($source->properties as $name => $property) {
            if (isset($property->pattern)) {
                // Hash assertions avoid printing Production regexes if a regression fails.
                $this->assertTrue(hash_equals(hash('sha256', $property->pattern), hash('sha256', $fields[$name]['pattern'])), $name);
                $this->assertSame(NiumBeneficiarySchemaPattern::DIALECT, $fields[$name]['pattern_dialect']);
                $patternCount++;
            }
            if (isset($property->maxLength)) {
                $this->assertSame((int) $property->maxLength, $fields[$name]['max_length']);
            }
        }
        $this->assertSame(11, $patternCount);
        $this->assertTrue(hash_equals(hash('sha256', $source->allOf[0]->else->properties->beneficiaryName->pattern), hash('sha256', $fields['beneficiaryName']['pattern'])));
        $this->assertSame(['SWIFT'], $fields['routingCodeType1']['allowed_values']);
        $this->assertSame(['Corporate'], $fields['beneficiaryAccountType']['allowed_values']);
        $this->assertSame(['HK'], $fields['clientLegalEntity']['allowed_values']);
        $this->assertFalse($fields['beneficiaryBankName']['required']);
        $this->assertTrue((new NiumBeneficiaryPreflightValidator($service))->validate($this->payload(), $this->corridor(), $schema)['valid']);
    }

    #[DataProvider('productionPatternCases')]
    public function test_every_production_validation_pattern_accepts_and_rejects_field_values(string $field, string $accountType, string $valid, string $invalid): void
    {
        $corridor = $this->corridor($accountType);
        $service = $this->requirementsService();
        $schema = $service->requirements(new User, $corridor);
        $validator = new NiumBeneficiaryPreflightValidator($service);
        $this->assertTrue($validator->validate([...$this->payload($accountType), $field => $valid], $corridor, $schema)['valid']);
        try {
            $validator->validate([...$this->payload($accountType), $field => $invalid], $corridor, $schema);
            $this->fail('Invalid Production field format must fail preflight.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public static function productionPatternCases(): array
    {
        return [
            ['clientLegalEntity', 'Corporate', 'HK', 'hk'],
            ['beneficiaryAlias', 'Corporate', 'Vendor', "Vendor\rName"],
            ['beneficiaryAddress', 'Corporate', '1 Example Road', '😀'],
            ['beneficiaryCountryCode', 'Corporate', 'HK', 'hk'],
            ['beneficiaryEmail', 'Corporate', 'fixture@example.test', 'invalid'],
            ['beneficiaryContactNumber', 'Corporate', '1234567890', 'invalid'],
            ['remitterBeneficiaryRelationship', 'Corporate', 'Vendor', '<bad>'],
            ['beneficiaryAccountNumber', 'Corporate', '123456789', '<bad>'],
            ['beneficiaryIdentificationType', 'Corporate', 'A/B', '<bad>'],
            ['beneficiaryIdentificationValue', 'Corporate', 'A/B', '<bad>'],
            ['routingCodeValue1', 'Corporate', 'HKBCHKHH', 'invalid'],
            ['beneficiaryName', 'Individual', 'Ada Lovelace', '<bad>'],
            ['beneficiaryName', 'Corporate', 'A/B Co.', '<bad>'],
        ];
    }

    #[DataProvider('regexBoundaryCases')]
    public function test_production_regex_semantics_match_ecmascript_at_boundaries(string $field, string $accountType, string $value, bool $valid): void
    {
        $corridor = $this->corridor($accountType);
        $service = $this->requirementsService();
        $schema = $service->requirements(new User, $corridor);
        try {
            (new NiumBeneficiaryPreflightValidator($service))->validate([...$this->payload($accountType), $field => $value], $corridor, $schema);
            $this->assertTrue($valid, 'Unexpectedly accepted field: '.$field);
        } catch (ValidationException $exception) {
            $this->assertFalse($valid, 'Unexpectedly rejected field: '.$field);
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public static function regexBoundaryCases(): array
    {
        // Expected results verified with new RegExp(exactProductionPattern, 'u').
        $cases = [];
        foreach (['beneficiaryCountryCode' => 'HK', 'beneficiaryEmail' => 'fixture@example.test',
            'beneficiaryContactNumber' => '1234567890', 'remitterBeneficiaryRelationship' => 'Vendor',
            'beneficiaryAccountNumber' => '123456789', 'beneficiaryIdentificationType' => 'A/B',
            'beneficiaryIdentificationValue' => 'A/B', 'routingCodeValue1' => 'HKBCHKHH'] as $field => $value) {
            $cases[] = [$field, 'Corporate', $value."\n", false];
        }
        foreach (["\r", "\u{2028}", "\u{2029}"] as $lineTerminator) {
            $cases[] = ['beneficiaryAlias', 'Corporate', 'Vendor'.$lineTerminator.'Name', false];
        }

        return [...$cases,
            ['beneficiaryName', 'Corporate', "A\u{feff}B", true],
            ['beneficiaryName', 'Corporate', "A\u{0085}B", false],
            ['beneficiaryName', 'Individual', "A\u{feff}B", false],
            ['beneficiaryName', 'Individual', "A\u{0085}B", false],
            ['beneficiaryName', 'Corporate', "A\nB", true],
            ['beneficiaryName', 'Individual', "A\nB", false],
            ['beneficiaryAddress', 'Corporate', 'a😀', true],
            ['routingCodeValue1', 'Corporate', 'HKBCHKHHXXX', true],
        ];
    }

    public function test_all_three_exact_conditional_clauses_use_pinned_context_and_preserve_requirements(): void
    {
        $service = $this->requirementsService();
        foreach (['Corporate', 'Individual'] as $accountType) {
            foreach (['HK', 'AU', 'CA'] as $legalEntity) {
                $corridor = $this->corridor($accountType);
                // Exercise the exact rules with compiler contexts; the application still supplies HK only.
                $fields = (new NiumBeneficiaryJsonSchemaNormalizer)->fields($this->fixture()[0], $this->dimensions($accountType), ['SWIFT'], $legalEntity);
                $schema = NiumBeneficiaryRequirementsResult::trusted($this->dimensions($accountType), ['SWIFT'], $fields, now()->toISOString(), 'production-fixture-test');
                $indexed = array_column($fields, null, 'name');
                $this->assertSame($legalEntity === 'AU', $indexed['beneficiaryBankName']['required']);
                foreach (['beneficiaryAddress', 'beneficiaryCity', 'beneficiaryCountryCode'] as $required) {
                    $this->assertTrue($indexed[$required]['required']);
                }
                $nameRule = $this->fixture()[0]->allOf[0]->{$accountType === 'Individual' ? 'then' : 'else'}->properties->beneficiaryName;
                $this->assertTrue(hash_equals(hash('sha256', $nameRule->pattern), hash('sha256', $indexed['beneficiaryName']['pattern'])));
                $validator = new NiumBeneficiaryPreflightValidator($service);
                $payload = [...$this->payload($accountType), 'clientLegalEntity' => $legalEntity];
                $this->assertTrue($validator->validate($payload, $corridor, $schema)['valid']);
                $required = array_unique([...$this->fixture()[0]->required,
                    ...($legalEntity === 'AU' ? $this->fixture()[0]->allOf[1]->then->required : []),
                    ...($legalEntity === 'CA' ? $this->fixture()[0]->allOf[2]->then->required : [])]);
                foreach ($required as $name) {
                    if (array_key_exists($name, $this->dimensions($accountType))) {
                        // Corridor dimensions have a separate provenance guard before field validation.
                        continue;
                    }
                    $missing = $payload;
                    unset($missing[$name]);
                    try {
                        $validator->validate($missing, $corridor, $schema);
                        $this->fail('Applicable required field was omitted: '.$name);
                    } catch (ValidationException $exception) {
                        $this->assertArrayHasKey($name, $exception->errors());
                    }
                }
                unset($payload['beneficiaryBankName']);
                if ($legalEntity !== 'AU') {
                    $this->assertTrue($validator->validate($payload, $corridor, $schema)['valid']);
                }
            }
        }
    }

    public function test_unsupported_assertions_fail_closed_even_when_annotation_messages_contain_patterns(): void
    {
        $schema = $this->fixture()[0];
        $schema->properties->beneficiaryName->format = 'unsupported-private-value';
        try {
            (new NiumBeneficiaryJsonSchemaNormalizer)->fields($schema, $this->dimensions(), ['SWIFT'], 'HK');
            $this->fail('Unsupported validation semantics must not be ignored.');
        } catch (NiumBeneficiarySchemaException $exception) {
            $this->assertSame('unsupported_keyword', $exception->reason);
            $this->assertStringNotContainsString('unsupported-private-value', $exception->getMessage());
        }
    }

    public function test_patterns_outside_the_supported_ecmascript_subset_fail_closed(): void
    {
        foreach (['[', '\\K', '(?i)private', 'a++', 'a{1,3}+', 'a{,3}', '[\\S]', '(?<=a)b'] as $unsupported) {
            $schema = $this->fixture()[0];
            $schema->properties->beneficiaryName->pattern = $unsupported;
            try {
                (new NiumBeneficiaryJsonSchemaNormalizer)->fields($schema, $this->dimensions(), ['SWIFT'], 'HK');
                $this->fail('Unsupported pattern semantics must not be interpreted as PCRE.');
            } catch (NiumBeneficiarySchemaException $exception) {
                $this->assertSame('unsupported_pattern', $exception->reason);
                $this->assertSame('schema.properties.*', $exception->location);
            }
        }
    }

    public function test_exact_production_length_enum_and_routing_rules_remain_authoritative(): void
    {
        $service = $this->requirementsService();
        $schema = $service->requirements(new User, $this->corridor());
        $invalidFields = ['beneficiaryBankAccountType' => 'CURRENT', 'routingCodeType1' => 'IFSC'];
        foreach ($this->fixture()[0]->properties as $name => $property) {
            if (isset($property->maxLength)) {
                $invalidFields[$name] = str_repeat('a', (int) $property->maxLength + 1);
            }
        }
        foreach ($invalidFields as $name => $value) {
            try {
                (new NiumBeneficiaryPreflightValidator($service))->validate([...$this->payload(), $name => $value], $this->corridor(), $schema);
                $this->fail('Invalid Production constraint must fail preflight: '.$name);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($name, $exception->errors());
            }
        }
    }

    private function fixture(): array
    {
        // An explicit environment override runs the same tests against the untouched, private original.
        $path = getenv('NIUM_BENEFICIARY_SCHEMA_FIXTURE') ?: base_path('tests/Fixtures/nium/beneficiary-hk-usd-swift-schema.sanitized.json');

        return json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
    }

    private function requirementsService(): NiumBeneficiaryRequirementsService
    {
        $nium = Mockery::mock(NiumService::class);
        $nium->shouldReceive('clientId')->andReturn('test-client');
        $nium->shouldReceive('customerId')->andReturn('test-customer');
        $nium->shouldReceive('path')->andReturn('/validationSchemas');
        $nium->shouldReceive('get')->andReturn(new Response(new PsrResponse(200, [], json_encode($this->fixture()))));
        $nium->shouldNotReceive('post');

        return new NiumBeneficiaryRequirementsService($nium);
    }

    private function dimensions(string $accountType = 'Corporate'): array
    {
        return ['destinationCountry' => 'HK', 'destinationCurrency' => 'USD', 'payoutMethod' => 'SWIFT',
            'beneficiaryAccountType' => strtoupper($accountType), 'customerType' => 'CORPORATE'];
    }

    private function corridor(string $accountType = 'Corporate'): array
    {
        return [...$this->dimensions($accountType), 'routingCodeType' => ['SWIFT']];
    }

    private function payload(string $accountType = 'Corporate'): array
    {
        return ['clientLegalEntity' => 'HK', 'destinationCountry' => 'HK', 'destinationCurrency' => 'USD',
            'payoutMethod' => 'SWIFT', 'beneficiaryAccountType' => $accountType, 'customerType' => 'CORPORATE',
            'beneficiaryName' => $accountType === 'Corporate' ? 'Example Company Limited' : 'Ada Lovelace',
            'beneficiaryAlias' => 'Vendor', 'beneficiaryAddress' => '1 Example Road', 'beneficiaryCity' => 'Hong Kong',
            'beneficiaryCountryCode' => 'HK', 'beneficiaryEmail' => 'fixture@example.test',
            'beneficiaryContactNumber' => '1234567890', 'beneficiaryBankAccountType' => 'Current',
            'beneficiaryState' => 'Hong Kong', 'beneficiaryPostcode' => '999077', 'remitterBeneficiaryRelationship' => 'Vendor',
            'beneficiaryAccountNumber' => '123456789', 'beneficiaryBankName' => 'Example Bank',
            'beneficiaryIdentificationType' => 'passport', 'beneficiaryIdentificationValue' => 'TEST1234',
            'routingCodeType1' => 'SWIFT', 'routingCodeValue1' => 'HKBCHKHH'];
    }
}
