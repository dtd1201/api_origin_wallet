<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Nium\NiumBeneficiaryPayoutMethodResolver;
use App\Services\Nium\NiumBeneficiaryRequirementsService;
use App\Services\Nium\NiumBeneficiaryService;
use App\Services\Nium\NiumTransferPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class NiumBeneficiaryLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        config()->set('services.nium', array_replace_recursive((array) config('services.nium'), [
            'base_url' => 'https://gateway.nium.test',
            'client_id' => 'lifecycle-client-id',
            'auth' => ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'lifecycle-key'],
            'webhook' => ['static_header_name' => 'x-partner-key', 'static_header_value' => 'lifecycle-partner-key'],
            'health_endpoint' => '/api/v1/client/{clientHashId}',
            'customer_create_endpoint' => '/api/v1/client/{clientHashId}/customer',
            'customer_list_endpoint' => '/api/v1/client/{clientHashId}/customers',
            'customer_get_endpoint' => '/api/v1/client/{clientHashId}/customer/{customerHashId}',
            'beneficiary_endpoint' => '/api/v2/client/{clientHashId}/customer/{customerHashId}/beneficiaries',
            'supported_corridors' => [[
                'destinationCountry' => 'HK',
                'destinationCurrency' => 'USD',
                'payoutMethod' => 'SWIFT',
                'beneficiaryAccountType' => 'CORPORATE',
                'customerType' => 'CORPORATE',
                'routingCodeType' => ['SWIFT'],
            ]],
        ]));
    }

    public function test_successful_controller_create_persists_authoritative_corridor_and_safe_operational_metadata(): void
    {
        [$user, $provider, $token] = $this->customer();
        Http::fake(['*validationSchemas*' => Http::response(['fields' => []]), '*' => Http::response([
            'beneficiaryHashId' => 'verified-beneficiary-id',
            'status' => 'ACTIVE',
            'requestId' => 'safe-provider-request-id',
            'authenticationCode' => 'must-not-persist',
        ], 200)]);

        $response = $this->withToken($token)
            ->postJson("/api/user/users/{$user->id}/beneficiaries", $this->beneficiaryPayload($provider))
            ->assertCreated()
            ->assertJsonPath('payout_method', 'SWIFT');

        $beneficiary = Beneficiary::query()->sole();
        $this->assertSame('SWIFT', $beneficiary->payout_method);
        $this->assertSame('SWIFT', data_get($beneficiary->raw_data, 'nium.provider_verified.payout_method'));
        $this->assertSame('safe-provider-request-id', $beneficiary->raw_data['provider_request_id']);
        $this->assertSame('verified-beneficiary-id', $beneficiary->raw_data['beneficiary_id']);
        $this->assertStringNotContainsString('must-not-persist', json_encode($beneficiary->raw_data, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('must-not-persist', $response->getContent());
        $this->assertSame('verified-beneficiary-id', ApiRequestLog::query()->where('operation', 'beneficiary_create')->sole()->response_body['beneficiary_id']);

        app(NiumTransferPolicy::class)->normalizeCreate([
            'transfer_type' => 'payout',
            'source_currency' => 'USD',
            'target_currency' => 'USD',
            'purpose_code' => 'IR01811',
            'reference_text' => 'INV-123',
            'source_amount' => '25.00',
        ], $user, $provider, $beneficiary->fresh(), [['code' => 'IR01811', 'label' => 'Business']]);
        $this->addToAssertionCount(1);

        Http::assertSent(function ($request): bool {
            return $request->data() === [
                'clientLegalEntity' => 'HK',
                'beneficiaryAccountType' => 'Corporate',
                'destinationCountry' => 'HK',
                'destinationCurrency' => 'USD',
                'payoutMethod' => 'SWIFT',
                'beneficiaryName' => 'Verified Corporate Beneficiary',
                'beneficiaryPostcode' => '999077',
                'beneficiaryAddress' => '1 Beneficiary Road',
                'beneficiaryCity' => 'Hong Kong',
                'beneficiaryCountryCode' => 'HK',
                'beneficiaryAccountNumber' => '123456789',
                'beneficiaryBankName' => 'Verified Bank',
                'routingCodeType1' => 'SWIFT',
                'routingCodeValue1' => 'HKBCHKHH',
            ];
        });
    }

    public function test_client_raw_data_cannot_forge_authoritative_payout_method(): void
    {
        [$user, $provider] = $this->customer();
        $beneficiary = $user->beneficiaries()->create([
            ...$this->beneficiaryAttributes($provider),
            'external_beneficiary_id' => 'unsafely-claimed-beneficiary',
            'status' => 'active',
            'raw_data' => ['nium' => ['payoutMethod' => 'SWIFT']],
        ]);

        $this->expectException(RuntimeException::class);
        app(NiumTransferPolicy::class)->normalizeCreate([
            'transfer_type' => 'payout',
            'source_currency' => 'USD',
            'target_currency' => 'USD',
            'purpose_code' => 'IR01811',
            'reference_text' => 'INV-123',
        ], $user, $provider, $beneficiary, [['code' => 'IR01811', 'label' => 'Business']]);
    }

    public function test_failed_provider_create_does_not_verify_payout_method(): void
    {
        [$user, $provider] = $this->customer();
        $beneficiary = $user->beneficiaries()->create($this->beneficiaryAttributes($provider));
        Http::fake(['*validationSchemas*' => Http::response(['fields' => []]), '*' => Http::response([
            'status' => 'FAILED',
            'code' => 'invalid_input',
            'message' => 'Rejected',
        ], 422)]);

        try {
            app(NiumBeneficiaryService::class)->createBeneficiary($provider, $beneficiary->fresh('user'));
            $this->fail('Failed provider creation must throw.');
        } catch (RuntimeException) {
            // The failed response is expected and must not produce authoritative corridor state.
        }

        $beneficiary->refresh();
        $this->assertNull($beneficiary->payout_method);
        $this->assertSame('create_failed', $beneficiary->status);
        $this->assertSame('invalid_input', $beneficiary->raw_data['provider_error_code']);
        $this->assertArrayNotHasKey('provider_verified', (array) data_get($beneficiary->raw_data, 'nium', []));
    }

    public function test_legacy_fallback_and_explicit_repair_require_matching_successful_request_evidence(): void
    {
        [$user, $provider] = $this->customer();
        $beneficiary = $user->beneficiaries()->create([
            ...$this->beneficiaryAttributes($provider),
            'external_beneficiary_id' => 'legacy-beneficiary-id',
            'status' => 'active',
            'raw_data' => ['nium' => ['payoutMethod' => 'LOCAL']],
        ]);
        $log = ApiRequestLog::query()->create([
            'provider_id' => $provider->id,
            'user_id' => $user->id,
            'operation' => 'beneficiary_create',
            'external_reference' => (string) $beneficiary->id,
            'request_method' => 'POST',
            'request_url' => '/beneficiaries',
            'request_body' => ['payout_method' => 'SWIFT'],
            'response_body' => ['beneficiary_id' => 'legacy-beneficiary-id'],
            'response_status' => 200,
            'transport_outcome' => 'response_received',
            'is_success' => true,
        ]);

        app(NiumTransferPolicy::class)->normalizeCreate([
            'transfer_type' => 'payout',
            'source_currency' => 'USD',
            'target_currency' => 'USD',
            'purpose_code' => 'IR01811',
            'reference_text' => 'INV-123',
        ], $user, $provider, $beneficiary, [['code' => 'IR01811', 'label' => 'Business']]);
        $this->addToAssertionCount(1);

        $arguments = [
            'beneficiary' => $beneficiary->id,
            '--user' => $user->id,
            '--provider' => $provider->id,
            '--external-beneficiary-id' => 'legacy-beneficiary-id',
            '--payout-method' => 'SWIFT',
        ];
        $this->artisan('nium:repair-beneficiary-payout-method', $arguments)
            ->expectsOutput("Beneficiary {$beneficiary->id} verified (dry run): SWIFT from ApiRequestLog {$log->id}.")
            ->assertSuccessful();
        $this->assertNull($beneficiary->fresh()->payout_method);

        $this->artisan('nium:repair-beneficiary-payout-method', [
            ...$arguments,
            '--apply' => true,
            '--approve' => 'REPAIR_NIUM_BENEFICIARY_PAYOUT_METHOD',
            '--operator' => 'OPS-123 lifecycle repair test',
        ])->assertSuccessful();
        $this->assertSame('SWIFT', $beneficiary->fresh()->payout_method);

        $audit = AuditLog::query()->where('action', 'beneficiary.nium_payout_method_repaired')->sole();
        $this->assertSame((string) $beneficiary->id, $audit->entity_id);
        $this->assertSame([
            'beneficiary_id' => $beneficiary->id,
            'provider_id' => $provider->id,
            'user_id' => $user->id,
            'api_request_log_id' => $log->id,
            'external_beneficiary_id' => 'legacy-beneficiary-id',
            'payout_method' => 'SWIFT',
            'operator_context' => 'OPS-123 lifecycle repair test',
        ], $audit->new_data);
        $this->assertStringNotContainsString('lifecycle-key', json_encode($audit->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_missing_or_mismatched_response_beneficiary_evidence_cannot_resolve_or_apply(): void
    {
        [$user, $provider] = $this->customer();

        foreach ([
            'missing response evidence' => [],
            'mismatched response evidence' => ['beneficiary_id' => 'different-beneficiary-id'],
        ] as $case => $responseBody) {
            $beneficiary = $this->legacyBeneficiary($user, $provider, 'legacy-'.str_replace(' ', '-', $case));
            $this->beneficiaryEvidenceLog($beneficiary, $responseBody);

            $this->assertRepairFailsWithoutMutation($beneficiary, $case);
        }
    }

    public function test_correct_request_and_response_with_wrong_scope_cannot_resolve_or_apply(): void
    {
        [$user, $provider] = $this->customer();
        $otherProvider = IntegrationProvider::query()->create(['code' => 'other', 'name' => 'Other', 'status' => 'active']);
        $otherUser = User::factory()->create();

        foreach ([
            'provider_id' => $otherProvider->id,
            'user_id' => $otherUser->id,
            'external_reference' => '999999',
        ] as $field => $wrongValue) {
            $beneficiary = $this->legacyBeneficiary($user, $provider, "scoped-{$field}-{$user->id}");
            $this->beneficiaryEvidenceLog($beneficiary, [
                'beneficiary_id' => $beneficiary->external_beneficiary_id,
            ], [$field => $wrongValue]);

            $this->assertRepairFailsWithoutMutation($beneficiary, "wrong {$field}");
        }
    }

    #[DataProvider('supportedBankRouting')]
    public function test_controller_resolves_and_persists_supported_bank_routing(array|string $types, ?string $swift): void
    {
        [$user, $provider, $token] = $this->customer();
        $this->localCorridor($types);
        Http::preventStrayRequests();
        Http::fake(['*validationSchemas*' => Http::response(['fields' => []]), '*/beneficiaries' => Http::response(['beneficiaryHashId' => 'resolved-beneficiary', 'status' => 'ACTIVE'])]);

        $this->withToken($token)->postJson("/api/user/users/{$user->id}/beneficiaries", [
            ...$this->localBeneficiaryPayload($provider), 'swift_bic' => $swift,
        ])->assertCreated();

        $beneficiary = Beneficiary::query()->sole();
        $this->assertSame('IFSC', data_get($beneficiary->raw_data, 'nium.bankCodeType'));
        $this->assertSame('LOCAL', data_get($beneficiary->raw_data, 'nium.payoutMethod'));
        $slot = $swift === null ? 1 : 2;
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request["routingCodeType{$slot}"] === 'IFSC'
            && $request["routingCodeValue{$slot}"] === 'HDFC0001234'
            && ($swift === null || ($request['routingCodeType1'] === 'SWIFT' && $request['routingCodeValue1'] === $swift)));
        Http::assertSentCount(2);
    }

    public static function supportedBankRouting(): array
    {
        return [
            'scalar corridor type' => ['IFSC', null],
            'array corridor type' => [['IFSC'], null],
            'normalized corridor type' => [[' ifsc '], null],
            'bank type alongside explicit swift' => [['IFSC', 'SWIFT'], 'HDFCINBB'],
        ];
    }

    #[DataProvider('providedBankRouting')]
    public function test_controller_preserves_explicit_supported_routing_metadata(array $metadata, string $persistedKey): void
    {
        [$user, $provider, $token] = $this->customer();
        $this->localCorridor(['IFSC', 'SWIFT']);
        Http::preventStrayRequests();
        Http::fake(['*validationSchemas*' => Http::response(['fields' => []]), '*/beneficiaries' => Http::response(['beneficiaryHashId' => 'explicit-beneficiary', 'status' => 'ACTIVE'])]);

        $this->withToken($token)->postJson("/api/user/users/{$user->id}/beneficiaries", [
            ...$this->localBeneficiaryPayload($provider),
            'raw_data' => ['nium' => ['payoutMethod' => 'LOCAL', ...$metadata]],
        ])->assertCreated();

        $this->assertSame('IFSC', data_get(Beneficiary::query()->sole()->raw_data, $persistedKey));
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request['routingCodeType1'] === 'IFSC' && $request['routingCodeValue1'] === 'HDFC0001234');
        Http::assertSentCount(2);
    }

    public static function providedBankRouting(): array
    {
        return [
            'bank code type' => [['bankCodeType' => 'IFSC'], 'nium.bankCodeType'],
            'routing info' => [['routingInfo' => [['type' => 'IFSC', 'value' => 'HDFC0001234']]], 'nium.routingInfo.0.type'],
        ];
    }

    public function test_controller_update_preserves_routing_type_and_rejects_unsupported_replacement(): void
    {
        [$user, $provider, $token] = $this->customer();
        $this->localCorridor(['IFSC', 'SWIFT']);
        config()->set('services.nium.beneficiary_update_enabled', true);
        $beneficiary = $user->beneficiaries()->create([
            ...$this->localBeneficiaryPayload($provider),
            'external_beneficiary_id' => 'existing-beneficiary',
        ]);
        Http::preventStrayRequests();
        Http::fake(['*validationSchemas*' => Http::response(['fields' => []]), '*/beneficiaries/existing-beneficiary' => Http::response(['status' => 'ACTIVE'])]);

        $url = "/api/user/users/{$user->id}/beneficiaries/{$beneficiary->id}";
        $this->withToken($token)->putJson($url, [
            'raw_data' => ['nium' => ['payoutMethod' => 'LOCAL', 'bankCodeType' => 'IFSC']],
        ])->assertOk();
        $this->assertSame('IFSC', data_get($beneficiary->fresh()->raw_data, 'nium.bankCodeType'));
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['routingCodeType1'] === 'IFSC');

        $this->withToken($token)->putJson($url, [
            'raw_data' => ['nium' => ['payoutMethod' => 'LOCAL', 'bankCodeType' => 'NOT_SUPPORTED']],
        ])->assertUnprocessable()->assertJsonValidationErrors('bank_code');
        $this->assertSame('IFSC', data_get($beneficiary->fresh()->raw_data, 'nium.bankCodeType'));
        Http::assertSentCount(2);
    }

    public function test_missing_branch_routing_type_returns_a_branch_field_error_without_post(): void
    {
        [$user, $provider, $token] = $this->customer();
        $this->localCorridor(['IFSC']);
        Http::preventStrayRequests();
        Http::fake(['*validationSchemas*' => Http::response(['fields' => []])]);

        $this->withToken($token)->postJson("/api/user/users/{$user->id}/beneficiaries", [
            ...$this->localBeneficiaryPayload($provider), 'branch_code' => '123',
            'raw_data' => ['nium' => ['payoutMethod' => 'LOCAL', 'bankCodeType' => 'IFSC']],
        ])->assertUnprocessable()->assertJsonValidationErrors('branch_code');
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    #[DataProvider('invalidBankRouting')]
    public function test_controller_returns_bank_field_error_without_any_nium_post(array|string|null $types, array $metadata): void
    {
        [$user, $provider, $token] = $this->customer();
        $this->localCorridor($types);
        Http::preventStrayRequests();
        Http::fake(['*validationSchemas*' => Http::response(['fields' => []])]);

        $this->withToken($token)->postJson("/api/user/users/{$user->id}/beneficiaries", [
            ...$this->localBeneficiaryPayload($provider),
            'raw_data' => ['nium' => ['payoutMethod' => 'LOCAL', 'verify_before_create' => true, ...$metadata]],
        ])->assertUnprocessable()->assertJsonValidationErrors('bank_code');

        $this->assertDatabaseCount('beneficiaries', 0);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public static function invalidBankRouting(): array
    {
        return [
            'swift-only corridor rejects generic bank codes' => [['SWIFT'], []],
            'missing corridor type' => [null, []],
            'empty corridor types' => [[], []],
            'ambiguous corridor types' => [['IFSC', 'SWIFT'], []],
            'unsupported explicit bank type' => [['IFSC'], ['bankCodeType' => 'SWIFT']],
            'invented explicit type' => [['IFSC'], ['bankCodeType' => 'NOT_SUPPORTED']],
            'explicit type cannot bypass absent corridor types' => [[], ['bankCodeType' => 'IFSC']],
            'unsupported explicit routing info' => [['SWIFT'], ['routingInfo' => [['type' => 'IFSC', 'value' => 'HDFC0001234']]]],
            'explicit routing cannot silently drop bank code' => [['SWIFT'], ['routingInfo' => [['type' => 'SWIFT', 'value' => 'HDFCINBB']]]],
        ];
    }

    public function test_trusted_schema_can_disambiguate_supported_routing_before_payload_construction(): void
    {
        [$user, $provider] = $this->customer();
        $corridor = $this->localCorridor(['IFSC', 'SWIFT']);
        Http::preventStrayRequests();
        Http::fake([
            '*/beneficiaries' => Http::response(['beneficiaryHashId' => 'schema-beneficiary', 'status' => 'ACTIVE']),
            '*' => Http::response(['fields' => [
                ['fieldName' => 'routingCodeType1', 'required' => true, 'allowedValues' => ['IFSC']],
            ]]),
        ]);
        $schema = app(NiumBeneficiaryRequirementsService::class)->requirements($user, $corridor);
        $beneficiary = $user->beneficiaries()->create($this->localBeneficiaryPayload($provider));

        $created = app(NiumBeneficiaryService::class)->createValidatedBeneficiary($provider, $beneficiary, $corridor, $schema);

        $this->assertSame('schema-beneficiary', $created->external_beneficiary_id);
        $this->assertSame('IFSC', data_get($created->raw_data, 'nium.bankCodeType'));
        $this->assertTrue(data_get($created->raw_data, 'nium.beneficiary_preflight.valid'));
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['routingCodeType1'] === 'IFSC');
        Http::assertSentCount(2);
    }

    public function test_schema_cannot_authorize_a_routing_type_absent_from_the_corridor(): void
    {
        [$user, $provider] = $this->customer();
        $corridor = $this->localCorridor(['IFSC']);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['fields' => [
            ['fieldName' => 'routingCodeType1', 'required' => true, 'allowedValues' => ['SWIFT']],
        ]])]);
        $schema = app(NiumBeneficiaryRequirementsService::class)->requirements($user, $corridor);
        $beneficiary = $user->beneficiaries()->create($this->localBeneficiaryPayload($provider));

        try {
            app(NiumBeneficiaryService::class)->createValidatedBeneficiary($provider, $beneficiary, $corridor, $schema);
            $this->fail('Unsupported routing must fail before POST.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('bank_code', $exception->errors());
        }
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        Http::assertSentCount(1);
    }

    public function test_requirements_endpoint_reuses_v3_corridor_and_validation_schema_without_a_nium_post(): void
    {
        [$user, $provider, $token] = $this->customer();
        $corridor = config('services.nium.supported_corridors.0');
        config()->set('services.nium.supported_corridors', []);
        Http::fake([
            '*supportedCorridors*' => Http::response(['content' => [$corridor]]),
            '*validationSchemas*' => Http::response(['fields' => [
                ['fieldName' => 'routingCodeValue1', 'required' => true, 'pattern' => '^[A-Z]{8}$', 'minLength' => 8, 'maxLength' => 11],
            ]]),
        ]);
        $query = http_build_query(['provider_id' => $provider->id, 'beneficiary_type' => 'business', 'country_code' => 'HK', 'currency' => 'USD', 'payout_method' => 'SWIFT']);
        $this->withToken($token)->getJson("/api/user/users/{$user->id}/beneficiaries/requirements?{$query}")
            ->assertOk()->assertJsonPath('routing_code_types', ['SWIFT'])
            ->assertJsonPath('dimensions.customerType', 'CORPORATE')
            ->assertJsonPath('fields.0.required', true)->assertJsonPath('fields.0.min_length', 8);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v3/client/')
            && $request['beneficiaryAccountType'] === 'CORPORATE' && $request['customerType'] === 'CORPORATE');
        $this->assertDatabaseCount('beneficiaries', 0);
    }

    #[DataProvider('schemaLookupFailures')]
    public function test_schema_failure_blocks_requirements_and_creation_without_a_nium_post(array $body, int $status): void
    {
        [$user, $provider, $token] = $this->customer();
        Http::fake(['*validationSchemas*' => Http::response($body, $status)]);
        $query = http_build_query(['provider_id' => $provider->id, 'beneficiary_type' => 'business', 'country_code' => 'HK', 'currency' => 'USD', 'payout_method' => 'SWIFT']);
        $response = $this->withToken($token)->getJson("/api/user/users/{$user->id}/beneficiaries/requirements?{$query}")->assertStatus(502);
        $this->assertStringNotContainsString('secret provider details', $response->getContent());
        $this->withToken($token)->postJson("/api/user/users/{$user->id}/beneficiaries", $this->beneficiaryPayload($provider))
            ->assertUnprocessable()->assertJsonValidationErrors('raw_data.nium');
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        $this->assertDatabaseCount('beneficiaries', 0);
    }

    public static function schemaLookupFailures(): array
    {
        return [
            'provider failure' => [['message' => 'secret provider details'], 503],
            'unknown schema shape' => [['unexpected' => []], 200],
        ];
    }

    public function test_requirements_endpoint_rejects_cross_user_access_before_looking_up_nium(): void
    {
        [$user, $provider, $token] = $this->customer();
        $other = User::factory()->create();
        Http::fake();
        $query = http_build_query(['provider_id' => $provider->id, 'beneficiary_type' => 'business', 'country_code' => 'HK', 'currency' => 'USD', 'payout_method' => 'SWIFT']);
        $this->withToken($token)->getJson("/api/user/users/{$other->id}/beneficiaries/requirements?{$query}")->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_schema_connection_failure_returns_an_actionable_field_error_without_post(): void
    {
        [$user, $provider, $token] = $this->customer();
        Http::fake(fn () => throw new ConnectionException('offline'));
        $this->withToken($token)->postJson("/api/user/users/{$user->id}/beneficiaries", $this->beneficiaryPayload($provider))
            ->assertUnprocessable()->assertJsonValidationErrors('raw_data.nium');
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        $this->assertDatabaseCount('beneficiaries', 0);
    }

    public function test_schema_field_rules_are_authoritative_before_beneficiary_creation(): void
    {
        [$user, $provider, $token] = $this->customer();
        Http::fake(['*validationSchemas*' => Http::response(['fields' => [
            ['fieldName' => 'beneficiaryPostcode', 'required' => true, 'minLength' => 6],
            ['fieldName' => 'routingCodeValue1', 'required' => true, 'pattern' => '^[A-Z]{8}$'],
        ]])]);
        $this->withToken($token)->postJson("/api/user/users/{$user->id}/beneficiaries", [
            ...$this->beneficiaryPayload($provider), 'postal_code' => '1', 'swift_bic' => 'invalid',
        ])->assertUnprocessable()->assertJsonValidationErrors(['beneficiaryPostcode', 'routingCodeValue1']);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        $this->assertDatabaseCount('beneficiaries', 0);
    }

    public function test_swift_routing_replacement_clears_obsolete_codes_and_preserves_schema_approved_account_details(): void
    {
        [$user, $provider, $token] = $this->customer();
        config()->set('services.nium.beneficiary_update_enabled', true);
        $beneficiary = $user->beneficiaries()->create([
            ...$this->beneficiaryAttributes($provider), 'bank_code' => 'legacy-bank', 'branch_code' => 'legacy-branch',
            'external_beneficiary_id' => 'existing-beneficiary',
        ]);
        Http::fake([
            '*validationSchemas*' => Http::response(['fields' => [
                ['fieldName' => 'beneficiaryBankAccountType', 'required' => true, 'allowedValues' => ['CURRENT']],
                ['fieldName' => 'beneficiaryAlias', 'required' => false],
            ]]),
            '*/beneficiaries/existing-beneficiary' => Http::response(['status' => 'ACTIVE']),
        ]);
        $this->withToken($token)->putJson("/api/user/users/{$user->id}/beneficiaries/{$beneficiary->id}", [
            'swift_bic' => 'HKBCHKHH',
            'raw_data' => ['nium' => ['payoutMethod' => 'SWIFT', 'beneficiaryBankAccountType' => 'CURRENT',
                'routingInfo' => [['type' => 'SWIFT', 'value' => 'HKBCHKHH']], 'additionalFields' => ['beneficiaryAlias' => 'Vendor alias']]],
        ])->assertOk()->assertJsonPath('bank_code', null)->assertJsonPath('branch_code', null)
            ->assertJsonPath('raw_data.nium.beneficiaryBankAccountType', 'CURRENT');
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['routingCodeType1'] === 'SWIFT'
            && $request['beneficiaryBankAccountType'] === 'CURRENT' && $request['beneficiaryAlias'] === 'Vendor alias'
            && ! isset($request['beneficiaryBankCode']) && ! isset($request['routingCodeType2']));
        $this->assertSame('123456789', $beneficiary->fresh()->account_number);
    }

    public function test_routing_explicitly_marked_optional_by_the_schema_can_be_omitted(): void
    {
        [$user, $provider, $token] = $this->customer();
        $this->localCorridor(['IFSC', 'SWIFT']);
        Http::fake([
            '*validationSchemas*' => Http::response(['fields' => [
                ['fieldName' => 'routingCodeType1', 'required' => false],
                ['fieldName' => 'routingCodeValue1', 'required' => false],
            ]]),
            '*/beneficiaries' => Http::response(['beneficiaryHashId' => 'optional-routing-beneficiary', 'status' => 'ACTIVE']),
        ]);
        $this->withToken($token)->postJson("/api/user/users/{$user->id}/beneficiaries", [
            ...$this->localBeneficiaryPayload($provider), 'bank_code' => null,
        ])->assertCreated();
        Http::assertSent(fn ($request) => $request->method() === 'POST' && ! isset($request['routingCodeType1']) && ! isset($request['routingCodeValue1']));
    }

    public function test_additional_schema_values_cannot_inject_routing_or_undeclared_fields(): void
    {
        [$user, $provider, $token] = $this->customer();
        Http::fake(['*validationSchemas*' => Http::response(['fields' => [
            ['fieldName' => 'routingCodeType1', 'required' => true],
        ]])]);
        foreach (['routingCodeType1', 'undeclared'] as $field) {
            $this->withToken($token)->postJson("/api/user/users/{$user->id}/beneficiaries", [
                ...$this->beneficiaryPayload($provider),
                'raw_data' => ['nium' => ['payoutMethod' => 'SWIFT', 'additionalFields' => [$field => 'IFSC']]],
            ])->assertUnprocessable()->assertJsonValidationErrors("raw_data.nium.additionalFields.{$field}");
        }
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    private function localCorridor(array|string|null $types): array
    {
        $corridor = [
            'destinationCountry' => 'IN', 'destinationCurrency' => 'INR', 'payoutMethod' => 'LOCAL',
            'beneficiaryAccountType' => 'CORPORATE', 'customerType' => 'CORPORATE',
        ];
        if ($types !== null) {
            $corridor['routingCodeType'] = $types;
        }
        config()->set('services.nium.supported_corridors', [$corridor]);

        return $corridor;
    }

    private function localBeneficiaryPayload(IntegrationProvider $provider): array
    {
        return [
            ...$this->beneficiaryPayload($provider),
            'country_code' => 'IN', 'currency' => 'INR', 'bank_code' => 'HDFC0001234', 'swift_bic' => null,
            'raw_data' => ['nium' => ['payoutMethod' => 'LOCAL']],
        ];
    }

    private function assertRepairFailsWithoutMutation(Beneficiary $beneficiary, string $case): void
    {
        $this->assertNull(
            app(NiumBeneficiaryPayoutMethodResolver::class)->successfulRequestEvidence($beneficiary),
            $case,
        );

        $this->artisan('nium:repair-beneficiary-payout-method', [
            'beneficiary' => $beneficiary->id,
            '--user' => $beneficiary->user_id,
            '--provider' => $beneficiary->provider_id,
            '--external-beneficiary-id' => $beneficiary->external_beneficiary_id,
            '--payout-method' => 'SWIFT',
            '--apply' => true,
            '--approve' => 'REPAIR_NIUM_BENEFICIARY_PAYOUT_METHOD',
            '--operator' => "OPS-FAIL {$case}",
        ])->assertFailed();

        $this->assertNull($beneficiary->fresh()->payout_method, $case);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'beneficiary.nium_payout_method_repaired',
            'entity_id' => (string) $beneficiary->id,
        ]);
    }

    private function legacyBeneficiary(User $user, IntegrationProvider $provider, string $externalId): Beneficiary
    {
        return $user->beneficiaries()->create([
            ...$this->beneficiaryAttributes($provider),
            'external_beneficiary_id' => $externalId,
            'status' => 'active',
        ]);
    }

    private function beneficiaryEvidenceLog(Beneficiary $beneficiary, array $responseBody, array $overrides = []): ApiRequestLog
    {
        return ApiRequestLog::query()->create([
            'provider_id' => $beneficiary->provider_id,
            'user_id' => $beneficiary->user_id,
            'operation' => 'beneficiary_create',
            'external_reference' => (string) $beneficiary->id,
            'request_method' => 'POST',
            'request_url' => '/beneficiaries',
            'request_body' => ['payout_method' => 'SWIFT'],
            'response_status' => 200,
            'response_body' => $responseBody,
            'transport_outcome' => 'response_received',
            'is_success' => true,
            ...$overrides,
        ]);
    }

    private function customer(): array
    {
        $provider = IntegrationProvider::query()->create(['code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        $user = User::factory()->create(['kyc_status' => 'verified']);
        $user->profile()->create(['user_type' => 'business', 'country_code' => 'HK']);
        $user->kycProfile()->create([
            'status' => 'approved',
            'applicant_type' => 'business',
            'legal_name' => 'Lifecycle Customer',
            'registered_country_code' => 'HK',
            'address_line1' => '1 Customer Road',
            'city' => 'Hong Kong',
            'country_code' => 'HK',
            'metadata' => ['nium_region' => 'HK'],
        ]);
        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => 'lifecycle-customer-id',
            'external_account_id' => 'lifecycle-wallet-id',
            'status' => 'active',
            'provider_status' => 'clear',
            'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(),
            'provider_ids_verified_at' => now(),
        ]);
        $plainToken = Str::random(80);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'beneficiary-lifecycle-token',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        return [$user, $provider, $plainToken];
    }

    private function beneficiaryPayload(IntegrationProvider $provider): array
    {
        return [
            'provider_id' => $provider->id,
            ...$this->beneficiaryAttributes($provider),
        ];
    }

    private function beneficiaryAttributes(IntegrationProvider $provider): array
    {
        return [
            'provider_id' => $provider->id,
            'beneficiary_type' => 'business',
            'full_name' => 'Verified Corporate Beneficiary',
            'country_code' => 'HK',
            'currency' => 'USD',
            'bank_name' => 'Verified Bank',
            'account_number' => '123456789',
            'swift_bic' => 'HKBCHKHH',
            'address_line1' => '1 Beneficiary Road',
            'city' => 'Hong Kong',
            'postal_code' => '999077',
            'raw_data' => ['nium' => ['payoutMethod' => 'SWIFT']],
            'status' => 'pending',
        ];
    }
}
