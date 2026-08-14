<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumBeneficiaryListService;
use App\Services\Nium\NiumBeneficiaryExecutionAuthorization;
use App\Services\Nium\NiumBeneficiaryService;
use App\Services\Nium\NiumBeneficiaryValidationSchemaService;
use App\Services\Nium\NiumHkBeneficiaryOneShotRunner;
use App\Services\Nium\NiumPurposeCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class NiumPayoutContractHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.nium.base_url', 'https://gateway.sandbox.nium.test');
        config()->set('services.nium.client_id', 'client-test');
        config()->set('services.nium.purpose_codes_endpoint', '/api/v1/remittance/purposeCodes');
        config()->set('services.nium.auth', ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'test-key']);
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'partner-key');
    }

    public function test_validation_schema_v2_returns_raw_string_from_exact_endpoint_and_query(): void
    {
        [, $user] = $this->account();
        Http::fake(['*' => Http::response('opaque-provider-schema', 200)]);

        $schema = app(NiumBeneficiaryValidationSchemaService::class)->fetchRaw($user, 'inr', 'LOCAL', 'in');

        $this->assertSame('opaque-provider-schema', $schema);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://gateway.sandbox.nium.test/api/v2/client/client-test/customer/customer-test/currency/INR/validationSchemas')
            && $request['payoutMethod'] === 'LOCAL' && $request['destinationCountry'] === 'IN');
    }

    public function test_missing_payout_method_and_unproven_schema_fail_before_http(): void
    {
        [$provider, $user] = $this->account();
        $beneficiary = $this->beneficiary($provider, $user, []);
        Http::fake();

        try {
            app(NiumBeneficiaryService::class)->createBeneficiary($provider, $beneficiary);
            $this->fail('Missing payoutMethod must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('payoutMethod must be explicit', $exception->getMessage());
        }
        Http::assertNothingSent();

        $beneficiary->update(['raw_data' => ['nium' => ['payoutMethod' => 'LOCAL']]]);
        try {
            app(NiumBeneficiaryService::class)->createBeneficiary($provider, $beneficiary->fresh('user'));
            $this->fail('Unknown schema format must hold.');
        } catch (RuntimeException $exception) {
            $this->assertSame('HOLD_BENEFICIARY_SCHEMA_NOT_PROVEN', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_schema_approval_is_fingerprint_and_corridor_bound(): void
    {
        foreach (['legacy', 'missing_sha', 'wrong_sha', 'currency', 'country', 'payout'] as $case) {
            [$provider, $user] = $this->account();
            $approval = $this->schemaApproval([]);
            $nium = ['payoutMethod' => 'LOCAL', 'schema_sha256' => $approval['schema_sha256'], 'schema_approval' => $approval];
            if ($case === 'legacy') {
                $nium = ['payoutMethod' => 'LOCAL', 'schema' => ['format_proven' => true, 'approved_fields' => []]];
            } elseif ($case === 'missing_sha') {
                $nium['schema_sha256'] = null;
            } elseif ($case === 'wrong_sha') {
                $nium['schema_sha256'] = str_repeat('a', 64);
            } elseif ($case === 'currency') {
                $nium['schema_approval']['currency_code'] = 'USD';
            } elseif ($case === 'country') {
                $nium['schema_approval']['destination_country'] = 'SG';
            } else {
                $nium['schema_approval']['payout_method'] = 'SWIFT';
            }
            Http::fake();

            try {
                app(NiumBeneficiaryService::class)->assertReadyForCreate($this->beneficiary($provider, $user, $nium));
                $this->fail("Schema case {$case} should hold.");
            } catch (RuntimeException $exception) {
                $this->assertSame('HOLD_BENEFICIARY_SCHEMA_NOT_PROVEN', $exception->getMessage());
            }
            Http::assertNothingSent();
        }

        $approval = app(NiumBeneficiaryValidationSchemaService::class)->approval(
            'raw-schema-never-persisted', 'INR', 'IN', 'LOCAL', [], [], '2026-08-14T00:00:00Z', str_repeat('a', 64)
        );
        $this->assertSame(hash('sha256', 'raw-schema-never-persisted'), $approval['schema_sha256']);
        $this->assertSame(strlen('raw-schema-never-persisted'), $approval['schema_length']);
        $this->assertSame(str_repeat('a', 64), $approval['beneficiary_preparation_sha256']);
        $this->assertSame('human_reviewed_factual_nium_schema', $approval['review_source']);
        $this->assertArrayNotHasKey('raw_schema', $approval);
        $this->assertArrayNotHasKey('schema', $approval);

        foreach (['short', str_repeat('A', 64), str_repeat('z', 64)] as $invalid) {
            try {
                app(NiumBeneficiaryValidationSchemaService::class)->approval(
                    'raw', 'INR', 'IN', 'LOCAL', [], [], now()->toISOString(), $invalid
                );
                $this->fail('Invalid preparation fingerprint should hold.');
            } catch (RuntimeException $exception) {
                $this->assertSame('HOLD_BENEFICIARY_PREPARATION_FINGERPRINT_INVALID', $exception->getMessage());
            }
        }
    }

    public function test_production_approval_output_authorizes_unchanged_preparation_only(): void
    {
        [$provider, $user] = $this->account();
        $beneficiary = $this->beneficiary($provider, $user, [
            'payoutMethod' => 'LOCAL', 'schema_approval' => $this->schemaApproval(['beneficiaryAccountNumber']),
        ]);
        $preparationSha = app(NiumBeneficiaryService::class)->preparationFingerprint($beneficiary);
        $approval = app(NiumBeneficiaryValidationSchemaService::class)->approval(
            'reviewed-raw-schema', 'INR', 'IN', 'LOCAL', ['beneficiaryAccountNumber'], [],
            now()->toISOString(), $preparationSha
        );
        $raw = (array) $beneficiary->raw_data;
        $raw['nium']['schema_sha256'] = $approval['schema_sha256'];
        $raw['nium']['schema_approval'] = $approval;
        $beneficiary->update(['raw_data' => $raw]);

        app(NiumBeneficiaryService::class)->assertReadyForCreate($beneficiary->fresh('user.profile'));
        $beneficiary->update(['account_number' => 'changed-after-production-approval']);
        Http::fake();
        try {
            app(NiumBeneficiaryService::class)->assertReadyForCreate($beneficiary->fresh('user.profile'));
            $this->fail('Changed preparation should hold.');
        } catch (RuntimeException $exception) {
            $this->assertSame('HOLD_BENEFICIARY_PREPARATION_CHANGED', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_add_beneficiary_is_flat_and_preserves_exact_bank_account_literals(): void
    {
        foreach (['Current', 'Saving', 'Maestra', 'Checking'] as $literal) {
            [$provider, $user] = $this->account();
            $beneficiary = $this->beneficiary($provider, $user, [
                'payoutMethod' => 'LOCAL',
                'beneficiaryBankAccountType' => $literal,
                'schema_approval' => $this->schemaApproval(['beneficiaryAccountNumber', 'beneficiaryBankAccountType']),
            ]);
            Http::fake(['*' => Http::response(['beneficiaryHashId' => 'bnf-'.$literal], 200)]);

            app(NiumBeneficiaryService::class)->createBeneficiary($provider, $beneficiary);

            Http::assertSent(fn ($request) => $request['beneficiaryBankAccountType'] === $literal
                && $request['beneficiaryAccountNumber'] === '123456'
                && ! array_key_exists('beneficiaryDetail', $request->data())
                && ! array_key_exists('payoutDetail', $request->data()));
        }
    }

    public function test_beneficiary_list_filters_and_persists_both_safe_ids(): void
    {
        [$provider, $user] = $this->account();
        $beneficiary = $this->beneficiary($provider, $user, [
            'payoutMethod' => 'LOCAL',
            'schema_approval' => $this->schemaApproval([]),
        ]);
        $beneficiary->update(['external_beneficiary_id' => 'bnf-1']);
        Http::fake(['*' => Http::response([[
            'beneficiaryHashId' => 'bnf-1', 'payoutHashId' => 'payout-1',
            'destinationCountry' => 'IN', 'destinationCurrency' => 'INR', 'payoutMethod' => 'LOCAL',
            'beneficiaryAccountNumber' => '123456',
        ]])]);

        $result = app(NiumBeneficiaryListService::class)->reconcile($beneficiary->fresh('user'), 'LOCAL');

        $this->assertSame('bnf-1', data_get($result->raw_data, 'nium.reconciliation.beneficiaryHashId'));
        $this->assertSame('payout-1', data_get($result->raw_data, 'nium.reconciliation.payoutHashId'));
        Http::assertSent(fn ($request) => $request['beneficiaryAccountNumber'] === '123456'
            && $request['destinationCurrency'] === 'INR' && $request['payoutMethod'] === 'LOCAL');
        $serializedLog = json_encode(ApiRequestLog::query()->latest('id')->firstOrFail()->getAttributes());
        $this->assertStringNotContainsString('123456', $serializedLog);
    }

    public function test_account_7_reads_never_use_a_later_eligible_account_identifier(): void
    {
        [$provider, $user] = $this->account(7);
        $otherProvider = IntegrationProvider::query()->create(['code' => 'NIUM', 'name' => 'Other Nium', 'status' => 'active']);
        UserProviderAccount::query()->forceCreate([
            'id' => 8, 'user_id' => $user->id, 'provider_id' => $otherProvider->id,
            'external_customer_id' => 'wrong-latest-customer', 'external_account_id' => 'wrong-wallet',
            'status' => 'active', 'provider_status' => 'clear', 'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(), 'provider_ids_verified_at' => now(),
        ]);
        $beneficiary = $this->beneficiary($provider, $user, ['payoutMethod' => 'LOCAL']);
        Http::fake([
            '*validationSchemas*' => Http::response('opaque-schema', 200),
            '*beneficiaries*' => Http::response([], 200),
        ]);

        app(NiumBeneficiaryValidationSchemaService::class)->fetchRaw($user, 'INR', 'LOCAL', 'IN');
        app(NiumBeneficiaryListService::class)->list($beneficiary, ['destinationCurrency' => 'INR', 'payoutMethod' => 'LOCAL']);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/customer/customer-test/')
            && ! str_contains($request->url(), 'wrong-latest-customer'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'wrong-latest-customer'));
    }

    public function test_account_7_provider_mismatch_never_falls_back_to_later_account(): void
    {
        [$provider, $user] = $this->account(7);
        $otherProvider = IntegrationProvider::query()->create(['code' => 'NIUM', 'name' => 'Other Nium', 'status' => 'active']);
        UserProviderAccount::query()->forceCreate([
            'id' => 8, 'user_id' => $user->id, 'provider_id' => $otherProvider->id,
            'external_customer_id' => 'wrong-latest-customer', 'external_account_id' => 'wrong-wallet',
            'status' => 'active', 'provider_status' => 'clear', 'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(), 'provider_ids_verified_at' => now(),
        ]);
        $beneficiary = $this->beneficiary($otherProvider, $user, ['payoutMethod' => 'LOCAL']);
        Http::fake();

        $this->expectExceptionMessage('HOLD_EXACT_ACCOUNT_7_PROVIDER_MISMATCH');
        try {
            app(NiumBeneficiaryListService::class)->list($beneficiary, ['payoutMethod' => 'LOCAL']);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_purpose_codes_are_factual_and_account_7_claim_precedes_service_call(): void
    {
        [$provider, $user] = $this->account(7);
        $protectedUser = User::factory()->create();
        UserProviderAccount::query()->forceCreate(['id' => 4, 'user_id' => $protectedUser->id, 'provider_id' => $provider->id, 'status' => 'active']);
        $otherProvider = IntegrationProvider::query()->create(['code' => 'NIUM', 'name' => 'Other Nium', 'status' => 'active']);
        UserProviderAccount::query()->forceCreate([
            'id' => 8, 'user_id' => $user->id, 'provider_id' => $otherProvider->id,
            'external_customer_id' => 'wrong-latest-customer', 'external_account_id' => 'wrong-wallet',
            'status' => 'active', 'provider_status' => 'clear', 'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(), 'provider_ids_verified_at' => now(),
        ]);
        $beneficiary = $this->beneficiary($provider, $user, [
            'payoutMethod' => 'LOCAL',
            'schema_approval' => $this->schemaApproval([]),
        ]);
        Http::fake([
            'https://gateway.sandbox.nium.test/api/v1/remittance/purposeCodes' => Http::response([['description' => 'Invoice', 'purposeCode' => 'IR001']]),
            '*' => Http::response(['beneficiaryHashId' => 'bnf-created', 'status' => 'ACTIVE'], 200),
        ]);
        app(NiumPurposeCodeService::class)->assertValid($user, 'IR001');

        $runner = app(NiumHkBeneficiaryOneShotRunner::class);
        $result = $runner->run($beneficiary->id, [
            'beneficiary_id' => $beneficiary->id,
            'destinationCountry' => 'IN',
            'destinationCurrency' => 'INR',
            'payoutMethod' => 'LOCAL',
            'schema_sha256' => hash('sha256', 'factual-test-schema'),
            'beneficiary_preparation_sha256' => data_get($beneficiary->raw_data, 'nium.schema_approval.beneficiary_preparation_sha256'),
        ], true);
        $this->assertSame('CREATED', $result['terminal']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/customer/customer-test/beneficiaries'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'wrong-latest-customer'));
        $this->assertSame('created', data_get(UserProviderAccount::findOrFail(7)->metadata, 'nium_beneficiary_first_payout_v1.state'));
        $log = ApiRequestLog::query()->where('operation', 'beneficiary_create')->sole();
        $this->assertSame([], $log->request_body);
        $fresh = Beneficiary::findOrFail($beneficiary->id);
        $this->assertSame('LOCAL', data_get($fresh->raw_data, 'nium.payoutMethod'));
        $this->assertSame(hash('sha256', 'factual-test-schema'), data_get($fresh->raw_data, 'nium.schema_approval.schema_sha256'));
    }

    public function test_account_7_verification_requires_separate_approval_before_any_http(): void
    {
        [$provider, $user] = $this->account(7);
        $protectedUser = User::factory()->create();
        UserProviderAccount::query()->forceCreate(['id' => 4, 'user_id' => $protectedUser->id, 'provider_id' => $provider->id, 'status' => 'active']);
        $beneficiary = $this->beneficiary($provider, $user, [
            'payoutMethod' => 'LOCAL', 'verify_before_create' => true,
            'schema_approval' => $this->schemaApproval([]),
        ]);
        Http::fake();

        $this->expectExceptionMessage('HOLD_ACCOUNT_VERIFICATION_SEPARATE_APPROVAL_REQUIRED');
        try {
            app(NiumHkBeneficiaryOneShotRunner::class)->run($beneficiary->id, [], true);
        } finally {
            Http::assertNothingSent();
            $this->assertNull(data_get(UserProviderAccount::findOrFail(7)->metadata, 'nium_beneficiary_first_payout_v1'));
        }
    }

    public function test_changed_preparation_fails_before_claim_or_http(): void
    {
        [$provider, $user] = $this->account(7);
        $protectedUser = User::factory()->create();
        UserProviderAccount::query()->forceCreate(['id' => 4, 'user_id' => $protectedUser->id, 'provider_id' => $provider->id, 'status' => 'active']);
        $beneficiary = $this->beneficiary($provider, $user, [
            'payoutMethod' => 'LOCAL',
            'schema_approval' => $this->schemaApproval(['beneficiaryAccountNumber']),
        ]);
        $beneficiary->update(['account_number' => 'changed-after-approval']);
        Http::fake();

        $this->expectExceptionMessage('HOLD_BENEFICIARY_PREPARATION_CHANGED');
        try {
            app(NiumHkBeneficiaryOneShotRunner::class)->run($beneficiary->id, [], true);
        } finally {
            Http::assertNothingSent();
            $this->assertNull(data_get(UserProviderAccount::findOrFail(7)->metadata, 'nium_beneficiary_first_payout_v1'));
        }
    }

    public function test_direct_account_7_create_is_blocked_before_http(): void
    {
        [$provider, $user] = $this->account(7);
        $beneficiary = $this->beneficiary($provider, $user, [
            'payoutMethod' => 'LOCAL',
            'schema_approval' => $this->schemaApproval([]),
        ]);
        Http::fake();

        $this->expectExceptionMessage('HOLD_ACCOUNT_7_BENEFICIARY_ONE_SHOT_REQUIRED');
        try {
            app(NiumBeneficiaryService::class)->createBeneficiary($provider, $beneficiary);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_execution_authorization_is_non_static_bound_and_revocable(): void
    {
        $authorization = new NiumBeneficiaryExecutionAuthorization;
        $authorization->authorize(7, 101, 'tuple', 'schema', 'preparation');

        $this->assertTrue($authorization->allows(7, 101, 'tuple', 'schema', 'preparation'));
        $this->assertFalse($authorization->allows(7, 102, 'tuple', 'schema', 'preparation'));
        $this->assertFalse((new NiumBeneficiaryExecutionAuthorization)->allows(7, 101, 'tuple', 'schema', 'preparation'));
        $authorization->revoke();
        $this->assertFalse($authorization->allows(7, 101, 'tuple', 'schema', 'preparation'));
    }

    public function test_unrelated_log_cannot_satisfy_evidence_and_claim_remains_closed(): void
    {
        [$provider, $user] = $this->account(7);
        $protectedUser = User::factory()->create();
        UserProviderAccount::query()->forceCreate(['id' => 4, 'user_id' => $protectedUser->id, 'provider_id' => $provider->id, 'status' => 'active']);
        $beneficiary = $this->beneficiary($provider, $user, [
            'payoutMethod' => 'LOCAL', 'schema_approval' => $this->schemaApproval([]),
        ]);
        $mock = Mockery::mock(NiumBeneficiaryService::class);
        $mock->shouldReceive('assertReadyForCreate')->twice();
        $mock->shouldReceive('preparationFingerprint')->times(4)->andReturn(
            data_get($beneficiary->raw_data, 'nium.schema_approval.beneficiary_preparation_sha256')
        );
        $mock->shouldReceive('createBeneficiary')->once()->andReturnUsing(function ($ignored, Beneficiary $claimed) use ($provider, $user): Beneficiary {
            ApiRequestLog::query()->create([
                'provider_id' => $provider->id, 'user_id' => $user->id, 'operation' => 'transfer_money',
                'request_method' => 'POST', 'endpoint_path' => '/api/v1/remittance',
                'response_status' => 200, 'transport_outcome' => 'response_received', 'is_success' => true,
            ]);
            return $claimed->fresh();
        });
        $authorization = new NiumBeneficiaryExecutionAuthorization;
        $result = (new NiumHkBeneficiaryOneShotRunner($mock, $authorization))->run($beneficiary->id, [
            'beneficiary_id' => $beneficiary->id, 'destinationCountry' => 'IN', 'destinationCurrency' => 'INR',
            'payoutMethod' => 'LOCAL', 'schema_sha256' => hash('sha256', 'factual-test-schema'),
            'beneficiary_preparation_sha256' => data_get($beneficiary->raw_data, 'nium.schema_approval.beneficiary_preparation_sha256'),
        ], true);

        $this->assertSame('OUTCOME_UNKNOWN_NO_RETRY', $result['terminal']);
        $this->assertFalse($authorization->allows(7, $beneficiary->id, 'stale', 'stale', 'stale'));
        $this->assertFalse((new NiumBeneficiaryExecutionAuthorization)->allows(7, $beneficiary->id, 'stale', 'stale', 'stale'));
        $this->assertSame('outcome_unknown_no_retry', data_get(UserProviderAccount::findOrFail(7)->metadata, 'nium_beneficiary_first_payout_v1.state'));
        $this->expectExceptionMessage('HOLD_BENEFICIARY_ALREADY_CLAIMED');
        (new NiumHkBeneficiaryOneShotRunner($mock, new NiumBeneficiaryExecutionAuthorization))->run($beneficiary->id, [
            'beneficiary_id' => $beneficiary->id, 'destinationCountry' => 'IN', 'destinationCurrency' => 'INR',
            'payoutMethod' => 'LOCAL', 'schema_sha256' => hash('sha256', 'factual-test-schema'),
            'beneficiary_preparation_sha256' => data_get($beneficiary->raw_data, 'nium.schema_approval.beneficiary_preparation_sha256'),
        ], true);
    }

    public function test_account_7_server_error_is_one_shot_unknown_with_one_canonical_log(): void
    {
        [$provider, $user] = $this->account(7);
        $protectedUser = User::factory()->create();
        UserProviderAccount::query()->forceCreate(['id' => 4, 'user_id' => $protectedUser->id, 'provider_id' => $provider->id, 'status' => 'active']);
        $beneficiary = $this->beneficiary($provider, $user, [
            'payoutMethod' => 'LOCAL', 'schema_approval' => $this->schemaApproval([]),
        ]);
        $tuple = [
            'beneficiary_id' => $beneficiary->id, 'destinationCountry' => 'IN', 'destinationCurrency' => 'INR',
            'payoutMethod' => 'LOCAL', 'schema_sha256' => hash('sha256', 'factual-test-schema'),
            'beneficiary_preparation_sha256' => data_get($beneficiary->raw_data, 'nium.schema_approval.beneficiary_preparation_sha256'),
        ];
        Http::fake(['*' => Http::response(['code' => 'internal_server_error'], 500)]);

        $authorization = app(NiumBeneficiaryExecutionAuthorization::class);
        $result = app(NiumHkBeneficiaryOneShotRunner::class)->run($beneficiary->id, $tuple, true);

        $this->assertSame('OUTCOME_UNKNOWN_NO_RETRY', $result['terminal']);
        $this->assertFalse($authorization->allows(7, $beneficiary->id, 'stale', 'stale', 'stale'));
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'beneficiary_create')->count());
        $this->expectExceptionMessage('HOLD_BENEFICIARY_ALREADY_CLAIMED');
        app(NiumHkBeneficiaryOneShotRunner::class)->run($beneficiary->id, $tuple, true);
    }

    private function account(?int $accountId = null): array
    {
        $provider = IntegrationProvider::query()->firstOrCreate(['code' => 'nium'], ['name' => 'Nium', 'status' => 'active']);
        $user = User::factory()->create();
        $attributes = [
            'user_id' => $user->id, 'provider_id' => $provider->id,
            'external_customer_id' => 'customer-test', 'external_account_id' => 'wallet-test',
            'status' => 'active', 'provider_status' => 'clear',
            'customer_id_verified_at' => now(), 'wallet_id_verified_at' => now(), 'provider_ids_verified_at' => now(),
        ];
        $accountId === null
            ? UserProviderAccount::query()->create($attributes)
            : UserProviderAccount::query()->forceCreate(['id' => $accountId, ...$attributes]);
        config()->set('services.nium.supported_corridors', [[
            'destinationCountry' => 'IN', 'destinationCurrency' => 'INR', 'payoutMethod' => 'LOCAL',
            'beneficiaryAccountType' => 'INDIVIDUAL', 'customerType' => 'INDIVIDUAL',
        ]]);

        return [$provider, $user];
    }

    private function beneficiary(IntegrationProvider $provider, User $user, array $nium): Beneficiary
    {
        if (isset($nium['schema_approval']) && ! array_key_exists('schema_sha256', $nium)) {
            $nium['schema_sha256'] = $nium['schema_approval']['schema_sha256'];
        }

        $beneficiary = Beneficiary::query()->create([
            'user_id' => $user->id, 'provider_id' => $provider->id, 'beneficiary_type' => 'personal',
            'full_name' => 'Jane Doe', 'country_code' => 'IN', 'currency' => 'INR',
            'account_number' => '123456', 'status' => 'pending', 'raw_data' => ['nium' => $nium],
        ])->load('user.profile');
        if (isset($nium['schema_approval'])) {
            $raw = (array) $beneficiary->raw_data;
            $raw['nium']['schema_approval']['beneficiary_preparation_sha256'] = app(NiumBeneficiaryService::class)->preparationFingerprint($beneficiary);
            $beneficiary->update(['raw_data' => $raw]);
        }

        return $beneficiary->fresh('user.profile');
    }

    private function schemaApproval(array $approvedFields, array $requiredFields = []): array
    {
        return [
            'schema_sha256' => hash('sha256', 'factual-test-schema'),
            'beneficiary_preparation_sha256' => str_repeat('0', 64),
            'schema_length' => strlen('factual-test-schema'),
            'currency_code' => 'INR',
            'destination_country' => 'IN',
            'payout_method' => 'LOCAL',
            'approved_fields' => $approvedFields,
            'required_fields' => $requiredFields,
            'reviewed_at' => now()->toISOString(),
            'review_source' => 'human_reviewed_factual_nium_schema',
        ];
    }
}
