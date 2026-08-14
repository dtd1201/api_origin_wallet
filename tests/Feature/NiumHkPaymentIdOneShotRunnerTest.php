<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\NiumVirtualAccount;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumHkPaymentIdOneShotRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class NiumHkPaymentIdOneShotRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.nium.base_url', 'https://gateway.sandbox.nium.test');
        config()->set('services.nium.client_id', 'client-test');
        config()->set('services.nium.assign_payment_id_endpoint', '/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/paymentId');
        config()->set('services.nium.auth', ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'test-key']);
        config()->set('services.nium.webhook', [
            'static_header_name' => 'x-partner-key', 'static_header_value' => 'test-partner-key',
        ]);
        $this->seedAccounts();
    }

    public function test_audit_has_no_defaults_writes_or_http_and_contract_remains_unproven(): void
    {
        $before = $this->immutableEvidence();

        $result = $this->runner()->audit('USD', 'COLLECTION_ACCOUNT', 'TEST_BANK');

        $this->assertSame('HOLD_VAN_CONTRACT_NOT_PROVEN', $result['terminal']);
        $this->assertSame(0, $result['assign_payment_id_post_count']);
        $this->assertSame(0, $result['db_write_count']);
        $this->assertArrayNotHasKey('nium_assign_payment_id_one_shot_v1', $this->account()->metadata);
        $this->assertSame($before, $this->immutableEvidence());
        Http::assertNothingSent();
    }

    public function test_human_approval_and_exact_eligibility_are_required_before_claim_or_http(): void
    {
        try {
            $this->runner()->run('USD', 'COLLECTION_ACCOUNT', 'JPM_SG');
            $this->fail('Expected explicit approval to be required.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Separate human approval of the exact Account 7 VAN tuple is required.', $exception->getMessage());
        }

        $this->account()->forceFill(['status' => 'under_review'])->save();
        try {
            $this->runner()->run('USD', 'COLLECTION_ACCOUNT', 'JPM_SG', separateHumanApproval: true);
            $this->fail('Expected eligibility to fail closed.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertArrayNotHasKey('nium_assign_payment_id_one_shot_v1', $this->account()->metadata);
        Http::assertNothingSent();
    }

    public function test_exact_account_7_binding_mismatch_blocks_before_claim_http_and_va_mutation(): void
    {
        IntegrationProvider::query()->forceCreate(['id' => 2, 'code' => 'other', 'name' => 'Other', 'status' => 'active']);
        $this->account()->forceFill(['provider_id' => 2])->save();
        UserProviderAccount::query()->forceCreate([
            'id' => 8, 'user_id' => 9, 'provider_id' => 1, 'external_customer_id' => 'customer-other',
            'external_account_id' => 'wallet-other', 'status' => 'active', 'provider_status' => 'clear',
            'customer_id_verified_at' => now(), 'wallet_id_verified_at' => now(),
        ]);

        try {
            $this->runner()->run('USD', 'COLLECTION_ACCOUNT', 'JPM_SG', separateHumanApproval: true);
            $this->fail('Expected Account 7 binding mismatch to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Account 7 is not the exact authoritative eligible Nium account.', $exception->getMessage());
        }

        $this->assertArrayNotHasKey('nium_assign_payment_id_one_shot_v1', $this->account()->metadata);
        $this->assertSame(0, NiumVirtualAccount::query()->count());
        Http::assertNothingSent();
    }

    public function test_claim_precedes_single_successful_post_and_replay_is_blocked(): void
    {
        $before = $this->immutableEvidence();
        Http::fake(function ($request) {
            $this->assertSame('submitting', $this->claim()['state']);
            $this->assertSame([
                'bankName' => 'JpM_Sg',
                'currencyCode' => 'USD',
                'accountCategory' => 'COLLECTION_ACCOUNT',
            ], $request->data());

            return [
                'uniquePaymentId' => 'VA-ACCOUNT-7', 'currencyCode' => 'USD',
                'accountCategory' => 'COLLECTION_ACCOUNT',
            ];
        });

        $result = $this->runner()->run(
            'USD', 'COLLECTION_ACCOUNT', '  JpM_Sg  ', separateHumanApproval: true,
        );

        $this->assertSame('ASSIGNED', $result['terminal']);
        $this->assertSame(1, $result['assign_payment_id_post_count']);
        $this->assertDatabaseHas('nium_virtual_accounts', [
            'user_provider_account_id' => 7, 'provider_payment_id' => 'VA-ACCOUNT-7',
            'currency' => 'USD', 'account_category' => 'COLLECTION_ACCOUNT',
            'account_type' => null, 'status' => 'assigned',
        ]);
        $this->assertSame($before, $this->immutableEvidence());
        $this->assertSame(substr(hash('sha256', 'JpM_Sg'), 0, 16), $this->claim()['bank_name_fingerprint']);
        $this->assertSame(substr(hash('sha256', json_encode([
            'bankName' => 'JpM_Sg',
            'currencyCode' => 'USD',
            'accountCategory' => 'COLLECTION_ACCOUNT',
        ], JSON_THROW_ON_ERROR)), 0, 16), $this->claim()['payload_fingerprint']);
        $this->assertSafeClaim('assigned');
        $this->assertReplayBlocked(1);
    }

    public function test_v1_contract_requires_bank_currency_and_supported_non_null_category_without_http(): void
    {
        foreach ([
            ['US', 'SELF_FUNDING_ACCOUNT', 'JPM_SG'],
            ['USD', 'SELF_FUNDING_AND_COLLECTION_ACCOUNT', 'JPM_SG'],
            ['USD', 'SELF_FUNDING_ACCOUNT', ''],
        ] as [$currencyCode, $accountCategory, $bankName]) {
            try {
                $this->runner()->audit($currencyCode, $accountCategory, $bankName);
                $this->fail('Expected invalid Account 7 V1 tuple to hold before HTTP.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Invalid explicit Nium Assign Payment ID tuple.', $exception->getMessage());
            }
        }

        $this->assertArrayNotHasKey('nium_assign_payment_id_one_shot_v1', $this->account()->metadata);
        Http::assertNothingSent();
    }

    public function test_other_provider_log_cannot_satisfy_evidence_change_terminal_or_inflate_post_count(): void
    {
        IntegrationProvider::query()->forceCreate(['id' => 2, 'code' => 'other', 'name' => 'Other', 'status' => 'active']);
        Http::fake(function () {
            ApiRequestLog::query()->forceCreate([
                'provider_id' => 2, 'user_id' => 9, 'operation' => 'assign_payment_id',
                'request_method' => 'POST', 'request_url' => '/unrelated', 'response_status' => 400,
                'transport_outcome' => 'response_received', 'is_success' => false,
            ]);

            return Http::response([], 200);
        });

        $result = $this->runner()->run('USD', 'COLLECTION_ACCOUNT', 'JPM_SG', separateHumanApproval: true);

        $this->assertSame('OUTCOME_UNKNOWN_NO_RETRY', $result['terminal']);
        $this->assertSame(1, $result['assign_payment_id_post_count']);
        $this->assertSame(1, ApiRequestLog::query()->where('provider_id', 1)->where('operation', 'assign_payment_id')->count());
        $this->assertSame(1, ApiRequestLog::query()->where('provider_id', 2)->where('operation', 'assign_payment_id')->count());
        $this->assertSame(200, $this->claim()['provider_http_status']);
    }

    public function test_4xx_5xx_and_malformed_2xx_are_permanent_one_shot_outcomes(): void
    {
        Http::fake(['*' => Http::sequence()->push([], 400)->push([], 500)->push([], 200)]);
        foreach (['REJECTED_NO_RETRY', 'OUTCOME_UNKNOWN_NO_RETRY', 'OUTCOME_UNKNOWN_NO_RETRY'] as $terminal) {
            $result = $this->runner()->run('USD', 'COLLECTION_ACCOUNT', 'JPM_SG', separateHumanApproval: true);

            $this->assertSame($terminal, $result['terminal']);
            $this->assertSame(1, $result['assign_payment_id_post_count']);
            $this->assertReplayBlocked(1);
            $this->resetExecutionEvidence();
        }
    }

    public function test_connection_and_local_persistence_ambiguity_are_unknown_without_retry(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $result = $this->runner()->run('USD', 'COLLECTION_ACCOUNT', 'JPM_SG', separateHumanApproval: true);
        $this->assertSame('OUTCOME_UNKNOWN_NO_RETRY', $result['terminal']);
        $this->assertReplayBlocked(1);

        $this->resetExecutionEvidence();
        NiumVirtualAccount::creating(static fn () => throw new RuntimeException('local persistence failed'));
        Http::fake(['*' => Http::response(['uniquePaymentId' => 'VA-POSSIBLY-ASSIGNED'], 200)]);
        $result = $this->runner()->run('USD', 'COLLECTION_ACCOUNT', 'JPM_SG', separateHumanApproval: true);
        $this->assertSame('OUTCOME_UNKNOWN_NO_RETRY', $result['terminal']);
        $this->assertReplayBlocked(1);
    }

    public function test_existing_exact_tuple_blocks_and_account_4_remains_unchanged(): void
    {
        $account4 = UserProviderAccount::query()->findOrFail(4)->getRawOriginal();
        NiumVirtualAccount::query()->create([
            'user_provider_account_id' => 7, 'provider_payment_id' => 'VA-EXISTING',
            'virtual_account_reference' => 'VA-EXISTING', 'currency' => 'USD',
            'account_category' => 'COLLECTION_ACCOUNT', 'account_type' => null, 'status' => 'assigned',
        ]);

        try {
            $this->runner()->run('USD', 'COLLECTION_ACCOUNT', 'JPM_SG', separateHumanApproval: true);
            $this->fail('Expected an assigned tuple to block.');
        } catch (RuntimeException $exception) {
            $this->assertSame('HOLD_ASSIGN_PAYMENT_ID_TUPLE_ALREADY_ASSIGNED', $exception->getMessage());
        }

        $this->assertSame($account4, UserProviderAccount::query()->findOrFail(4)->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_generic_cli_refuses_account_7_before_http(): void
    {
        $this->artisan('nium:assign-payment-id', [
            'account' => 7, 'currencyCode' => 'USD', '--account-category' => 'COLLECTION_ACCOUNT',
            '--bank-name' => 'JPM_SG',
        ])->assertFailed()->expectsOutput('Account 7 requires the dedicated human-approved Nium payment ID one-shot path.');

        Http::assertNothingSent();
        $this->assertSame(0, NiumVirtualAccount::query()->count());
    }

    private function assertReplayBlocked(int $posts): void
    {
        try {
            $this->runner()->run('USD', 'COLLECTION_ACCOUNT', 'JPM_SG', separateHumanApproval: true);
            $this->fail('Expected one-shot replay to be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('HOLD_ASSIGN_PAYMENT_ID_ONE_SHOT_ALREADY_CLAIMED', $exception->getMessage());
        }
        $this->assertSame($posts, ApiRequestLog::query()->where('provider_id', 1)
            ->where('user_id', 9)->where('operation', 'assign_payment_id')->count());
    }

    private function assertSafeClaim(string $state): void
    {
        $claim = $this->claim();
        $this->assertSame($state, $claim['state']);
        $this->assertSame('USD', $claim['currency_code']);
        $this->assertArrayNotHasKey('account_type', $claim);
        foreach (['external_customer_id', 'external_account_id', 'customerHashId', 'walletHashId', 'raw_response'] as $field) {
            $this->assertArrayNotHasKey($field, $claim);
        }
    }

    private function resetExecutionEvidence(): void
    {
        ApiRequestLog::query()->where('operation', 'assign_payment_id')->delete();
        NiumVirtualAccount::query()->delete();
        $account = $this->account();
        $metadata = $account->metadata;
        unset($metadata['nium_assign_payment_id_one_shot_v1']);
        $account->forceFill(['metadata' => $metadata])->save();
    }

    private function immutableEvidence(): array
    {
        return [
            'account_4' => UserProviderAccount::query()->findOrFail(4)->getRawOriginal(),
            'historical_logs' => ApiRequestLog::query()->whereIn('id', [104, 106, 113, 114, 115, 116, 117, 118, 119])
                ->orderBy('id')->get()->map->getRawOriginal()->all(),
        ];
    }

    private function account(): UserProviderAccount
    {
        return UserProviderAccount::query()->findOrFail(7);
    }

    private function claim(): array
    {
        return $this->account()->metadata['nium_assign_payment_id_one_shot_v1'];
    }

    private function runner(): NiumHkPaymentIdOneShotRunner
    {
        return app(NiumHkPaymentIdOneShotRunner::class);
    }

    private function seedAccounts(): void
    {
        IntegrationProvider::query()->forceCreate(['id' => 1, 'code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        User::factory()->create(['id' => 4]);
        User::factory()->create(['id' => 9]);
        UserProviderAccount::query()->forceCreate(['id' => 4, 'user_id' => 4, 'provider_id' => 1]);
        UserProviderAccount::query()->forceCreate([
            'id' => 7, 'user_id' => 9, 'provider_id' => 1,
            'external_customer_id' => 'customer-account-7', 'external_account_id' => 'wallet-account-7',
            'status' => 'active', 'provider_status' => 'clear', 'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(), 'metadata' => [],
        ]);
    }
}
