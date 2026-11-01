<?php

namespace Tests\Feature;

use App\Http\Resources\User\BeneficiaryResource;
use App\Models\ApiToken;
use App\Models\ApiRequestLog;
use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\NiumApiExchangeEvidence;
use App\Models\User;
use App\Support\BeneficiaryEditFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerBeneficiaryEditCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;
    private IntegrationProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('services.nium', array_replace((array) config('services.nium'), [
            'base_url' => 'https://gateway.nium.test', 'client_id' => 'fixture-client',
            'auth' => ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'fixture-key'],
            'webhook' => ['static_header_name' => 'x-partner-key', 'static_header_value' => 'fixture-partner-key'],
            'health_endpoint' => '/api/v1/client/{clientHashId}',
            'customer_create_endpoint' => '/api/v1/client/{clientHashId}/customer',
            'customer_list_endpoint' => '/api/v1/client/{clientHashId}/customers',
            'customer_get_endpoint' => '/api/v1/client/{clientHashId}/customer/{customerHashId}',
            'beneficiary_validation_schema_endpoint' => '/validationSchemas',
            'beneficiary_endpoint' => '/beneficiaries', 'beneficiary_update_endpoint' => '/beneficiaries/{beneficiaryHashId}',
            'beneficiary_update_enabled' => true,
            'beneficiary_delete_endpoint' => '/beneficiaries/{beneficiaryHashId}', 'beneficiary_delete_enabled' => true,
            'supported_corridors' => [
                ['destinationCountry' => 'HK', 'destinationCurrency' => 'USD', 'payoutMethod' => 'SWIFT',
                    'beneficiaryAccountType' => 'CORPORATE', 'customerType' => 'CORPORATE', 'routingCodeType' => 'SWIFT'],
                ['destinationCountry' => 'SG', 'destinationCurrency' => 'SGD', 'payoutMethod' => 'LOCAL',
                    'beneficiaryAccountType' => 'INDIVIDUAL', 'customerType' => 'CORPORATE', 'routingCodeType' => 'SWIFT'],
            ],
        ]));
        $this->provider = IntegrationProvider::query()->create(['code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        $this->customer = User::factory()->create(['kyc_status' => 'verified']);
        $this->customer->profile()->create(['user_type' => 'business', 'country_code' => 'HK']);
        $this->customer->kycProfile()->create(['status' => 'approved', 'applicant_type' => 'business',
            'registered_country_code' => 'HK', 'country_code' => 'HK', 'legal_name' => 'Fixture Customer',
            'address_line1' => '1 Fixture Road', 'city' => 'Hong Kong']);
        $this->customer->providerAccounts()->create([
            'provider_id' => $this->provider->id, 'external_customer_id' => 'fixture-customer',
            'external_account_id' => 'fixture-wallet', 'status' => 'active', 'provider_status' => 'clear',
            'reconciliation_status' => 'reconciled',
            'customer_id_verified_at' => now(), 'wallet_id_verified_at' => now(), 'provider_ids_verified_at' => now(),
        ]);
        $token = Str::random(80);
        ApiToken::query()->create(['user_id' => $this->customer->id, 'name' => 'fixture-token',
            'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
        $this->withToken($token);
        // Synthetic beneficiary fixtures only; no remittance, live pricing or provider access.
        Http::fake([
            '*validationSchemas*' => Http::response([$this->schema()]),
            'https://gateway.nium.test/beneficiaries*' => Http::response([
                'beneficiaryHashId' => 'PRIVATE_BENEFICIARY_ID', 'status' => 'ACTIVE', 'requestId' => 'PRIVATE_REQUEST_ID',
            ]),
        ]);
    }

    public static function corridors(): array
    {
        return ['HK business SWIFT' => ['HK', 'USD', 'business', 'SWIFT', 'HSBCHKHHHKH'],
            'SG individual LOCAL' => ['SG', 'SGD', 'individual', 'LOCAL', 'DBSSSGSG']];
    }

    #[DataProvider('corridors')]
    public function test_create_open_edit_save_and_reopen_keep_customer_fields_without_provider_metadata(
        string $country, string $currency, string $type, string $method, string $bic,
    ): void {
        $created = $this->postJson($this->url(), $this->payload($country, $currency, $type, $method, $bic))
            ->assertCreated()->assertJsonMissingPath('raw_data')->assertJsonMissingPath('external_beneficiary_id')
            ->assertJsonPath('is_registered', true)->assertJsonPath('payout_method', $method)
            ->assertJsonPath('edit_fields.bank_account_type', 'Current')
            ->assertJsonPath('edit_fields.relationship', 'Vendor')
            ->assertJsonPath('edit_fields.contact_country_code', '65')
            ->assertJsonPath('edit_fields.routing', [['type' => 'SWIFT', 'value' => $bic]])
            ->assertJsonPath('edit_fields.additional_fields.beneficiaryIdentificationValue', 'FIXTURE123');
        $id = $created->json('id');
        $original = Beneficiary::query()->findOrFail($id);
        $raw = $original->raw_data;
        $raw['diagnostic'] = 'PRIVATE_DIAGNOSTIC';
        $original->update(['raw_data' => $raw]);
        $this->getJson($this->url($id))->assertOk()->assertJsonMissingPath('raw_data');
        $this->getJson($this->url())->assertOk()->assertJsonMissingPath('0.raw_data')
            ->assertJsonPath('0.edit_fields.bank_account_type', 'Current')
            ->assertJsonPath('0.edit_fields.routing', [['type' => 'SWIFT', 'value' => $bic]]);

        $this->putJson($this->url($id), ['email' => 'edited@example.test', 'raw_data' => ['nium' => [
            'beneficiaryBankAccountType' => 'Savings', 'beneficiaryContactCountryCode' => '852',
            'remitterBeneficiaryRelationship' => 'Contractor',
            'additionalFields' => ['beneficiaryIdentificationValue' => 'UPDATED123'],
        ]]])->assertOk()->assertJsonPath('edit_fields.bank_account_type', 'Savings');

        $reopened = $this->getJson($this->url($id))->assertOk()->assertJsonMissingPath('raw_data')
            ->assertJsonPath('country_code', $country)->assertJsonPath('currency', $currency)
            ->assertJsonPath('beneficiary_type', $type)->assertJsonPath('payout_method', $method)
            ->assertJsonPath('account_number', '123456789')->assertJsonPath('swift_bic', $bic)
            ->assertJsonPath('phone', '12345678')->assertJsonPath('email', 'edited@example.test')
            ->assertJsonPath('edit_fields.contact_country_code', '852')
            ->assertJsonPath('edit_fields.relationship', 'Contractor')
            ->assertJsonPath('edit_fields.routing', [['type' => 'SWIFT', 'value' => $bic]])
            ->assertJsonPath('edit_fields.additional_fields.beneficiaryIdentificationType', 'passport')
            ->assertJsonPath('edit_fields.additional_fields.beneficiaryIdentificationValue', 'UPDATED123');
        foreach (['PRIVATE_DIAGNOSTIC', 'PRIVATE_REQUEST_ID', 'PRIVATE_BENEFICIARY_ID', 'beneficiary_preflight', 'provider_verified'] as $secret) {
            $reopened->assertDontSee($secret);
        }
        $saved = $original->fresh();
        $this->assertSame('PRIVATE_DIAGNOSTIC', $saved->raw_data['diagnostic']);
        $this->assertSame($raw['nium']['provider_verified'], $saved->raw_data['nium']['provider_verified']);
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request['payoutMethod'] === $method && $request['routingCodeValue1'] === $bic
            && $request['beneficiaryBankAccountType'] === 'Savings' && $request['beneficiaryIdentificationValue'] === 'UPDATED123');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'remittance'));
    }

    public function test_absent_empty_and_null_metadata_updates_preserve_stored_fields_and_hidden_values(): void
    {
        $created = $this->postJson($this->url(), $this->payload('SG', 'SGD', 'individual', 'LOCAL', 'DBSSSGSG'))->assertCreated();
        $id = $created->json('id');
        foreach ([['email' => 'edited@example.test'], ['raw_data' => []], ['raw_data' => null], ['raw_data' => ['nium' => ['additionalFields' => []]]]] as $patch) {
            $this->putJson($this->url($id), $patch)->assertOk()
                ->assertJsonPath('edit_fields.bank_account_type', 'Current')
                ->assertJsonPath('edit_fields.relationship', 'Vendor')
                ->assertJsonPath('edit_fields.contact_country_code', '65')
                ->assertJsonPath('edit_fields.additional_fields.beneficiaryIdentificationValue', 'FIXTURE123')
                ->assertJsonPath('edit_fields.routing', [['type' => 'SWIFT', 'value' => 'DBSSSGSG']]);
        }
    }

    public function test_direct_legacy_customer_fields_can_be_edited_without_duplicate_provider_fields(): void
    {
        $created = $this->postJson($this->url(), $this->payload('HK', 'USD', 'business', 'SWIFT', 'HSBCHKHHHKH'))->assertCreated();
        $id = $created->json('id');
        $beneficiary = Beneficiary::query()->findOrFail($id);
        $raw = $beneficiary->raw_data;
        unset($raw['nium']['beneficiaryBankAccountType']);
        $raw['nium']['bankAccountType'] = 'Current';
        $raw['nium']['beneficiaryAlias'] = 'Original alias';
        $beneficiary->update(['raw_data' => $raw]);
        $this->getJson($this->url($id))->assertOk()->assertJsonPath('edit_fields.additional_fields.beneficiaryAlias', 'Original alias');
        $this->putJson($this->url($id), ['raw_data' => ['nium' => ['additionalFields' => ['beneficiaryAlias' => 'Edited alias']]]])
            ->assertOk()->assertJsonPath('edit_fields.bank_account_type', 'Current')
            ->assertJsonPath('edit_fields.additional_fields.beneficiaryAlias', 'Edited alias');
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['beneficiaryAlias'] === 'Edited alias'
            && $request['beneficiaryBankAccountType'] === 'Current');
    }

    public function test_partial_update_preserves_newer_stored_values_instead_of_replaying_an_old_form(): void
    {
        $created = $this->postJson($this->url(), $this->payload('SG', 'SGD', 'individual', 'LOCAL', 'DBSSSGSG'))->assertCreated();
        $beneficiary = Beneficiary::query()->findOrFail($created->json('id'));
        $raw = $beneficiary->raw_data;
        $raw['nium']['beneficiaryBankAccountType'] = 'Savings';
        $raw['nium']['beneficiaryContactCountryCode'] = '852';
        $beneficiary->update(['raw_data' => $raw]);
        $this->putJson($this->url($beneficiary->id), ['email' => 'edited@example.test'])->assertOk()
            ->assertJsonPath('edit_fields.bank_account_type', 'Savings')
            ->assertJsonPath('edit_fields.contact_country_code', '852')
            ->assertJsonPath('email', 'edited@example.test');
    }

    #[DataProvider('corridors')]
    public function test_legacy_registered_method_uses_the_existing_resolver_without_exposing_its_evidence(
        string $country, string $currency, string $type, string $method, string $bic,
    ): void {
        $beneficiary = $this->customer->beneficiaries()->create([
            ...$this->payload($country, $currency, $type, $method, $bic),
            'payout_method' => null, 'external_beneficiary_id' => 'PRIVATE_BENEFICIARY_ID', 'status' => 'active',
        ]);
        ApiRequestLog::query()->create([
            'provider_id' => $this->provider->id, 'user_id' => $this->customer->id,
            'operation' => 'beneficiary_create', 'external_reference' => (string) $beneficiary->id,
            'request_method' => 'POST', 'request_url' => '/beneficiaries',
            'request_body' => ['payout_method' => $method, 'private_evidence' => 'PRIVATE_EVIDENCE'],
            'response_body' => ['beneficiary_id' => 'PRIVATE_BENEFICIARY_ID'],
            'response_status' => 200, 'transport_outcome' => 'response_received', 'is_success' => true,
        ]);
        $raw = $beneficiary->raw_data;
        unset($raw['nium']['payoutMethod']);
        $beneficiary->update(['raw_data' => $raw]);
        $this->getJson($this->url($beneficiary->id))->assertOk()->assertJsonPath('payout_method', $method)
            ->assertJsonMissingPath('raw_data')->assertDontSee('PRIVATE_EVIDENCE')->assertDontSee('PRIVATE_BENEFICIARY_ID');
        $this->putJson($this->url($beneficiary->id), ['email' => 'edited@example.test'])->assertOk()
            ->assertJsonPath('payout_method', $method);
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['payoutMethod'] === $method);
    }

    public function test_explicitly_clearing_an_optional_field_keeps_other_customer_fields(): void
    {
        $created = $this->postJson($this->url(), $this->payload('SG', 'SGD', 'individual', 'LOCAL', 'DBSSSGSG'))->assertCreated();
        $this->putJson($this->url($created->json('id')), ['raw_data' => ['nium' => [
            'additionalFields' => ['beneficiaryIdentificationValue' => null],
        ]]])->assertOk()->assertJsonMissingPath('edit_fields.additional_fields.beneficiaryIdentificationValue')
            ->assertJsonPath('edit_fields.additional_fields.beneficiaryIdentificationType', 'passport')
            ->assertJsonPath('edit_fields.bank_account_type', 'Current');
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && ! isset($request['beneficiaryIdentificationValue'])
            && $request['beneficiaryIdentificationType'] === 'passport');
    }

    public function test_unavailable_provider_update_rolls_back_the_partial_edit(): void
    {
        $created = $this->postJson($this->url(), $this->payload('HK', 'USD', 'business', 'SWIFT', 'HSBCHKHHHKH'))->assertCreated();
        $beneficiary = Beneficiary::query()->findOrFail($created->json('id'));
        $original = $beneficiary->getAttributes();
        config()->set('services.nium.beneficiary_update_enabled', false);
        $this->putJson($this->url($beneficiary->id), ['raw_data' => ['nium' => ['beneficiaryBankAccountType' => 'Savings']]])
            ->assertUnprocessable()->assertJsonMissingPath('raw_data');
        $this->assertSame($original, $beneficiary->fresh()->getAttributes());
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    }

    public function test_allowlist_does_not_serialize_arbitrary_nested_metadata_or_routing_evidence(): void
    {
        $beneficiary = new Beneficiary(['country_code' => 'HK', 'currency' => 'USD', 'raw_data' => [
            'diagnostics' => 'PRIVATE', 'nium' => [
                'request' => ['beneficiaryAlias' => 'PRIVATE'], 'provider_verified' => ['secret' => 'PRIVATE'],
                'bankAccountType' => ['diagnostic' => 'PRIVATE'],
                'routingInfo' => [['type' => 'SWIFT', 'value' => 'HSBCHKHHHKH', 'evidence' => 'PRIVATE']],
                'additionalFields' => ['beneficiaryAlias' => 'Safe alias', 'diagnostics' => 'PRIVATE',
                    'authenticationCode' => 'PRIVATE', 'encryptedBeneficiaryCardToken' => 'PRIVATE'],
            ],
        ]]);
        $result = (new BeneficiaryResource($beneficiary))->resolve();
        $this->assertArrayNotHasKey('raw_data', $result);
        $this->assertSame(['routing' => [['type' => 'SWIFT', 'value' => 'HSBCHKHHHKH']],
            'additional_fields' => ['beneficiaryAlias' => 'Safe alias']], (array) $result['edit_fields']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($result, JSON_THROW_ON_ERROR));
        Http::assertNothingSent();
    }

    public function test_shorter_routing_list_replaces_old_slots_but_preserves_bank_details_and_internal_metadata(): void
    {
        $beneficiary = new Beneficiary(['payout_method' => 'LOCAL', 'bank_code' => '123', 'branch_code' => '456', 'raw_data' => ['nium' => [
            'routingInfo' => [['type' => 'BANK', 'value' => '123'], ['type' => 'BRANCH', 'value' => '456']],
            'provider_verified' => ['payout_method' => 'LOCAL'],
        ]]]);
        $merged = BeneficiaryEditFields::mergeUpdate($beneficiary, ['nium' => ['routingInfo' => [['type' => 'SWIFT', 'value' => 'DBSSSGSG']]]]);
        $this->assertSame([['type' => 'SWIFT', 'value' => 'DBSSSGSG']], $merged['nium']['routingInfo']);
        $this->assertSame(['payout_method' => 'LOCAL'], $merged['nium']['provider_verified']);
        $this->assertSame('123', $beneficiary->bank_code);
        $this->assertSame('456', $beneficiary->branch_code);
        Http::assertNothingSent();
    }

    public function test_safe_edit_fields_are_still_scoped_to_the_authenticated_customer(): void
    {
        $other = User::factory()->create();
        $beneficiary = $other->beneficiaries()->create(['provider_id' => $this->provider->id,
            'beneficiary_type' => 'business', 'full_name' => 'Other customer', 'country_code' => 'HK', 'currency' => 'USD']);
        $this->getJson($this->url($beneficiary->id))->assertNotFound();
        $this->putJson($this->url($beneficiary->id), ['phone' => '123'])->assertNotFound();
        $foreignUrl = '/api/user/users/'.$other->id.'/beneficiaries/'.$beneficiary->id;
        $this->getJson($foreignUrl)->assertForbidden();
        $this->putJson($foreignUrl, ['phone' => '123'])->assertForbidden();
        Http::assertNothingSent();
    }

    #[DataProvider('corridors')]
    public function test_customer_cannot_set_verified_status_on_create_or_update(
        string $country, string $currency, string $type, string $method, string $bic,
    ): void {
        $payload = $this->payload($country, $currency, $type, $method, $bic);
        $this->postJson($this->url(), [...$payload, 'status' => 'verified'])->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->assertDatabaseCount('beneficiaries', 0);
        Http::assertNothingSent();

        $created = $this->postJson($this->url(), $payload)->assertCreated()->assertJsonPath('status', 'active');
        $beneficiary = Beneficiary::query()->findOrFail($created->json('id'));
        $before = $beneficiary->getAttributes();
        $calls = count(Http::recorded());
        $this->putJson($this->url($beneficiary->id), ['status' => 'verified', 'email' => 'changed@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('active', $beneficiary->fresh()->status);
        $this->assertSame($before, $beneficiary->fresh()->getAttributes());
        $this->assertSame($calls, count(Http::recorded()));
        $this->assertDatabaseCount('beneficiaries', 1);
    }

    #[DataProvider('corridors')]
    public function test_registered_identity_and_corridor_edits_are_rejected_before_provider_access(
        string $country, string $currency, string $type, string $method, string $bic,
    ): void {
        $created = $this->postJson($this->url(), $this->payload($country, $currency, $type, $method, $bic))->assertCreated();
        $beneficiary = Beneficiary::query()->findOrFail($created->json('id'));
        $before = $beneficiary->getAttributes();
        $calls = count(Http::recorded());
        $other = IntegrationProvider::query()->create(['code' => 'other-fixture', 'name' => 'Other', 'status' => 'active']);
        foreach ([
            ['id' => 999], ['user_id' => 999], ['external_beneficiary_id' => 'FORGED_ID'],
            ['provider_id' => $other->id], ['status' => 'active'], ['payout_method' => $method === 'LOCAL' ? 'SWIFT' : 'LOCAL'],
            ['country_code' => $country === 'SG' ? 'HK' : 'SG'], ['currency' => $currency === 'SGD' ? 'USD' : 'SGD'],
            ['beneficiary_type' => $type === 'individual' ? 'business' : 'individual'],
            ['raw_data' => ['nium' => ['payoutMethod' => $method === 'LOCAL' ? 'SWIFT' : 'LOCAL']]],
            ['raw_data' => ['nium' => ['payout_method' => $method === 'LOCAL' ? 'SWIFT' : 'LOCAL']]],
            ['raw_data' => ['nium' => ['provider_verified' => ['payout_method' => $method]]]],
            ['raw_data' => ['nium' => ['beneficiary_preflight' => ['valid' => true]]]],
        ] as $changes) {
            $this->putJson($this->url($beneficiary->id), $changes)->assertUnprocessable()->assertJsonMissingPath('raw_data');
            $this->assertSame($before, $beneficiary->fresh()->getAttributes());
        }
        $this->assertSame($calls, count(Http::recorded()));
        $this->assertDatabaseCount('beneficiaries', 1);
    }

    public function test_partial_edit_preserves_authenticated_registration_capture_and_cannot_replace_operational_metadata(): void
    {
        $created = $this->postJson($this->url(), $this->payload('SG', 'SGD', 'individual', 'LOCAL', 'DBSSSGSG'))
            ->assertCreated()->assertJsonPath('payout_admission.preparation_allowed', true);
        $beneficiary = Beneficiary::query()->findOrFail($created->json('id'));
        $log = ApiRequestLog::query()->where('operation', 'beneficiary_create')->sole();
        $evidence = NiumApiExchangeEvidence::query()->where('api_request_log_id', $log->id)->sole();
        $capture = $evidence->getAttributes();
        $audit = $log->getAttributes();
        $this->assertTrue($evidence->response_received);
        $this->assertSame(hash('sha256', $evidence->raw_response_body), $evidence->response_body_sha256);
        $this->assertSame('PRIVATE_BENEFICIARY_ID', json_decode($evidence->raw_response_body, true)['beneficiaryHashId']);
        $this->assertSame('POST', $evidence->request_method);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && Str::isUuid($request->header('x-request-id')[0] ?? ''));
        $raw = $beneficiary->raw_data;
        $raw['operational'] = ['capture_reference' => 'PRIVATE_CAPTURE'];
        $raw['nium']['provider_verified']['capture_reference'] = 'PRIVATE_CAPTURE';
        $raw['nium']['account_verification']['evidence'] = 'PRIVATE_VERIFICATION';
        $beneficiary->update(['raw_data' => $raw]);

        $this->putJson($this->url($beneficiary->id), ['email' => 'edited@example.test', 'raw_data' => [
            'operational' => null, 'nium' => ['account_verification' => ['evidence' => 'FORGED']],
        ]])->assertOk()->assertJsonMissingPath('raw_data')->assertJsonPath('payout_admission.preparation_allowed', true)
            ->assertDontSee('PRIVATE_CAPTURE')->assertDontSee('PRIVATE_VERIFICATION');
        $saved = $beneficiary->fresh();
        $this->assertSame('PRIVATE_BENEFICIARY_ID', $saved->external_beneficiary_id);
        $this->assertSame('123456789', $saved->account_number);
        $this->assertSame($raw['operational'], $saved->raw_data['operational']);
        $this->assertSame($raw['nium']['provider_verified'], $saved->raw_data['nium']['provider_verified']);
        $this->assertSame($raw['nium']['account_verification'], $saved->raw_data['nium']['account_verification']);
        $this->assertSame($audit, $log->fresh()->getAttributes());
        $this->assertSame($capture, $evidence->fresh()->getAttributes());

        // The edit projection and saved metadata cannot replace authenticated capture verification.
        $evidence->update(['response_body_sha256' => str_repeat('0', 64)]);
        $calls = count(Http::recorded());
        $this->getJson($this->url($beneficiary->id))->assertOk()->assertJsonPath('payout_admission.preparation_allowed', false)
            ->assertJsonMissingPath('raw_data');
        $this->assertSame($calls, count(Http::recorded()));
    }

    public static function rejectedProviderWrites(): array
    {
        return [
            'create_http_error' => ['create', 422, ['message' => 'PRIVATE_PROVIDER_DIAGNOSTIC']],
            'create_scalar_response' => ['create', 200, 'PRIVATE_PROVIDER_DIAGNOSTIC'],
            'create_scalar_json' => ['create', 200, '"PRIVATE_PROVIDER_DIAGNOSTIC"'],
            'create_invalid_id' => ['create', 200, ['beneficiaryHashId' => ['PRIVATE_PROVIDER_DIAGNOSTIC']]],
            'create_conflicting_ids' => ['create', 200, ['beneficiaryHashId' => 'ONE', 'id' => 'ANOTHER']],
            'update_http_error' => ['update', 422, ['message' => 'PRIVATE_PROVIDER_DIAGNOSTIC']],
            'update_identity_change' => ['update', 200, ['beneficiaryHashId' => 'CHANGED_ID']],
            'update_conflicting_ids' => ['update', 200, ['beneficiaryHashId' => 'PRIVATE_BENEFICIARY_ID', 'id' => 'CHANGED_ID']],
            'update_invalid_status' => ['update', 200, ['status' => ['PRIVATE_PROVIDER_DIAGNOSTIC']]],
            'create_connection_error' => ['create', 0, []],
            'update_connection_error' => ['update', 0, []],
            'delete_connection_error' => ['delete', 0, []],
            'delete_scalar_error' => ['delete', 422, 'PRIVATE_PROVIDER_DIAGNOSTIC'],
        ];
    }

    #[DataProvider('rejectedProviderWrites')]
    public function test_rejected_provider_writes_are_sanitized_and_preserve_saved_identity(string $operation, int $status, mixed $body): void
    {
        $beneficiary = null;
        if ($operation !== 'create') {
            $created = $this->postJson($this->url(), $this->payload('HK', 'USD', 'business', 'SWIFT', 'HSBCHKHHHKH'))->assertCreated();
            $beneficiary = Beneficiary::query()->findOrFail($created->json('id'));
        }
        $before = $beneficiary?->getAttributes();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($status, $body) {
            if ($request->method() === 'GET') {
                return Http::response([$this->schema()]);
            }
            if ($status === 0) {
                throw new ConnectionException('PRIVATE_PROVIDER_DIAGNOSTIC account=123456789 key=fixture-key');
            }

            return Http::response($body, $status);
        });
        $response = match ($operation) {
            'create' => $this->postJson($this->url(), $this->payload('HK', 'USD', 'business', 'SWIFT', 'HSBCHKHHHKH')),
            'update' => $this->putJson($this->url($beneficiary->id), ['email' => 'edited@example.test']),
            'delete' => $this->deleteJson($this->url($beneficiary->id)),
        };
        $response->assertUnprocessable()->assertExactJson([
            'message' => "Unable to {$operation} beneficiary with the selected provider. Review the details and try again.",
        ]);
        $this->assertDatabaseCount('beneficiaries', $beneficiary === null ? 0 : 1);
        if ($beneficiary !== null) {
            $this->assertSame($before, $beneficiary->fresh()->getAttributes());
        }
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'remittance'));
    }

    public function test_malformed_account_verification_is_sanitized_and_cannot_register_a_beneficiary(): void
    {
        config()->set('services.nium.account_verification_enabled', true);
        config()->set('services.nium.account_verification_endpoint', '/accountVerification');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*validationSchemas*' => Http::response([$this->schema()]),
            '*accountVerification' => Http::response('"PRIVATE_PROVIDER_DIAGNOSTIC"', 200),
        ]);
        $payload = $this->payload('SG', 'SGD', 'individual', 'LOCAL', 'DBSSSGSG');
        $payload['raw_data']['nium']['verify_before_create'] = true;
        $this->postJson($this->url(), $payload)->assertUnprocessable()->assertExactJson([
            'message' => 'Unable to create beneficiary with the selected provider. Review the details and try again.',
        ]);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/accountVerification'));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/beneficiaries'));
        $this->assertDatabaseCount('beneficiaries', 0);
        $this->assertDatabaseCount('nium_api_exchange_evidence', 0);
    }

    private function schema(): array
    {
        return ['type' => 'object', 'required' => ['beneficiaryAccountNumber', 'routingCodeType1', 'routingCodeValue1'],
            'properties' => [
                'beneficiaryAccountNumber' => ['type' => 'string', 'minLength' => 6],
                'routingCodeType1' => ['type' => 'string', 'enum' => ['SWIFT']],
                'routingCodeValue1' => ['type' => 'string', 'pattern' => '^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$'],
                'beneficiaryBankAccountType' => ['type' => 'string', 'enum' => ['Current', 'Savings']],
                'beneficiaryAlias' => ['type' => 'string'], 'beneficiaryIdentificationType' => ['type' => 'string'],
                'beneficiaryIdentificationValue' => ['type' => 'string'],
            ]];
    }

    private function url(?int $id = null): string
    {
        return '/api/user/users/'.$this->customer->id.'/beneficiaries'.($id === null ? '' : '/'.$id);
    }

    private function payload(string $country, string $currency, string $type, string $method, string $bic): array
    {
        return ['provider_id' => $this->provider->id, 'beneficiary_type' => $type, 'full_name' => 'Fixture Recipient',
            'country_code' => $country, 'currency' => $currency, 'phone' => '12345678',
            'bank_name' => 'Fixture Bank', 'account_number' => '123456789', 'swift_bic' => $bic,
            'raw_data' => ['origin' => ['account_route' => 'bank'], 'nium' => [
                'payoutMethod' => $method, 'beneficiaryBankAccountType' => 'Current',
                'beneficiaryContactCountryCode' => '65', 'remitterBeneficiaryRelationship' => 'Vendor',
                'routingInfo' => [['type' => 'SWIFT', 'value' => $bic]],
                'additionalFields' => ['beneficiaryIdentificationType' => 'passport', 'beneficiaryIdentificationValue' => 'FIXTURE123'],
            ]]];
    }
}
