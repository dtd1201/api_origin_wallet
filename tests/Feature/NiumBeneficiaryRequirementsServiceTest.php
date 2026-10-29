<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Nium\NiumBeneficiaryPreflightValidator;
use App\Services\Nium\NiumBeneficiaryRequirementsService;
use App\Services\Nium\NiumBeneficiarySchemaException;
use App\Services\Nium\NiumService;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NiumBeneficiaryRequirementsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        config()->set('services.nium.beneficiary_validation_schema_endpoint', '/validationSchemas');
    }

    public function test_json_schema_array_produces_trusted_fields_and_preserves_preflight_constraints(): void
    {
        $service = $this->service([$this->schema()]);
        $result = $service->requirements(new User, $this->corridor());
        $result->assertTrusted(3600);
        $fields = array_column($result->fields, null, 'name');
        $this->assertSame(['SWIFT'], $result->routingCodeTypes);
        $this->assertSame(['SWIFT'], $fields['routingCodeType1']['allowed_values']);
        $this->assertSame(['name' => 'beneficiaryAccountNumber', 'required' => true, 'type' => 'string', 'min_length' => 4, 'max_length' => 12], $fields['beneficiaryAccountNumber']);
        $this->assertFalse($fields['beneficiaryAlias']['required']);
        $this->assertSame('^[A-Z]{8}$', $fields['routingCodeValue1']['pattern']);
        $validator = new NiumBeneficiaryPreflightValidator($service);
        $this->assertTrue($validator->validate($this->payload(), $this->corridor(), $result)['valid']);
        foreach ([['beneficiaryAccountNumber' => null], ['beneficiaryAccountNumber' => '1'],
            ['routingCodeType1' => 'IFSC'], ['routingCodeValue1' => 'invalid'], ['beneficiaryAlias' => 'unsupported']] as $change) {
            try {
                $validator->validate(array_replace($this->payload(), $change), $this->corridor(), $result);
                $this->fail('Expected schema validation to reject the invalid field.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey(array_key_first($change), $exception->errors());
            }
        }
        Http::assertNothingSent();
    }

    public function test_all_of_merges_constraints_and_specializes_then_else_without_making_every_clause_unconditional(): void
    {
        // Synthetic contract example, not a claim about the unseen production allOf clauses.
        $schema = $this->schema();
        $schema['allOf'] = [
            ['properties' => ['beneficiaryAccountNumber' => ['minLength' => 6, 'maxLength' => 10]]],
            ['if' => ['properties' => ['payoutMethod' => ['const' => 'SWIFT']], 'required' => ['payoutMethod']],
                'then' => ['required' => ['beneficiaryAlias'], 'properties' => ['beneficiaryAlias' => ['enum' => ['vendor']]]],
                'else' => ['required' => ['localOnly'], 'properties' => ['localOnly' => ['type' => 'string']]]],
            ['if' => ['properties' => ['payoutMethod' => ['enum' => ['LOCAL']]]],
                'then' => ['required' => ['localOnly'], 'properties' => ['localOnly' => ['type' => 'string']]],
                'else' => ['properties' => ['beneficiaryAlias' => ['maxLength' => 10]]]],
            ['if' => ['properties' => ['beneficiaryAccountType' => ['const' => 'Corporate']]],
                'then' => ['properties' => ['beneficiaryAlias' => ['minLength' => 3]]]],
        ];
        $service = $this->service([$schema]);
        $result = $service->requirements(new User, $this->corridor());
        $fields = array_column($result->fields, null, 'name');
        $this->assertSame(6, $fields['beneficiaryAccountNumber']['min_length']);
        $this->assertSame(10, $fields['beneficiaryAccountNumber']['max_length']);
        $this->assertSame(['name' => 'beneficiaryAlias', 'required' => true, 'type' => 'string', 'allowed_values' => ['vendor'], 'max_length' => 10, 'min_length' => 3], $fields['beneficiaryAlias']);
        $this->assertArrayNotHasKey('localOnly', $fields);
        $validator = new NiumBeneficiaryPreflightValidator($service);
        try {
            $validator->validate($this->payload(), $this->corridor(), $result);
            $this->fail('The applicable conditional requirement must be enforced.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('beneficiaryAlias', $exception->errors());
        }
        $this->assertTrue($validator->validate([...$this->payload(), 'beneficiaryAlias' => 'vendor'], $this->corridor(), $result)['valid']);
    }

    public function test_specialized_condition_facts_are_enforced_with_exact_payload_case(): void
    {
        $service = $this->service([$this->schema()]);
        $result = $service->requirements(new User, $this->corridor());
        $this->expectException(ValidationException::class);
        (new NiumBeneficiaryPreflightValidator($service))->validate([
            ...$this->payload(), 'destinationCountry' => 'hk',
        ], $this->corridor(), $result);
    }

    #[DataProvider('productionMaxLengths')]
    public function test_production_numeric_string_maximum_lengths_are_normalized_and_enforced(string $name, string $maximum): void
    {
        $schema = $this->schema();
        $schema['properties'][$name] = ['type' => 'string', 'maxLength' => $maximum];
        $service = $this->service([$schema]);
        $result = $service->requirements(new User, $this->corridor());
        $result->assertTrusted(3600);
        $fields = array_column($result->fields, null, 'name');
        $this->assertSame((int) $maximum, $fields[$name]['max_length']);
        $validator = new NiumBeneficiaryPreflightValidator($service);
        $this->assertTrue($validator->validate([
            ...$this->payload(), $name => str_repeat('a', (int) $maximum),
        ], $this->corridor(), $result)['valid']);
        try {
            $validator->validate([
                ...$this->payload(), $name => str_repeat('a', (int) $maximum + 1),
            ], $this->corridor(), $result);
            $this->fail('Preflight must enforce the normalized maximum.');
        } catch (ValidationException $exception) {
            $this->assertSame(["Enter no more than {$maximum} characters."], $exception->errors()[$name]);
        }
        Http::assertNothingSent();
    }

    public static function productionMaxLengths(): array
    {
        return [
            ['beneficiaryAddress', '200'], ['beneficiaryCity', '50'], ['beneficiaryEmail', '100'],
            ['beneficiaryState', '50'], ['beneficiaryPostcode', '15'], ['beneficiaryBankName', '255'],
            ['beneficiaryBankCode', '15'],
        ];
    }

    #[DataProvider('validLengths')]
    public function test_length_conversion_preserves_integer_values_in_root_and_conditional_properties(int|string $length): void
    {
        foreach (['root', 'then', 'else'] as $location) {
            Cache::flush();
            $schema = $this->lengthSchema(['minLength' => $length, 'maxLength' => $length], $location);
            $result = $this->service([$schema])->requirements(new User, $this->corridor());
            $fields = array_column($result->fields, null, 'name');
            $this->assertSame((int) $length, $fields['beneficiaryCity']['min_length']);
            $this->assertSame((int) $length, $fields['beneficiaryCity']['max_length']);
        }
        Http::assertNothingSent();
    }

    public static function validLengths(): array
    {
        return [[0], ['0'], [1], ['1'], [PHP_INT_MAX], [(string) PHP_INT_MAX]];
    }

    #[DataProvider('invalidLengths')]
    public function test_invalid_lengths_fail_closed_in_root_and_conditional_properties(mixed $length): void
    {
        Log::spy();
        foreach (['minLength', 'maxLength'] as $keyword) {
            foreach (['root', 'then', 'else'] as $location) {
                Cache::flush();
                $schema = $this->lengthSchema([$keyword => $length], $location);
                try {
                    $this->service([$schema])->requirements(new User, $this->corridor());
                    $this->fail('Invalid length constraints must not produce a trusted schema.');
                } catch (NiumBeneficiarySchemaException $exception) {
                    $this->assertSame('invalid_length', $exception->reason);
                    $this->assertSame('schema.properties.*', $exception->location);
                }
            }
        }
        Http::assertNothingSent();
    }

    public static function invalidLengths(): array
    {
        return [
            'negative integer' => [-1], 'negative string' => ['-1'], 'negative zero' => ['-0'],
            'fractional number' => [1.5], 'whole float' => [50.0], 'fractional string' => ['1.5'], 'decimal string' => ['1.0'],
            'scientific number' => [1e100], 'scientific string' => ['1e2'],
            'leading whitespace' => [' 50'], 'trailing whitespace' => ['50 '], 'newline' => ["50\n"],
            'nonnumeric string' => ['fifty'], 'empty string' => [''], 'null' => [null],
            'true' => [true], 'false' => [false], 'plus sign' => ['+50'], 'leading zero' => ['050'],
            'non-ASCII digits' => ['５０'], 'overflow number' => [PHP_INT_MAX + 1],
            'overflow string' => [substr((string) PHP_INT_MAX, 0, -1).'8'],
            'very large string' => [str_repeat('9', 100)],
        ];
    }

    private function lengthSchema(array $constraints, string $location): array
    {
        $schema = $this->schema();
        $property = ['type' => 'string', ...$constraints];
        if ($location === 'root') {
            $schema['properties']['beneficiaryCity'] = $property;
        } else {
            $schema['allOf'] = [[
                'if' => ['required' => ['payoutMethod'], 'properties' => ['payoutMethod' => ['const' => $location === 'then' ? 'SWIFT' : 'LOCAL']]],
                $location => ['properties' => ['beneficiaryCity' => $property]],
            ]];
        }

        return $schema;
    }

    #[DataProvider('productionStructureCases')]
    public function test_confirmed_all_of_structures_select_only_applicable_patterns_and_requirements(
        string $accountType, string $threeFieldEntity, string $twoFieldEntity, array $expectedRequired,
    ): void {
        $schema = $this->productionStructureSchema($threeFieldEntity, $twoFieldEntity);
        // A different destination proves clientLegalEntity is not inferred from the bank's country.
        $corridor = [...$this->corridor(), 'destinationCountry' => 'IN', 'beneficiaryAccountType' => strtoupper($accountType)];
        $service = $this->service([$schema]);
        $result = $service->requirements(new User, $corridor);
        $fields = array_column($result->fields, null, 'name');
        $this->assertSame(['HK'], $fields['clientLegalEntity']['allowed_values']);
        $this->assertTrue($fields['clientLegalEntity']['required']);
        $pattern = $accountType === 'Corporate' ? '^[A-Za-z ]+ Ltd$' : '^[A-Z][a-z]+ [A-Z][a-z]+$';
        $this->assertSame($pattern, $fields['beneficiaryName']['pattern']);
        $this->assertArrayNotHasKey('errorMessage', $fields['beneficiaryName']);
        $conditionalFields = ['beneficiaryAddress', 'beneficiaryCity', 'beneficiaryPostcode', 'beneficiaryEmail', 'beneficiaryContactNumber'];
        foreach ($conditionalFields as $name) {
            $this->assertSame(in_array($name, $expectedRequired, true), $fields[$name]['required'], $name);
        }

        $payload = [...$this->payload(), 'destinationCountry' => 'IN', 'beneficiaryAccountType' => $accountType,
            'clientLegalEntity' => 'HK', 'beneficiaryName' => $accountType === 'Corporate' ? 'Acme Ltd' : 'Ada Lovelace'];
        foreach ($expectedRequired as $name) {
            $payload[$name] = 'test-value';
        }
        $validator = new NiumBeneficiaryPreflightValidator($service);
        $this->assertTrue($validator->validate($payload, $corridor, $result)['valid']);
        foreach (['beneficiaryName', 'clientLegalEntity', ...$expectedRequired] as $invalidField) {
            $invalid = array_replace($payload, [$invalidField => match ($invalidField) {
                'beneficiaryName' => 'Invalid123', 'clientLegalEntity' => 'SG', default => null,
            }]);
            try {
                $validator->validate($invalid, $corridor, $result);
                $this->fail('Expected the selected pattern, entity and conditional requirements to remain authoritative.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($invalidField, $exception->errors());
                $this->assertStringNotContainsString('provider error-message annotation', json_encode($exception->errors()));
            }
        }
        Http::assertNothingSent();
    }

    public static function productionStructureCases(): array
    {
        $three = ['beneficiaryAddress', 'beneficiaryCity', 'beneficiaryPostcode'];
        $two = ['beneficiaryEmail', 'beneficiaryContactNumber'];

        return [
            'corporate then pattern and three required fields' => ['Corporate', 'HK', 'SG', $three],
            'individual else pattern and three required fields' => ['Individual', 'HK', 'SG', $three],
            'only second entity clause matches' => ['Corporate', 'SG', 'HK', $two],
            'both entity clauses match' => ['Corporate', 'HK', 'HK', [...$three, ...$two]],
            'nonmatching const does not promote if.required or then.required' => ['Corporate', 'SG', 'SG', []],
        ];
    }

    #[DataProvider('legacyShapes')]
    public function test_existing_field_list_formats_remain_supported(array $body): void
    {
        $result = $this->service($body)->requirements(new User, $this->corridor());
        $this->assertSame([['name' => 'beneficiaryAccountNumber', 'required' => true, 'type' => 'string', 'allowed_values' => ['test']]], $result->fields);
        $result->assertTrusted(3600);
    }

    public static function legacyShapes(): array
    {
        $field = ['fieldName' => 'beneficiaryAccountNumber', 'required' => true, 'type' => 'string', 'allowedValues' => ['test']];

        return [
            [['fields' => [$field]]], [['data' => ['requirements' => [$field]]]],
            [['validationSchema' => ['validationRules' => [$field]]]], [['content' => [['fields' => [$field]]]]],
            [['type' => 'legacy-metadata', 'fields' => [$field]]],
        ];
    }

    #[DataProvider('unsupportedSchemas')]
    public function test_unsupported_or_malformed_semantics_fail_closed_with_safe_diagnostics(array $body, string $reason): void
    {
        Log::spy();
        try {
            $this->service($body)->requirements(new User, $this->corridor());
            $this->fail('Unsupported schema semantics must not produce a trusted result.');
        } catch (NiumBeneficiarySchemaException $exception) {
            $this->assertSame($reason, $exception->reason);
            $this->assertStringNotContainsString('private-provider-value', $exception->getMessage());
        }
        Log::shouldHaveReceived('warning')->once()->with('Nium beneficiary requirements unavailable.', Mockery::on(
            fn (array $context) => $context['reason'] === $reason && $context['http_status'] === 200
                && array_keys($context) === ['reason', 'schema_location', 'http_status']
                && ! str_contains(json_encode($context), 'private-provider-value')
        ));
        Http::assertNothingSent();
    }

    public static function unsupportedSchemas(): array
    {
        $base = ['type' => 'object', 'properties' => ['beneficiaryAlias' => ['type' => 'string']]];

        return [
            'empty array' => [[], 'ambiguous_schema_array'],
            'multiple schemas without selection evidence' => [[$base, $base], 'ambiguous_schema_array'],
            'ambiguous wrapped schemas' => [['content' => [$base, $base]], 'ambiguous_schema_array'],
            'invalid schema element' => [[false], 'ambiguous_schema_array'],
            'malformed properties' => [[['type' => 'object', 'properties' => null]], 'invalid_schema_object'],
            'properties array is not an object' => [[['type' => 'object', 'properties' => []]], 'invalid_schema_object'],
            'allOf array is not a schema object' => [[[...$base, 'allOf' => [[]]]], 'invalid_schema_object'],
            'malformed required' => [[['type' => 'object', 'required' => null]], 'invalid_required'],
            'required must be a list' => [[['type' => 'object', 'required' => 'private-provider-value']], 'invalid_required'],
            'required missing property type' => [[['type' => 'object', 'required' => ['private-provider-value']]], 'unrepresentable_property_type'],
            'unknown validation keyword' => [[['type' => 'object', 'private-provider-value' => true]], 'unsupported_keyword'],
            'references' => [[['type' => 'object', '$ref' => 'private-provider-value']], 'unsupported_keyword'],
            'oneOf' => [[[...$base, 'oneOf' => [$base]]], 'unsupported_keyword'],
            'property format' => [[['type' => 'object', 'properties' => ['private-provider-value' => ['type' => 'string', 'format' => 'email']]]], 'unsupported_keyword'],
            'nested object' => [[['type' => 'object', 'properties' => ['nested' => ['type' => 'object']]]], 'unsupported_property_type'],
            'union type' => [[['type' => 'object', 'properties' => ['nullable' => ['type' => ['string', 'null']]]]], 'unsupported_property_type'],
            'additional properties restriction' => [[[...$base, 'additionalProperties' => false]], 'unsupported_additional_properties'],
            'invalid allOf' => [[[...$base, 'allOf' => []]], 'invalid_all_of'],
            'payload-dependent condition' => [[[...$base, 'allOf' => [
                ['if' => ['properties' => ['routingCodeType1' => ['const' => 'SWIFT']]], 'then' => ['required' => ['beneficiaryAlias']]],
            ]]], 'payload_dependent_condition'],
            'orphan then' => [[[...$base, 'then' => ['required' => ['beneficiaryAlias']]]], 'ambiguous_conditional'],
            'conflicting enum conjunction' => [[[...$base, 'required' => ['beneficiaryAlias'], 'allOf' => [
                ['properties' => ['beneficiaryAlias' => ['enum' => ['one']]]],
                ['properties' => ['beneficiaryAlias' => ['enum' => ['two']]]],
            ]]], 'conflicting_constraints'],
            'multiple patterns cannot be flattened' => [[[...$base, 'allOf' => [
                ['properties' => ['beneficiaryAlias' => ['pattern' => '^one']]],
                ['properties' => ['beneficiaryAlias' => ['pattern' => 'two$']]],
            ]]], 'unrepresentable_conjunction'],
            'malformed regex' => [[['type' => 'object', 'properties' => ['private-provider-value' => ['type' => 'string', 'pattern' => '[']]]], 'unsupported_pattern'],
            'errorMessage cannot hide a malformed regex' => [[['type' => 'object', 'properties' => [
                'name' => ['type' => 'string', 'pattern' => '[', 'errorMessage' => 'private-provider-value'],
            ]]], 'unsupported_pattern'],
            'errorMessage cannot hide an unsupported assertion' => [[['type' => 'object', 'properties' => [
                'name' => ['type' => 'string', 'format' => 'email', 'errorMessage' => 'private-provider-value'],
            ]]], 'unsupported_keyword'],
            'negative length' => [[['type' => 'object', 'properties' => ['code' => ['type' => 'string', 'minLength' => -1]]]], 'invalid_length'],
            'empty enum' => [[['type' => 'object', 'properties' => ['code' => ['enum' => []]]]], 'unsupported_enum'],
            'unsupported routing cannot escape corridor' => [[['type' => 'object', 'required' => ['routingCodeType1'],
                'properties' => ['routingCodeType1' => ['type' => 'string', 'enum' => ['IFSC']]]]], 'conflicting_constraints'],
        ];
    }

    private function service(array $body): NiumBeneficiaryRequirementsService
    {
        $nium = Mockery::mock(NiumService::class);
        $nium->shouldReceive('clientId')->andReturn('test-client');
        $nium->shouldReceive('customerId')->andReturn('test-customer');
        $nium->shouldReceive('path')->andReturn('/validationSchemas');
        $nium->shouldReceive('get')->once()->andReturn(new Response(new PsrResponse(200, [], json_encode($body, JSON_PRESERVE_ZERO_FRACTION))));

        return new NiumBeneficiaryRequirementsService($nium);
    }

    private function corridor(): array
    {
        return ['destinationCountry' => 'HK', 'destinationCurrency' => 'USD', 'payoutMethod' => 'SWIFT',
            'beneficiaryAccountType' => 'CORPORATE', 'customerType' => 'CORPORATE', 'routingCodeType' => ['SWIFT']];
    }

    private function payload(): array
    {
        return ['destinationCountry' => 'HK', 'destinationCurrency' => 'USD', 'payoutMethod' => 'SWIFT',
            'beneficiaryAccountType' => 'Corporate', 'customerType' => 'CORPORATE',
            'clientLegalEntity' => 'HK', 'beneficiaryAccountNumber' => '12345678', 'routingCodeType1' => 'SWIFT', 'routingCodeValue1' => 'HKBCHKHH'];
    }

    private function schema(): array
    {
        return ['$schema' => 'http://json-schema.org/draft-07/schema#', '$id' => 'urn:test:beneficiary',
            'type' => 'object', 'title' => 'Synthetic beneficiary schema',
            'required' => ['beneficiaryAccountNumber', 'routingCodeType1', 'routingCodeValue1'],
            'properties' => [
                'beneficiaryAccountNumber' => ['type' => 'string', 'minLength' => 4, 'maxLength' => 12],
                'routingCodeType1' => ['type' => 'string', 'enum' => ['SWIFT', 'IFSC']],
                'routingCodeValue1' => ['type' => 'string', 'pattern' => '^[A-Z]{8}$'],
                'beneficiaryAlias' => ['type' => 'string', 'enum' => ['vendor', 'supplier']],
            ],
            'allOf' => [['properties' => ['beneficiaryAccountNumber' => ['minLength' => 4]]]],
        ];
    }

    private function productionStructureSchema(string $threeFieldEntity, string $twoFieldEntity): array
    {
        // Match the confirmed clause structures; patterns, messages and required field names are representative.
        $schema = $this->schema();
        $schema['required'][] = 'beneficiaryName';
        foreach (['beneficiaryName', 'clientLegalEntity', 'beneficiaryAddress', 'beneficiaryCity', 'beneficiaryPostcode',
            'beneficiaryEmail', 'beneficiaryContactNumber'] as $name) {
            $schema['properties'][$name] = ['type' => 'string'];
        }
        $schema['allOf'] = [
            ['if' => ['required' => ['beneficiaryAccountType'], 'properties' => ['beneficiaryAccountType' => ['const' => 'Corporate']]],
                'then' => ['type' => 'object', 'errorMessage' => ['properties' => ['beneficiaryName' => 'provider error-message annotation']],
                    'properties' => ['beneficiaryName' => ['type' => 'string', 'pattern' => '^[A-Za-z ]+ Ltd$', 'errorMessage' => 'provider error-message annotation']]],
                'else' => ['properties' => ['beneficiaryName' => ['pattern' => '^[A-Z][a-z]+ [A-Z][a-z]+$']]]],
            ['if' => ['required' => ['clientLegalEntity'], 'properties' => ['clientLegalEntity' => ['const' => $threeFieldEntity]]],
                'then' => ['required' => ['beneficiaryAddress', 'beneficiaryCity', 'beneficiaryPostcode']]],
            ['if' => ['required' => ['clientLegalEntity'], 'properties' => ['clientLegalEntity' => ['const' => $twoFieldEntity]]],
                'then' => ['required' => ['beneficiaryEmail', 'beneficiaryContactNumber']]],
        ];

        return $schema;
    }
}
