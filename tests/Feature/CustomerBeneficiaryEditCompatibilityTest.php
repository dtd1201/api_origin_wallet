<?php

namespace Tests\Feature;

use App\Http\Resources\User\BeneficiaryResource;
use App\Models\ApiToken;
use App\Models\ApiRequestLog;
use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Support\BeneficiaryEditFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->customer->providerAccounts()->create([
            'provider_id' => $this->provider->id, 'external_customer_id' => 'fixture-customer',
            'external_account_id' => 'fixture-wallet', 'status' => 'active', 'provider_status' => 'clear',
            'customer_id_verified_at' => now(), 'wallet_id_verified_at' => now(), 'provider_ids_verified_at' => now(),
        ]);
        $token = Str::random(80);
        ApiToken::query()->create(['user_id' => $this->customer->id, 'name' => 'fixture-token',
            'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
        $this->withToken($token);
        // Synthetic beneficiary fixtures only; no remittance, live pricing or provider access.
        Http::fake([
            '*validationSchemas*' => Http::response([['type' => 'object',
                'required' => ['beneficiaryAccountNumber', 'routingCodeType1', 'routingCodeValue1'],
                'properties' => [
                    'beneficiaryAccountNumber' => ['type' => 'string', 'minLength' => 6],
                    'routingCodeType1' => ['type' => 'string', 'enum' => ['SWIFT']],
                    'routingCodeValue1' => ['type' => 'string', 'pattern' => '^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$'],
                    'beneficiaryBankAccountType' => ['type' => 'string', 'enum' => ['Current', 'Savings']],
                    'beneficiaryAlias' => ['type' => 'string'],
                    'beneficiaryIdentificationType' => ['type' => 'string'],
                    'beneficiaryIdentificationValue' => ['type' => 'string'],
                ],
            ]]),
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
        Http::assertNothingSent();
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
