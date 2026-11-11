<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Models\IntegrationProvider;
use App\Models\NiumApiExchangeEvidence;
use App\Models\User;
use App\Services\Integrations\ProviderBeneficiaryManager;
use App\Services\Nium\NiumBeneficiaryWriteException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class NiumSgdBeneficiaryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private IntegrationProvider $provider;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('services.nium.base_url', 'https://gateway.nium.test');
        config()->set('services.nium.client_id', 'fixture-client');
        config()->set('services.nium.auth', ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'fixture-key']);
        config()->set('services.nium.webhook', ['static_header_name' => 'x-partner-key', 'static_header_value' => 'fixture-partner-key']);
        config()->set('services.nium.health_endpoint', '/api/v1/client/{clientHashId}');
        config()->set('services.nium.customer_create_endpoint', '/api/v1/client/{clientHashId}/customer');
        config()->set('services.nium.customer_list_endpoint', '/api/v1/client/{clientHashId}/customers');
        config()->set('services.nium.customer_get_endpoint', '/api/v1/client/{clientHashId}/customer/{customerHashId}');
        config()->set('services.nium.beneficiary_validation_schema_endpoint', '/validationSchemas');
        config()->set('services.nium.beneficiary_endpoint', '/beneficiaries');
        config()->set('services.nium.supported_corridors_endpoint', '/supportedCorridors');
        config()->set('services.nium.supported_corridors', [$this->sgCorridor(), [
            'destinationCountry' => 'HK', 'destinationCurrency' => 'USD', 'payoutMethod' => 'SWIFT',
            'beneficiaryAccountType' => 'CORPORATE', 'customerType' => 'CORPORATE', 'routingCodeType' => 'SWIFT',
        ]]);
        $this->provider = IntegrationProvider::query()->create(['code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        $this->customer = User::factory()->create(['kyc_status' => 'verified']);
        $this->customer->profile()->create(['user_type' => 'business', 'country_code' => 'HK']);
        $this->customer->kycProfile()->create([
            'status' => 'approved', 'applicant_type' => 'business', 'legal_name' => 'Fixture Customer',
            'registered_country_code' => 'HK', 'country_code' => 'HK', 'address_line1' => '1 Fixture Road', 'city' => 'Hong Kong',
        ]);
        $this->customer->providerAccounts()->create([
            'provider_id' => $this->provider->id, 'external_customer_id' => 'fixture-customer',
            'external_account_id' => 'fixture-wallet', 'status' => 'active', 'provider_status' => 'clear',
            'customer_id_verified_at' => now(), 'wallet_id_verified_at' => now(), 'provider_ids_verified_at' => now(),
        ]);
        $this->token = Str::random(80);
        ApiToken::query()->create([
            'user_id' => $this->customer->id, 'name' => 'fixture-token', 'token_hash' => hash('sha256', $this->token),
            'expires_at' => now()->addDay(),
        ]);
        $this->withToken($this->token);
    }

    public function test_discovery_admits_only_local_for_sg_individual_using_authoritative_corridor_gets(): void
    {
        config()->set('services.nium.supported_corridors', []);
        Http::fake(['*supportedCorridors*' => Http::response(['content' => [$this->sgCorridor()]])]);
        $this->getJson($this->url('payout-methods'))->assertOk()
            ->assertJsonPath('payout_methods', ['LOCAL'])->assertJsonPath('currency', 'SGD');
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request['customerType'] === 'CORPORATE'
            && $request['beneficiaryAccountType'] === 'INDIVIDUAL' && $request['payoutMethod'] === 'LOCAL');
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        $this->assertDatabaseCount('beneficiaries', 0);
    }

    public function test_hk_usd_corporate_discovery_preserves_swift(): void
    {
        $this->getJson($this->url('payout-methods', ['country_code' => 'HK', 'currency' => 'USD', 'beneficiary_type' => 'business']))
            ->assertOk()->assertJsonPath('payout_methods', ['SWIFT']);
        Http::assertNothingSent();
    }

    public function test_wrong_swift_method_is_an_unsupported_corridor_before_schema_lookup(): void
    {
        $this->getJson($this->url('requirements', ['payout_method' => 'SWIFT']))->assertUnprocessable()
            ->assertJsonPath('code', 'beneficiary_corridor_unsupported');
        Http::assertNothingSent();
    }

    public function test_corporate_sg_beneficiary_is_not_admitted_by_individual_evidence(): void
    {
        $this->getJson($this->url('payout-methods', ['beneficiary_type' => 'business']))->assertUnprocessable()
            ->assertJsonPath('code', 'beneficiary_corridor_unsupported');
        Http::assertNothingSent();
    }

    public function test_failed_corridor_lookup_is_not_reported_as_an_unsupported_corridor(): void
    {
        config()->set('services.nium.supported_corridors', []);
        Http::fake(['*supportedCorridors*' => Http::response(['message' => 'PRIVATE_PROVIDER_DETAIL'], 503)]);
        $this->getJson($this->url('payout-methods'))->assertStatus(502)
            ->assertJsonPath('code', 'beneficiary_corridors_unavailable')->assertDontSee('PRIVATE_PROVIDER_DETAIL');
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_supported_local_and_swift_routing_remain_distinct_through_review_requirements_and_mocked_create(): void
    {
        // Synthetic flow fixture only: an authentic SG schema is still required for verification.
        Http::fake([
            '*validationSchemas*' => Http::response([$this->syntheticSchema()]),
            'https://gateway.nium.test/beneficiaries' => Http::response(['beneficiaryHashId' => 'fixture-beneficiary', 'status' => 'ACTIVE']),
        ]);
        $this->getJson($this->url('requirements', ['payout_method' => 'LOCAL']))->assertOk()
            ->assertJsonPath('dimensions.payoutMethod', 'LOCAL')->assertJsonPath('routing_code_types', ['SWIFT']);
        $this->postJson($this->baseUrl(), $this->payload())->assertCreated()
            ->assertJsonPath('country_code', 'SG')->assertJsonPath('currency', 'SGD')
            ->assertJsonPath('is_registered', true)->assertJsonPath('payout_method', 'LOCAL');
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://gateway.nium.test/beneficiaries'
            && $request['payoutMethod'] === 'LOCAL' && $request['routingCodeType1'] === 'SWIFT'
            && $request['destinationCurrency'] === 'SGD' && $request['beneficiaryAccountType'] === 'Individual'
            && $request['beneficiaryIdentificationValue'] === 'FIXTURE123');
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET' && $request->url() !== 'https://gateway.nium.test/beneficiaries');
    }

    #[DataProvider('rejectedWriteResponses')]
    public function test_customer_create_rolls_back_http_error_audits_even_though_post_was_dispatched(int $status, array $body): void
    {
        Log::spy();
        // Synthetic responses reproduce transaction behavior, not the unknown Production response.
        $observed = [];
        NiumApiExchangeEvidence::created(function (NiumApiExchangeEvidence $evidence) use (&$observed): void {
            $log = ApiRequestLog::findOrFail($evidence->api_request_log_id);
            if ($log->operation === 'beneficiary_create') {
                $observed[] = [$log->request_method, $log->response_status, $evidence->response_received];
            }
        });
        Http::fake([
            '*validationSchemas*' => Http::response([$this->syntheticSchema()]),
            'https://gateway.nium.test/beneficiaries' => Http::response($body, $status),
        ]);

        $this->postJson($this->baseUrl(), $this->payload())->assertUnprocessable()
            ->assertJsonPath('message', 'Unable to create beneficiary with the selected provider. Review the details and try again.');

        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://gateway.nium.test/beneficiaries');
        $this->assertSame([['POST', $status, true]], $observed);
        $this->assertDatabaseCount('beneficiaries', 0);
        $this->assertDatabaseCount('api_request_logs', 0);
        $this->assertDatabaseCount('nium_api_exchange_evidence', 0);
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($status): bool {
            $this->assertDatabaseCount('api_request_logs', 0);
            $this->assertDatabaseCount('nium_api_exchange_evidence', 0);
            $this->assertArrayNotHasKey('message', $context);
            $this->assertSame(NiumBeneficiaryWriteException::class, $context['exception']);
            $diagnostic = $context['provider_diagnostic'];
            $this->assertSame('beneficiary_create', $diagnostic['stage']);
            $this->assertSame($status, $diagnostic['http_status']);
            $this->assertTrue($diagnostic['response_received']);
            $this->assertSame($status === 200 ? 'missing_beneficiary_id' : 'http_error', $diagnostic['failure_kind']);
            Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->header('x-request-id') === [$diagnostic['request_id']]);

            return $message === 'Customer beneficiary provider operation failed.';
        });
    }

    public static function rejectedWriteResponses(): array
    {
        return [
            'HTTP 400' => [400, ['message' => 'Validation failed for input provided']],
            'HTTP 422' => [422, ['message' => 'Validation failed for input provided']],
            'HTTP 500' => [500, ['message' => 'Synthetic provider error']],
            'HTTP 200 without beneficiary ID' => [200, ['message' => 'Synthetic incomplete success']],
        ];
    }

    public function test_provider_http_error_is_audited_when_caller_does_not_roll_back(): void
    {
        Http::fake([
            '*validationSchemas*' => Http::response([$this->syntheticSchema()]),
            'https://gateway.nium.test/beneficiaries' => Http::response(['message' => 'Validation failed for input provided'], 400),
        ]);
        $beneficiary = $this->customer->beneficiaries()->create($this->payload());
        try {
            app(ProviderBeneficiaryManager::class)->createBeneficiary($this->provider, $beneficiary);
            $this->fail('The rejected write must still throw.');
        } catch (RuntimeException) {
            $this->assertSame('create_failed', $beneficiary->fresh()->status);
        }
        $log = ApiRequestLog::where('operation', 'beneficiary_create')->sole();
        $this->assertSame('POST', $log->request_method);
        $this->assertSame(400, $log->response_status);
        $this->assertFalse($log->is_success);
        $this->assertTrue(NiumApiExchangeEvidence::where('api_request_log_id', $log->id)->sole()->response_received);
    }

    public function test_optional_account_verification_rejection_can_precede_beneficiary_create_and_roll_back_its_audit(): void
    {
        Log::spy();
        config()->set('services.nium.account_verification_enabled', true);
        config()->set('services.nium.account_verification_endpoint', '/accountVerification');
        $observed = [];
        NiumApiExchangeEvidence::created(function (NiumApiExchangeEvidence $evidence) use (&$observed): void {
            $log = ApiRequestLog::findOrFail($evidence->api_request_log_id);
            if ($log->request_method === 'POST') {
                $observed[] = $log->response_status;
            }
        });
        Http::fake([
            '*validationSchemas*' => Http::response([$this->syntheticSchema()]),
            '*accountVerification' => Http::response(['message' => 'Validation failed for input provided'], 400),
        ]);
        $payload = $this->payload();
        $payload['raw_data']['nium']['verify_before_create'] = true;
        $this->postJson($this->baseUrl(), $payload)->assertUnprocessable();
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://gateway.nium.test/accountVerification');
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://gateway.nium.test/beneficiaries');
        $this->assertSame([400], $observed);
        $this->assertDatabaseCount('beneficiaries', 0);
        $this->assertDatabaseCount('api_request_logs', 0);
        $this->assertDatabaseCount('nium_api_exchange_evidence', 0);
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            $this->assertSame('beneficiary_account_verification', $context['provider_diagnostic']['stage']);
            $this->assertSame(400, $context['provider_diagnostic']['http_status']);
            Http::assertSent(fn ($request) => $request->url() === 'https://gateway.nium.test/accountVerification'
                && $request->header('x-request-id') === [$context['provider_diagnostic']['request_id']]);

            return $message === 'Customer beneficiary provider operation failed.';
        });
    }

    public function test_post_rollback_diagnostic_excludes_provider_supplied_personal_data_and_credentials(): void
    {
        Log::spy();
        $private = 'Fixture Person 123456789 fixture.person@example.test fixture-key fixture-partner-key';
        $responseId = (string) Str::uuid();
        Http::fake([
            '*validationSchemas*' => Http::response([$this->syntheticSchema()]),
            'https://gateway.nium.test/beneficiaries' => Http::response([
                'message' => 'Validation failed for '.$private,
                'referenceId' => $private,
                'beneficiaryHashId' => $private,
                'errorCode' => $private,
                'errors' => [
                    ['code' => 'validation_error', 'field' => 'beneficiaryAccountNumber', 'description' => $private],
                    ['code' => $private, 'field' => $private, 'description' => $private, 'allowedValues' => [$private]],
                    $private,
                ],
            ], 400, ['x-request-id' => $responseId, 'Authorization' => $private]),
        ]);
        $response = $this->postJson($this->baseUrl(), $this->payload())->assertUnprocessable();
        $this->assertStringNotContainsString($private, $response->getContent());
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($private, $responseId): bool {
            $diagnostic = $context['provider_diagnostic'];
            $this->assertSame($responseId, $diagnostic['response_request_id']);
            $this->assertSame(3, $diagnostic['provider_error_count']);
            $this->assertSame('validation_error', $diagnostic['errors']['error_code']);
            $this->assertSame('validation_error', $diagnostic['errors']['error_items'][0]['error_code']);
            $this->assertSame(substr(hash('sha256', $private), 0, 16), $diagnostic['errors']['error_items'][0]['error_description_fingerprint']);
            foreach (['Fixture Person', '123456789', 'fixture.person@example.test', 'fixture-key', 'fixture-partner-key'] as $secret) {
                $this->assertStringNotContainsString($secret, json_encode($context, JSON_THROW_ON_ERROR));
            }

            return $message === 'Customer beneficiary provider operation failed.';
        });
        $this->assertDatabaseCount('api_request_logs', 0);
        $this->assertDatabaseCount('nium_api_exchange_evidence', 0);
    }

    public function test_non_json_provider_rejection_has_safe_diagnostics_and_still_rolls_back(): void
    {
        Log::spy();
        Http::fake([
            '*validationSchemas*' => Http::response([$this->syntheticSchema()]),
            'https://gateway.nium.test/beneficiaries' => Http::response('Invalid Fixture Person account 123456789 fixture-key', 502,
                ['Content-Type' => 'text/plain', 'x-request-id' => '123456789']),
        ]);
        $this->postJson($this->baseUrl(), $this->payload())->assertUnprocessable()->assertDontSee('123456789');
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            $this->assertSame(502, $context['provider_diagnostic']['http_status']);
            $this->assertSame('http_error', $context['provider_diagnostic']['failure_kind']);
            $this->assertArrayNotHasKey('response_request_id', $context['provider_diagnostic']);
            $this->assertStringNotContainsString('123456789', json_encode($context, JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString('fixture-key', json_encode($context, JSON_THROW_ON_ERROR));

            return $message === 'Customer beneficiary provider operation failed.';
        });
        $this->assertDatabaseCount('api_request_logs', 0);
        $this->assertDatabaseCount('nium_api_exchange_evidence', 0);
    }

    #[DataProvider('invalidFields')]
    public function test_synthetic_local_schema_retains_required_conditional_length_pattern_and_routing_constraints(array $changes, string $field): void
    {
        Http::fake(['*validationSchemas*' => Http::response([$this->syntheticSchema()])]);
        $payload = array_replace_recursive($this->payload(), $changes);
        $this->postJson($this->baseUrl(), $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        $this->assertDatabaseCount('beneficiaries', 0);
    }

    public static function invalidFields(): array
    {
        return [
            'required account' => [['account_number' => null], 'beneficiaryAccountNumber'],
            'short account' => [['account_number' => '123'], 'beneficiaryAccountNumber'],
            'bad routing pattern' => [['swift_bic' => 'INVALID'], 'routingCodeValue1'],
            'conditional required identification' => [['raw_data' => ['nium' => ['additionalFields' => ['beneficiaryIdentificationValue' => null]]]], 'beneficiaryIdentificationValue'],
        ];
    }

    public function test_unseen_unsupported_pattern_still_blocks_requirements_and_create_with_safe_errors(): void
    {
        $schema = $this->syntheticSchema();
        $schema['properties']['beneficiaryAccountNumber']['pattern'] = '\\KPRIVATE_PATTERN';
        Http::fake(['*validationSchemas*' => Http::response([$schema])]);
        $response = $this->getJson($this->url('requirements', ['payout_method' => 'LOCAL']))->assertStatus(502)
            ->assertJsonPath('code', 'beneficiary_schema_unavailable');
        $this->assertStringNotContainsString('PRIVATE_PATTERN', $response->getContent());
        $this->assertStringNotContainsString('properties', $response->getContent());
        $this->postJson($this->baseUrl(), $this->payload())->assertUnprocessable();
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        $this->assertDatabaseCount('beneficiaries', 0);
    }

    #[DataProvider('corridorChanges')]
    public function test_registered_edit_cannot_mutate_corridor_or_create_a_replacement(array $changes): void
    {
        $beneficiary = $this->customer->beneficiaries()->create([
            ...$this->payload(), 'beneficiary_type' => 'business', 'country_code' => 'HK', 'currency' => 'USD',
            'payout_method' => 'SWIFT', 'external_beneficiary_id' => 'registered-fixture', 'status' => 'active',
            'raw_data' => ['nium' => ['payoutMethod' => 'SWIFT']],
        ]);
        $original = $beneficiary->fresh()->getAttributes();
        $this->putJson($this->baseUrl().'/'.$beneficiary->id, $changes)->assertUnprocessable()
            ->assertSee('registered NIUM beneficiary');
        $this->assertSame($original, $beneficiary->fresh()->getAttributes());
        $this->assertDatabaseCount('beneficiaries', 1);
        Http::assertNothingSent();
    }

    public static function corridorChanges(): array
    {
        return [
            [['country_code' => 'SG', 'currency' => 'SGD', 'raw_data' => ['nium' => ['payoutMethod' => 'LOCAL']]]],
            [['currency' => 'SGD']], [['country_code' => 'SG']], [['beneficiary_type' => 'individual']],
            [['raw_data' => ['nium' => ['payoutMethod' => 'LOCAL']]]],
        ];
    }

    public function test_cors_applies_to_existing_api_422_and_502_responses_and_preflight_without_widening_origins(): void
    {
        config()->set('cors.allowed_origins', ['https://wallet.example.test', 'https://admin.example.test']);
        Http::fake(['*validationSchemas*' => Http::response(['message' => 'PRIVATE_DETAIL'], 500)]);
        $this->withHeader('Origin', 'https://wallet.example.test')
            ->getJson($this->url('requirements', ['payout_method' => 'SWIFT']))->assertUnprocessable()
            ->assertHeader('Access-Control-Allow-Origin', 'https://wallet.example.test');
        $this->getJson($this->url('requirements', ['payout_method' => 'LOCAL']))->assertStatus(502)
            ->assertHeader('Access-Control-Allow-Origin', 'https://wallet.example.test')->assertDontSee('PRIVATE_DETAIL');
        $this->withHeader('Origin', 'https://unapproved.example.test')
            ->getJson($this->url('requirements', ['payout_method' => 'SWIFT']))->assertUnprocessable()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->withHeaders(['Origin' => 'https://wallet.example.test', 'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'Authorization,Accept'])
            ->options($this->url('requirements', ['payout_method' => 'LOCAL']))->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://wallet.example.test');
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_edit_cannot_switch_the_provider_even_before_registration(): void
    {
        $other = IntegrationProvider::query()->create(['code' => 'fixture-other', 'name' => 'Other', 'status' => 'active']);
        $beneficiary = $this->customer->beneficiaries()->create($this->payload());
        $this->putJson($this->baseUrl().'/'.$beneficiary->id, ['provider_id' => $other->id])->assertUnprocessable()
            ->assertJsonValidationErrors('provider_id');
        $this->assertSame($this->provider->id, $beneficiary->fresh()->provider_id);
        Http::assertNothingSent();
    }

    public function test_unknown_registered_method_cannot_be_established_from_client_raw_metadata_during_edit(): void
    {
        $beneficiary = $this->customer->beneficiaries()->create([
            ...$this->payload(), 'external_beneficiary_id' => 'legacy-registered-fixture', 'status' => 'active',
        ]);
        $original = $beneficiary->fresh()->getAttributes();
        $this->putJson($this->baseUrl().'/'.$beneficiary->id, ['full_name' => 'Changed Name'])
            ->assertUnprocessable()->assertJsonValidationErrors('raw_data.nium.payoutMethod')
            ->assertSee('payout method could not be verified');
        $this->assertSame($original, $beneficiary->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_discovery_rejects_another_customer_before_provider_access(): void
    {
        $other = User::factory()->create();
        $this->getJson(str_replace('/users/'.$this->customer->id.'/', '/users/'.$other->id.'/', $this->url('payout-methods')))->assertForbidden();
        Http::assertNothingSent();
    }

    private function sgCorridor(): array
    {
        return ['destinationCountry' => 'SG', 'destinationCurrency' => 'SGD', 'payoutMethod' => 'LOCAL',
            'beneficiaryAccountType' => 'INDIVIDUAL', 'customerType' => 'CORPORATE', 'routingCodeType' => 'SWIFT'];
    }

    private function baseUrl(): string
    {
        return '/api/user/users/'.$this->customer->id.'/beneficiaries';
    }

    private function url(string $action, array $overrides = []): string
    {
        return $this->baseUrl().'/'.$action.'?'.http_build_query([
            'provider_id' => $this->provider->id, 'beneficiary_type' => 'individual', 'country_code' => 'SG', 'currency' => 'SGD', ...$overrides,
        ]);
    }

    private function payload(): array
    {
        return ['provider_id' => $this->provider->id, 'beneficiary_type' => 'individual', 'full_name' => 'Fixture Person',
            'country_code' => 'SG', 'currency' => 'SGD', 'bank_name' => 'Fixture Bank', 'account_number' => '123456789',
            'swift_bic' => 'DBSSSGSG', 'raw_data' => ['nium' => ['payoutMethod' => 'LOCAL',
                'additionalFields' => ['beneficiaryIdentificationValue' => 'FIXTURE123']]]];
    }

    private function syntheticSchema(): array
    {
        return ['type' => 'object', 'required' => ['beneficiaryAccountNumber', 'routingCodeType1', 'routingCodeValue1'],
            'properties' => [
                'beneficiaryAccountNumber' => ['type' => 'string', 'minLength' => 6, 'maxLength' => 20, 'pattern' => '^[0-9]+$'],
                'routingCodeType1' => ['type' => 'string', 'enum' => ['SWIFT']],
                'routingCodeValue1' => ['type' => 'string', 'pattern' => '^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$'],
            ], 'allOf' => [[
                'if' => ['properties' => ['beneficiaryAccountType' => ['const' => 'Individual']]],
                'then' => ['required' => ['beneficiaryIdentificationValue'], 'properties' => ['beneficiaryIdentificationValue' => ['type' => 'string', 'minLength' => 4]]],
            ]]];
    }
}
