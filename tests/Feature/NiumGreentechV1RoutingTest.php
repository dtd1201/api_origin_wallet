<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\NiumApiExchangeEvidence;
use App\Models\NiumVirtualAccount;
use App\Models\NiumVirtualAccountOperation as Operation;
use App\Models\NiumVirtualAccountRequest;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use App\Services\Nium\NiumPaymentIdService;
use App\Services\Nium\NiumVirtualAccountReconciliationService;
use App\Services\Nium\NiumVirtualAccountWebhookContract;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesNiumVirtualAccountLedgerFixtures;
use Tests\TestCase;

class NiumGreentechV1RoutingTest extends TestCase
{
    use CreatesNiumVirtualAccountLedgerFixtures;

    private const BODY = ['accountCategory' => 'SELF_FUNDING_ACCOUNT', 'bankName' => 'JPM_SG', 'currencyCode' => 'USD'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.nium_greentech_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('nium_greentech_test');
        $this->artisan('migrate', ['--database' => 'nium_greentech_test', '--force' => true])->assertSuccessful();
        $this->configureLedgerTarget();
        config([
            'services.nium.auth' => ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'synthetic-key'],
            'services.nium.webhook.static_header_name' => 'x-partner-key',
            'services.nium.webhook.static_header_value' => 'synthetic-partner-key',
            'services.nium.greentech_v1_account_id' => null,
            'services.nium.va_single_dispatch.enabled' => true,
            'services.nium.va_single_dispatch.old_workers_fenced' => true,
            'services.nium.va_single_dispatch.account_ids' => [],
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        DB::purge('nium_greentech_test');
        parent::tearDown();
    }

    private function account(): UserProviderAccount
    {
        $account = $this->ledgerAccount();
        config([
            'services.nium.greentech_v1_account_id' => (string) $account->id,
            'services.nium.va_single_dispatch.account_ids' => [$account->id],
        ]);
        $token = Str::random(80);
        ApiToken::query()->create([
            'user_id' => $account->user_id, 'name' => 'greentech-routing-test',
            'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(),
        ]);
        $this->withToken($token);

        return $account;
    }

    private function submit(UserProviderAccount $account, string $key = 'controlled-request', array $overrides = []): TestResponse
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson(
            '/api/user/users/'.$account->user_id.'/provider-accounts/nium/virtual-account',
            array_replace([
                'currency' => 'USD', 'account_category' => 'SELF_FUNDING_ACCOUNT',
                'account_type' => 'WIRES', 'expected_provider_account_id' => $account->id,
            ], $overrides),
        );
    }

    public function test_normal_api_selects_exact_v1_without_get_and_replays_frozen_contract(): void
    {
        $account = $this->account();
        Http::fake(function ($outgoing) use ($account) {
            $this->assertSame('POST', $outgoing->method());
            $this->assertSame('https://nium.test/api/v1/client/'.config('services.nium.client_id')
                .'/customer/'.$account->external_customer_id.'/wallet/'.$account->external_account_id.'/paymentId', $outgoing->url());
            $this->assertSame(self::BODY, $outgoing->data());
            $this->assertArrayNotHasKey('accountType', $outgoing->data());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame(1, NiumVirtualAccountRequest::query()->sole()->dispatchAttempt()->count());

            return Http::response(['uniquePaymentId' => 'VA-GREENTECH'] + self::BODY);
        });

        $this->submit($account)->assertCreated()->assertJsonPath('virtual_account.provider_payment_id', 'VA-GREENTECH');
        $request = NiumVirtualAccountRequest::query()->sole();
        $identity = $request->snapshot;
        $this->assertSame('v1', $identity['api_version']);
        $this->assertSame(self::BODY, json_decode(NiumApiExchangeEvidence::query()->sole()->raw_request_body, true));
        $this->assertSame('controlled-request', $request->operation->operation_id);
        config([
            'services.nium.greentech_v1_account_id' => null,
            'services.nium.va_single_dispatch.enabled' => false,
        ]);
        $this->submit($account)->assertCreated()->assertJsonPath('virtual_account.provider_payment_id', 'VA-GREENTECH');
        $this->assertSame($identity, $request->fresh()->snapshot);
        $this->assertSame(1, $request->dispatchAttempt()->count());
        Http::assertSentCount(1);
    }

