<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\IntegrationProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
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