    #[DataProvider('uncertainOutcomes')]
    public function test_uncertain_result_is_persisted_and_no_key_can_automatically_repost(string $outcome, string $state): void
    {
        $account = $this->account();
        $calls = 0;
        Http::fake(function ($outgoing) use ($outcome, &$calls) {
            $calls++;
            $this->assertSame('POST', $outgoing->method());
            if ($outcome === 'timeout') {
                throw new ConnectionException('Synthetic timeout after possible provider acceptance');
            }

            return match ($outcome) {
                'initialized' => Http::response(['uniquePaymentId' => 'INITIALIZED']),
                'unknown' => Http::response([]),
                'server error' => Http::response([], 503),
            };
        });

        $this->submit($account)->assertStatus(202)->assertJsonPath('virtual_account', null)->assertJsonPath('operation.status', $state);
        $request = NiumVirtualAccountRequest::query()->sole();
        $this->assertSame($state, $request->fresh()->operation_state);
        $this->submit($account)->assertStatus(202)->assertJsonPath('operation.status', $state);
        $this->submit($account, 'replacement-key')->assertStatus(422);
        $this->assertSame(1, $calls);
        $this->assertSame(1, $request->dispatchAttempt()->count());
        $this->assertSame($request->operation_id, $request->assignmentScope()->value('active_operation_id'));
        $this->assertSame(1, Operation::query()->count());
        $this->assertSame(0, NiumVirtualAccount::query()->count());
    }

    public static function uncertainOutcomes(): array
    {
        return [
            ['timeout', Operation::UNKNOWN], ['unknown', Operation::UNKNOWN],
            ['server error', Operation::UNKNOWN], ['initialized', Operation::PENDING],
        ];
    }

    #[DataProvider('nonselectedCorridors')]
    public function test_nonselected_corridors_preserve_legacy_v2_get_and_post(string $difference): void
    {
        $account = $this->account();
        config(['services.nium.va_single_dispatch.enabled' => false]);
        $currency = $difference === 'currency' ? 'EUR' : 'USD';
        $category = $difference === 'category' ? 'COLLECTION_ACCOUNT' : 'SELF_FUNDING_ACCOUNT';
        $rail = $difference === 'rail' ? 'LOCAL' : 'WIRES';
        $bank = $difference === 'bank' ? 'DBS_HK' : 'JPM_SG';
        if ($difference === 'account') {
            config(['services.nium.greentech_v1_account_id' => (string) ($account->id + 100)]);
        } elseif ($difference === 'unconfigured') {
            config(['services.nium.greentech_v1_account_id' => null]);
        }
        Http::fake(fn ($outgoing) => $outgoing->method() === 'GET'
            ? Http::response(['content' => [], 'last' => true])
            : Http::response(['uniquePaymentId' => 'VA-LEGACY']));

        $result = app(NiumPaymentIdService::class)->assign($account, $currency, $category, $rail, $bank, 'legacy-request');
        $this->assertInstanceOf(NiumVirtualAccount::class, $result);
        $calls = Http::recorded();
        $this->assertSame('GET', $calls[0][0]->method());
        $this->assertSame('POST', $calls[1][0]->method());
        $this->assertSame('https://nium.test/api/v2/client/'.config('services.nium.client_id')
            .'/customer/'.$account->external_customer_id.'/wallet/'.$account->external_account_id.'/paymentId', $calls[1][0]->url());
        $this->assertSame([
            'currency' => $currency, 'accountCategory' => $category, 'accountType' => $rail, 'bankName' => $bank,
        ], $calls[1][0]->data());
        $this->assertSame(0, NiumVirtualAccountRequest::query()->count());
        Http::assertSentCount(2);
    }

    public static function nonselectedCorridors(): array
    {
        return [['account'], ['currency'], ['category'], ['rail'], ['bank'], ['unconfigured']];
    }

    public function test_existing_enrolled_v2_operation_is_not_reversioned_by_new_selector(): void
    {
        $account = $this->account();
        config(['services.nium.greentech_v1_account_id' => null]);
        Http::fake(fn () => Http::response([], 409));
        $this->submit($account)->assertStatus(202);
        $request = NiumVirtualAccountRequest::query()->sole();
        $this->assertSame('v2', $request->snapshot['api_version']);
        $before = $request->getAttributes();
        config(['services.nium.greentech_v1_account_id' => (string) $account->id]);
        $this->submit($account)->assertStatus(202);
        $this->assertSame($before, $request->fresh()->getAttributes());
        Http::assertSentCount(1);
        Http::assertSent(fn ($outgoing) => str_contains($outgoing->url(), '/api/v2/'));
    }

    public function test_existing_legacy_key_is_not_enrolled_or_redispatched(): void
    {
        $account = $this->account();
        config(['services.nium.va_single_dispatch.enabled' => false]);
        $pending = NiumVirtualAccount::query()->create([
            'user_provider_account_id' => $account->id, 'currency' => 'USD',
            'account_category' => 'SELF_FUNDING_ACCOUNT', 'account_type' => 'WIRES', 'status' => 'pending',
        ]);
        $operation = $this->legacyLedgerOperation($account, Operation::UNKNOWN, [
            'operation_id' => 'controlled-request', 'account_category' => 'SELF_FUNDING_ACCOUNT',
            'account_type' => 'WIRES', 'submitted_at' => now(), 'nium_virtual_account_id' => $pending->id,
        ]);
        Http::fake(fn ($outgoing) => Http::response(['content' => [], 'last' => true]));
        $this->submit($account)->assertSuccessful();
        $this->assertSame($operation->id, Operation::query()->sole()->id);
        $this->assertSame(0, NiumVirtualAccountRequest::query()->count());
        Http::assertNotSent(fn ($outgoing) => $outgoing->method() !== 'GET');
    }

    #[DataProvider('gateInterlocks')]
    public function test_selected_corridor_does_not_bypass_safety_gates(string $gate): void
    {
        $account = $this->account();
        Http::fake();
        match ($gate) {
            'disabled' => config(['services.nium.va_single_dispatch.enabled' => false]),
            'production' => $this->app->instance('env', 'production'),
            'allowlist' => config(['services.nium.va_single_dispatch.account_ids' => []]),
            'workers' => config(['services.nium.va_single_dispatch.old_workers_fenced' => false]),
            'unverified wallet' => $account->update(['wallet_id_verified_at' => null]),
            'transaction' => DB::beginTransaction(),
        };
        try {
            $this->submit($account)->assertStatus(422);
            $this->assertSame(0, Operation::query()->count());
            $this->assertSame(0, NiumVirtualAccountRequest::query()->count());
            Http::assertNothingSent();
        } finally {
            if ($gate === 'transaction') {
                DB::rollBack();
            }
        }
    }

    public static function gateInterlocks(): array
    {
        return [['disabled'], ['production'], ['allowlist'], ['workers'], ['unverified wallet'], ['transaction']];
    }

    public function test_api_account_guard_and_idempotency_key_validation_remain_effective(): void
    {
        $account = $this->account();
        Http::fake();
        $this->submit($account, overrides: ['expected_provider_account_id' => $account->id + 100])->assertStatus(409);
        $this->submit($account, '')->assertStatus(422);
        $this->submit($account, 'invalid key')->assertStatus(422);
        $this->assertSame(0, Operation::query()->count());
        Http::assertNothingSent();
    }

    public function test_unresolved_legacy_history_blocks_a_new_selected_operation(): void
    {
        $account = $this->account();
        $historical = $this->legacyLedgerOperation($account, Operation::UNKNOWN);
        Http::fake();
        $this->submit($account)->assertStatus(422);
        $this->assertSame($historical->id, Operation::query()->sole()->id);
        $this->assertSame(0, NiumVirtualAccountRequest::query()->count());
        $this->assertSame(0, DB::table('nium_va_dispatch_attempts')->count());
        Http::assertNothingSent();
    }

    public function test_authenticated_webhook_and_later_v2_get_do_not_invent_ownership_or_repost(): void
    {
        $account = $this->account();
        Http::fake(fn ($outgoing) => $outgoing->method() === 'POST'
            ? Http::response(['uniquePaymentId' => 'INITIALIZED'])
            : Http::response(['content' => [], 'last' => true]));
        $this->submit($account)->assertStatus(202);
        $request = NiumVirtualAccountRequest::query()->sole();
        $payload = [
            'eventId' => 'greentech-assigned', 'template' => NiumVirtualAccountWebhookContract::ASSIGNED,
            'clientHashId' => config('services.nium.client_id'), 'customerHashId' => $account->external_customer_id,
            'walletHashId' => $account->external_account_id, 'currencyCode' => 'USD', 'uniquePaymentId' => 'VA-UNPROVEN',
        ];
        $this->withHeader('x-partner-key', 'wrong-key')->postJson('/api/webhooks/providers/nium', $payload)->assertForbidden();
        $this->assertSame(0, WebhookEvent::query()->count());
        $this->withHeader('x-partner-key', 'synthetic-partner-key')->postJson('/api/webhooks/providers/nium', $payload)
            ->assertOk()->assertJsonPath('processing_status', 'observed');
        $this->assertSame('verified_x_partner_key', WebhookEvent::query()->sole()->nium_va_evidence['authentication']);
        Http::assertSentCount(1);

        app(NiumVirtualAccountReconciliationService::class)->reconcile($request->operation);
        $calls = Http::recorded();
        $this->assertSame('GET', $calls[1][0]->method());
        $this->assertStringStartsWith('https://nium.test/api/v2/client/'.config('services.nium.client_id')
            .'/customer/'.$account->external_customer_id.'/wallet/'.$account->external_account_id.'/paymentIds?', $calls[1][0]->url());
        Http::assertSentCount(2);
        $this->assertSame(0, NiumVirtualAccount::query()->count());
        $this->assertSame(1, $request->dispatchAttempt()->count());
        $this->submit($account)->assertStatus(202)->assertJsonPath('virtual_account', null);
        Http::assertSentCount(2);
    }
}
