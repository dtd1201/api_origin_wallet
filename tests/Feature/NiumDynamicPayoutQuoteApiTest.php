<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutAmountMode;
use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Models\FxQuote;
use App\Models\NiumApiExchangeEvidence;
use App\Models\NiumDynamicIntent;
use App\Models\NiumDynamicPayoutOutbox;
use App\Models\NiumDynamicPayoutQuote;
use App\Models\NiumDynamicReservation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Nium\DynamicConfirmation\ExactProviderJson;
use App\Services\Nium\DynamicConfirmation\OriginDynamicPayoutEvidenceSource;
use App\Services\Nium\NiumDynamicPayoutQuoteService;
use App\Services\Nium\NiumDynamicReviewService;
use App\Services\Nium\NiumService;
use App\Services\Nium\NiumBeneficiaryService;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutDispatcher;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutTransport;
use Tests\Support\DynamicPayoutTransportStub;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutWebhook;
use App\Models\IntegrationProvider;
use RuntimeException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesDynamicConfirmationFixtures;
use Tests\Concerns\CreatesFinalizedUsdConfirmationFixtures;
use Tests\Support\DynamicConfirmationDatabaseTestCase;

/** Real mapping and persistence code; NiumService is the mocked transport boundary. */
final class NiumDynamicPayoutQuoteApiTest extends DynamicConfirmationDatabaseTestCase
{
    use CreatesDynamicConfirmationFixtures;
    use CreatesFinalizedUsdConfirmationFixtures;

    private NiumService $nium;
    private NiumApiExchangeEvidence $exchange;
    private bool $corridorWithdrawn = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDynamicFixtures('2000');
        $this->wallet->update(['currency' => 'USD']);
        config(['services.nium.base_url' => 'https://gatewaysandbox.nium.com',
            'services.nium.client_id' => 'fixture-client', 'services.nium.payout_fx_enabled' => true,
            'nium_dynamic_confirmation.automatic_dispatch_enabled' => true,
            'nium_dynamic_payouts.reconciliation_enabled' => true,
            'services.nium.quote_endpoint' => '/api/v1/client/{clientHashId}/quotes',
            'services.nium.beneficiary_endpoint' => '/api/v2/client/{clientHashId}/customer/{customerHashId}/beneficiaries',
            'services.nium.supported_corridors_endpoint' => '/api/v3/client/{clientHashId}/supportedCorridors',
            'cache.default' => 'array']);
        ApiToken::query()->create(['user_id' => $this->owner->id, 'name' => 'offline-reference',
            'token_hash' => hash('sha256', 'offline-reference'), 'expires_at' => now()->addDay()]);
        $this->withToken('offline-reference');
        $url = 'https://gatewaysandbox.nium.com/api/v2/client/[REDACTED]/customer/[REDACTED]/beneficiaries';
        $log = ApiRequestLog::query()->create(['provider_id' => $this->account->provider_id, 'user_id' => $this->owner->id,
            'operation' => 'beneficiary_create', 'client_hash_id' => 'fixture-client',
            'external_reference' => (string) $this->beneficiary->id, 'request_method' => 'POST',
            'request_url' => $url, 'request_body' => ['payout_method' => 'LOCAL'],
            'response_status' => 200, 'is_success' => true, 'created_at' => now(),
            'response_body' => ['beneficiary_id' => $this->beneficiary->external_beneficiary_id]]);
        $request = '{"payoutMethod":"LOCAL","destinationCountry":"GB","destinationCurrency":"GBP"}';
        $response = '{"beneficiaryHashId":"fixture-registered-payee"}';
        $this->exchange = NiumApiExchangeEvidence::query()->create([
            'api_request_log_id' => $log->id, 'request_method' => 'POST', 'request_url' => $url,
            'raw_request_body' => $request, 'raw_response_body' => $response,
            'request_body_sha256' => hash('sha256', $request), 'response_body_sha256' => hash('sha256', $response),
            'capture_version' => 1, 'response_received' => true]);
        $this->nium = Mockery::mock(NiumService::class)->makePartial();
        $this->nium->shouldReceive('clientId')->andReturn('fixture-client');
        $this->app->instance(NiumService::class, $this->nium);
        $this->nium->shouldReceive('post')->withArgs(static fn ($path, $payload, $user, $transferId, $operation): bool =>
            $operation === 'dynamic_remittance_validate')->andReturnUsing(function ($path, $payload, $user,
                $transferId, $operation, $reference, $beforeDispatch): Response {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertSame('/api/v1/client/fixture-client/customer/fixture-customer/wallet/fixture-wallet/remittance/validate', $path);
                $this->assertArrayNotHasKey('additionalFees', $payload);
                $this->assertArrayNotHasKey('additionalFees', $payload['payout']);
                $beforeDispatch(new Request(new PsrRequest('POST', 'https://gatewaysandbox.nium.com'.$path,
                    ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR))));
                return $this->response('{"valid":true,"issues":[]}');
            });
        $this->nium->shouldReceive('get')->withArgs(static fn ($path, $query = [], $user = null, $operation = null): bool =>
            $operation === 'dynamic_wallet_availability')->andReturnUsing(function (): Response {
                $this->assertSame(0, DB::transactionLevel());
                return $this->response('{"walletHashId":"fixture-wallet","currencies":[{"currencyCode":"USD","availableBalance":2000}]}');
            });
    }

    private function path(string $suffix): string
    {
        return '/api/user/users/'.$this->owner->id.'/nium/dynamic-payouts'.$suffix;
    }

    private function response(string $body): Response
    {
        return new Response(new PsrResponse(200, ['Content-Type' => 'application/json'], $body));
    }

    private function catalogue(bool $supported = true): void
    {
        // Configured corridor entries must not substitute for provider catalogue evidence.
        config(['services.nium.supported_corridors' => [['destinationCountry' => 'GB',
            'destinationCurrency' => 'GBP', 'payoutMethod' => 'LOCAL',
            'beneficiaryAccountType' => 'INDIVIDUAL', 'customerType' => 'INDIVIDUAL']]]);
        $this->nium->shouldReceive('get')->once()->withArgs(function ($path, $query, $user): bool {
            $this->assertSame(0, DB::transactionLevel());

            return $path === '/api/v3/client/fixture-client/supportedCorridors'
                && $query['destinationCurrency'] === 'GBP' && $user->id === $this->owner->id;
        })->andReturn($this->response($supported
            ? '{"content":[{"destinationCountry":"GB","destinationCurrency":"GBP","payoutMethod":"LOCAL","beneficiaryAccountType":"INDIVIDUAL","customerType":"INDIVIDUAL"}]}'
            : '{"content":[]}'));
    }

    private function providerQuote(string $mode = 'SOURCE_AMOUNT', ?string $body = null, ?\Closure $during = null): void
    {
        $this->nium->shouldReceive('post')->once()->withArgs(static fn ($path, $payload, $user, $transferId, $operation): bool =>
            $operation === 'dynamic_payout_reference_quote')->andReturnUsing(function ($path, $payload, $user,
            $relatedTransferId, $operation, $externalReference, $beforeDispatch) use ($mode, $body, $during): Response {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('/api/v1/client/fixture-client/quotes', $path);
            $this->assertSame('dynamic_payout_reference_quote', $operation);
            $this->assertSame('at_conversion_time', $payload['executionType']);
            $this->assertSame($this->owner->id, $user->id);
            $field = $mode === 'SOURCE_AMOUNT' ? 'sourceAmount' : 'destinationAmount';
            $this->assertSame($mode === 'SOURCE_AMOUNT' ? 980 : 784, $payload[$field]);
            $beforeDispatch(new Request(new PsrRequest('POST', 'https://gatewaysandbox.nium.com'.$path,
                ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR))));
            if ($during !== null) {
                $during();
            }

            return $this->response($body ?? '{"id":"real-shaped-quote","quoteType":"payout","quoteIntent":"EXECUTABLE",'
                .'"customerHashId":"fixture-customer","sourceCurrencyCode":"USD","destinationCurrencyCode":"GBP",'
                .'"conversionSchedule":"immediate","executionType":"at_conversion_time","lockPeriod":"5_mins",'
                .'"sourceAmount":980,"destinationAmount":784,"netExchangeRate":0.800000000000000001,'
                .'"expiryTime":"2030-01-01T01:00:00Z"}');
        });
    }

    private function input(string $mode = 'SOURCE_AMOUNT'): array
    {
        return ['beneficiary_id' => $this->beneficiary->id, 'destination_currency' => 'GBP',
            'amount_mode' => $mode, 'entered_amount' => $mode === 'SOURCE_AMOUNT' ? '1000' : '784'];
    }

    private function noAdmission(): void
    {
        $this->assertSame(0, FxQuote::query()->count());
        $this->assertSame(0, NiumDynamicIntent::query()->count());
        $this->assertSame(0, NiumDynamicReservation::query()->count());
        $this->assertSame(0, NiumDynamicPayoutOutbox::query()->count());
        $this->assertSame('0.00000000', $this->wallet->fresh()->reserved_balance);
    }

    private function preparationReasons(): array
    {
        return ['dynamic_registered_account_payload_changed', 'dynamic_registered_account_customer_scope_unavailable'];
    }

    public static function modes(): array { return [['SOURCE_AMOUNT'], ['DESTINATION_AMOUNT']]; }

    private function fullRegistration(): void
    {
        $this->beneficiary->update(['raw_data' => ['nium' => ['payoutMethod' => 'LOCAL']]]);
        $request = json_encode(app(NiumBeneficiaryService::class)->receivingAccountBinding($this->beneficiary), JSON_THROW_ON_ERROR);
        $response = json_encode(['beneficiaryHashId' => $this->beneficiary->external_beneficiary_id,
            'customerHashId' => $this->account->external_customer_id], JSON_THROW_ON_ERROR);
        $this->exchange->update(['raw_request_body' => $request, 'raw_response_body' => $response,
            'request_body_sha256' => hash('sha256', $request), 'response_body_sha256' => hash('sha256', $response)]);
    }

    private function executionEvidence(?\Closure $corridors = null): void
    {
        $this->nium->shouldReceive('get')->withArgs(static fn ($path, $query = [], $user = null, $operation = null): bool =>
            $operation === 'dynamic_customer_corridors')->andReturnUsing(function ($path, $query, $user) use ($corridors): Response {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertSame('/api/v1/client/fixture-client/customer/fixture-customer/supportedCorridors', $path);
                $this->assertSame($this->owner->id, $user->id);
                return $this->response($this->corridorWithdrawn ? '{"data":[]}' : ($corridors === null
                    ? '{"data":[{"client_legal_entity":["LEGAL_ENTITY_HK"],"destination_country":["GB"],"destination_currency":"GBP","payout_method":"LOCAL","routing_code_type":"SORT CODE","ignoreLookup":false}]}'
                    : $corridors()));
            });
        $this->nium->shouldReceive('get')->withArgs(static fn ($path, $query = [], $user = null, $operation = null): bool =>
            $operation === 'dynamic_payout_schema')->andReturnUsing(function ($path, $query): Response {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertSame('/api/v1/client/fixture-client/customer/fixture-customer/currency/GBP/payout/schema', $path);
                $this->assertSame(['destinationCountry' => 'GB', 'payoutMethod' => 'LOCAL'], $query);
                return $this->response('{"$schema":"http://json-schema.org/draft-07/schema#","type":"object","properties":{"payout.destinationAmount":{"type":"number","maximumDecimals":"2"}}}');
            });
    }

    private function readyReview(string $mode = 'SOURCE_AMOUNT'): array
    {
        $this->fullRegistration();
        $this->catalogue();
        $this->executionEvidence();
        $this->providerQuote($mode);
        $id = $this->postJson($this->path('/quotes'), $this->input($mode))->assertCreated()->json('dynamic_quote_id');
        $response = $this->postJson($this->path('/reviews'), ['beneficiary_id' => $this->beneficiary->id, 'dynamic_quote_id' => $id,
            'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']]);
        $this->assertSame([], $response->json('reason_codes'), 'Review refusal: '.$response->getContent());
        return $response->assertOk()->assertJsonPath('status', 'READY')->assertJsonPath('total_usd_debit', '1000')
            ->assertJsonPath('principal.amount', '980')->assertJsonPath('included_fee.amount', '20')->json();
    }

    private function submitReady(array $review, string $reference = 'production-evidence-flow'): array
    {
        return ['provider_account_id' => $this->account->id, 'beneficiary_id' => $this->beneficiary->id,
            'balance_id' => $this->wallet->id, 'client_reference' => $reference, 'accepted_fingerprint' => $review['accepted_fingerprint']];
    }

    #[DataProvider('modes')]
    public function test_production_evidence_assembly_reviews_submits_and_dispatches_both_modes_once_without_a_test_authority(string $mode): void
    {
        // Only the HTTP boundary is mocked. The container retains the production evidence source.
        $review = $this->readyReview($mode);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $review['accepted_fingerprint']);
        $this->assertSame(0, LedgerEntry::query()->count());
        $request = $this->submitReady($review);
        $id = $this->postJson($this->path(''), $request)->assertCreated()->json('id');
        $this->postJson($this->path(''), $request)->assertOk()->assertJsonPath('id', $id);
        $this->postJson($this->path(''), $this->submitReady($review, 'different-reference'))->assertStatus(409)
            ->assertJsonPath('reason_codes', ['dynamic_quote_already_consumed']);
        $this->assertSame(1, NiumDynamicIntent::query()->count());
        $this->assertSame(1, NiumDynamicReservation::query()->count());
        $this->assertSame('1000.00000000', $this->wallet->fresh()->reserved_balance);
        $wire = new DynamicPayoutTransportStub;
        $this->app->instance(DynamicPayoutTransport::class, $wire);
        $outbox = NiumDynamicPayoutOutbox::query()->sole();
        // Force fresh observations for the scheduler; no network is allowed under locks.
        $this->travelTo(now()->addSeconds(31));
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        $this->assertSame(1, $wire->submissions);
        $this->assertSame(1, $outbox->fresh()->attempts);
        $record = app(NiumDynamicReviewService::class)->load($review['accepted_fingerprint']);
        $this->assertSame($record['evidence']['instruction_validation']['request_json'], $wire->bodies[0]);
        $this->assertSame($record['operation_key'], $outbox->fresh()->operation_key);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame('1000.00000000', NiumDynamicIntent::query()->sole()->total_debit);
    }

    public function test_withdrawn_customer_corridor_is_refused_before_dispatch_without_releasing_or_resubmitting(): void
    {
        $review = $this->readyReview();
        $this->postJson($this->path(''), $this->submitReady($review))->assertCreated();
        $this->corridorWithdrawn = true;
        $this->travelTo(now()->addSeconds(31));
        $wire = new DynamicPayoutTransportStub;
        $this->app->instance(DynamicPayoutTransport::class, $wire);
        $outbox = NiumDynamicPayoutOutbox::query()->sole();
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        $this->assertSame(0, $wire->submissions);
        $this->assertSame(0, $outbox->fresh()->attempts);
        $this->assertSame('exception', $outbox->fresh()->state);
        $this->assertSame('held', NiumDynamicReservation::query()->sole()->state);
    }

    public function test_ready_review_rechecks_balance_and_receiving_account_before_new_admission(): void
    {
        $review = $this->readyReview();
        $this->wallet->update(['available_balance' => '999']);
        $this->postJson($this->path(''), $this->submitReady($review))->assertStatus(409)
            ->assertJsonPath('reason_codes', ['dynamic_insufficient_available_funds']);
        $this->wallet->update(['available_balance' => '2000']);
        $this->beneficiary->update(['account_number' => 'CHANGED']);
        $this->postJson($this->path(''), $this->submitReady($review))->assertStatus(409)
            ->assertJsonPath('reason_codes', ['dynamic_quote_binding_changed']);
        $this->noAdmission();
    }

    public static function changedWalletObservations(): array
    {
        return [
            'different scope' => ['client', 'other-client'],
            'different funds' => ['available', '1999.00000000'],
            // Loose PHP equality would accept true in place of a nonempty string.
            'different scalar type' => ['version', true],
        ];
    }

    #[DataProvider('changedWalletObservations')]
    public function test_wallet_observation_values_and_types_remain_bound_after_jsonb_round_trip(string $field, mixed $value): void
    {
        $review = $this->readyReview();
        $raw = $this->wallet->fresh()->raw_data;
        $raw['dynamic_authenticated_availability'][$field] = $value;
        $this->wallet->update(['raw_data' => $raw]);
        $this->postJson($this->path(''), $this->submitReady($review))->assertStatus(409)
            ->assertJsonPath('reason_codes', ['dynamic_review_binding_changed']);
        $this->noAdmission();
    }

    public function test_withdrawn_kyc_blocks_automatic_dispatch_even_with_fresh_provider_observations(): void
    {
        $review = $this->readyReview();
        $this->postJson($this->path(''), $this->submitReady($review))->assertCreated();
        $this->owner->kycProfile()->update(['status' => 'rejected']);
        $wire = new DynamicPayoutTransportStub;
        $this->app->instance(DynamicPayoutTransport::class, $wire);
        $outbox = NiumDynamicPayoutOutbox::query()->sole();
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        $this->assertSame(0, $wire->submissions);
        $this->assertSame(0, $outbox->fresh()->attempts);
        $this->assertSame('dynamic_customer_account_beneficiary_or_wallet_ineligible', $outbox->fresh()->exception_code);
        $this->assertSame('held', NiumDynamicReservation::query()->sole()->state);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'debit')->count());
    }

    public function test_evicted_review_observations_cannot_authorize_a_reservation(): void
    {
        $review = $this->readyReview();
        Cache::store('database')->forget('nium:dynamic:review:v1:'.$review['accepted_fingerprint'].':observations');
        $this->postJson($this->path(''), $this->submitReady($review))->assertStatus(409)
            ->assertJsonPath('reason_codes', ['dynamic_confirmation_evidence_expired']);
        $this->noAdmission();
    }

    public function test_authenticated_but_malformed_review_storage_is_a_binding_refusal(): void
    {
        $review = $this->readyReview();
        Cache::store('database')->put('nium:dynamic:review:v1:'.$review['accepted_fingerprint'],
            Crypt::encryptString('null'), now()->addHour());
        $this->postJson($this->path(''), $this->submitReady($review))->assertStatus(409)
            ->assertJsonPath('reason_codes', ['dynamic_review_binding_changed']);
        $this->noAdmission();
    }

    public function test_ready_review_has_bounded_observation_freshness_and_quote_expiry(): void
    {
        $review = $this->readyReview();
        $this->travelTo(now()->addSeconds(31));
        $this->postJson($this->path(''), $this->submitReady($review))->assertStatus(409)
            ->assertJsonPath('reason_codes', ['dynamic_confirmation_evidence_expired']);
        $this->travelTo(now()->addHours(2));
        $this->postJson($this->path(''), $this->submitReady($review))->assertStatus(409)
            ->assertJsonPath('reason_codes', ['dynamic_quote_expired']);
        $this->noAdmission();
    }

    public function test_changed_server_review_instruction_cannot_reuse_the_original_accepted_fingerprint(): void
    {
        $review = $this->readyReview();
        $record = app(NiumDynamicReviewService::class)->load($review['accepted_fingerprint']);
        $record['instruction']['comment'] = 'Changed after Review';
        Cache::store('database')->put('nium:dynamic:review:v1:'.$review['accepted_fingerprint'],
            Crypt::encryptString(json_encode($record, JSON_THROW_ON_ERROR)), now()->addHour());
        $this->postJson($this->path(''), $this->submitReady($review))->assertStatus(409)
            ->assertJsonPath('reason_codes', ['dynamic_review_binding_changed']);
        $this->noAdmission();
    }

    public static function earlyCallbacks(): array { return [[false], [true]]; }

    #[DataProvider('earlyCallbacks')]
    public function test_production_review_timeout_recovery_keeps_one_operation_even_with_an_early_callback(bool $early): void
    {
        $review = $this->readyReview();
        $request = $this->submitReady($review);
        $id = $this->postJson($this->path(''), $request)->assertCreated()->json('id');
        $wire = new DynamicPayoutTransportStub;
        $wire->afterWire = function () use ($early): void {
            if ($early) {
                app(DynamicPayoutWebhook::class)->observe(IntegrationProvider::query()->findOrFail($this->account->provider_id), [
                    'template' => 'REMIT_TRANSACTION_PAID_WEBHOOK', 'systemReferenceNumber' => 'RT-fixture-dynamic',
                    'externalId' => NiumDynamicPayoutOutbox::query()->sole()->operation_key,
                    'clientHashId' => 'fixture-client', 'customerHashId' => 'fixture-customer', 'walletHashId' => 'fixture-wallet',
                ], []);
            }
            throw new RuntimeException('Unknown outcome after wire attempt');
        };
        $this->app->instance(DynamicPayoutTransport::class, $wire);
        $outbox = NiumDynamicPayoutOutbox::query()->sole();
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        $this->travelTo(now()->addHours(2));
        $this->postJson($this->path(''), $request)->assertOk()->assertJsonPath('id', $id);
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        $this->assertSame(1, $wire->submissions);
        $this->assertSame('ambiguous', $outbox->fresh()->state);
        $this->assertSame($early ? 'RT-fixture-dynamic' : null, $outbox->fresh()->provider_reference);
        $this->assertSame(1, NiumDynamicIntent::query()->count());
        $this->assertSame(1, NiumDynamicReservation::query()->count());
        $this->assertSame('1000.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'debit')->count());
    }

    #[DataProvider('earlyCallbacks')]
    public function test_missing_or_undocumented_customer_entitlement_refuses_only_this_review(bool $missing): void
    {
        $this->fullRegistration(); $this->catalogue();
        $this->executionEvidence(fn () => $missing ? '{"data":[]}' : '{"content":[]}');
        $this->providerQuote();
        $id = $this->postJson($this->path('/quotes'), $this->input())->assertCreated()->json('dynamic_quote_id');
        $this->postJson($this->path('/reviews'), ['beneficiary_id' => $this->beneficiary->id, 'dynamic_quote_id' => $id,
            'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']])
            ->assertStatus(503)->assertJsonMissingPath('accepted_fingerprint')
            ->assertJsonPath('reason_codes', [$missing ? 'dynamic_customer_corridor_not_permitted' : 'dynamic_customer_corridor_response_contract_unavailable']);
        $this->noAdmission();
    }

    #[DataProvider('modes')]
    public function test_real_quote_is_exact_persisted_and_cannot_authorize_review_or_submission(string $mode): void
    {
        DB::statement("SET TIME ZONE 'Asia/Bangkok'");
        $this->catalogue();
        $this->providerQuote($mode);
        $response = $this->postJson($this->path('/quotes'), $this->input($mode))->assertCreated()
            ->assertJsonPath('status', 'QUOTE_REFERENCE_ONLY')->assertJsonPath('quoted_principal.amount', '980')
            ->assertJsonPath('planned_included_fee.amount', '20')->assertJsonPath('planned_total_usd_debit', '1000')
            ->assertJsonPath('nium_quoted_fx_rate', '0.800000000000000001')
            ->assertJsonPath('quote_expires_at', '2030-01-01T01:00:00.000000Z')->assertJsonPath('review', 'DENIED');
        $this->assertArrayNotHasKey('accepted_fingerprint', $response->json());
        $stored = NiumDynamicPayoutQuote::query()->sole();
        $this->assertSame($mode === 'SOURCE_AMOUNT' ? '980' : '784',
            ExactProviderJson::decode($stored->request_json)[$mode === 'SOURCE_AMOUNT' ? 'sourceAmount' : 'destinationAmount']);
        $this->assertSame($response->json('dynamic_quote_id'), $stored->id);
        $this->assertSame('0.800000000000000001', ExactProviderJson::decode($stored->response_json)['netExchangeRate']);
        $review = ['beneficiary_id' => $this->beneficiary->id, 'dynamic_quote_id' => $stored->id,
            'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']];
        $this->postJson($this->path('/reviews'), $review)->assertStatus(503)
            ->assertJsonPath('reason_codes', $this->preparationReasons())
            ->assertJsonPath('review.total_usd_debit', '1000')
            ->assertJsonPath('review.principal.amount', '980')->assertJsonPath('review.included_fee.amount', '20')
            ->assertJsonMissingPath('review.accepted_fingerprint')
            ->assertJsonMissingPath('errors');
        $this->postJson($this->path(''), ['provider_account_id' => $this->account->id,
            'beneficiary_id' => $this->beneficiary->id, 'balance_id' => $this->wallet->id,
            'client_reference' => 'reference-quote-is-not-consent',
            'accepted_fingerprint' => hash('sha256', $stored->response_json)])->assertStatus(503)
            ->assertJsonPath('reason_codes', OriginDynamicPayoutEvidenceSource::MISSING_DEPENDENCIES);
        $this->beneficiary->update(['account_number' => 'CHANGED']);
        $this->postJson($this->path('/reviews'), $review)->assertStatus(409)
            ->assertJsonPath('reason_codes', ['dynamic_quote_binding_changed']);
        $this->travelTo(now()->addHours(2));
        $this->postJson($this->path('/reviews'), $review)->assertStatus(409)
            ->assertJsonPath('reason_codes', ['dynamic_quote_expired']);
        $this->noAdmission();
    }

    public function test_options_distinguish_quote_capability_from_execution(): void
    {
        $this->catalogue();
        $this->nium->shouldNotReceive('post');
        $this->getJson($this->path('/options?beneficiary_id='.$this->beneficiary->id))->assertOk()
            ->assertJsonPath('status', 'QUOTE_REFERENCE_ONLY')->assertJsonPath('destination_currencies', [])
            ->assertJsonPath('reason_codes', [])
            ->assertJsonPath('quote_availability.status', 'QUOTE_REFERENCE_ONLY')
            ->assertJsonPath('quote_availability.destination_currencies', ['GBP']);
        $this->noAdmission();
    }

    public function test_server_owned_review_preparation_is_immutable_and_never_becomes_executable_by_accepting_its_digest(): void
    {
        $this->catalogue();
        $this->providerQuote();
        $id = $this->postJson($this->path('/quotes'), $this->input())->assertCreated()->json('dynamic_quote_id');
        $input = ['beneficiary_id' => $this->beneficiary->id, 'dynamic_quote_id' => $id,
            'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Original invoice']];
        $first = $this->postJson($this->path('/reviews'), $input)->assertStatus(503)
            ->assertJsonMissingPath('review.accepted_fingerprint')->json('review');
        $this->postJson($this->path('/reviews'), [...$input, 'instruction' => [...$input['instruction'], 'comment' => 'Different invoice']])
            ->assertStatus(503);
        $record = app(NiumDynamicReviewService::class)->load($first['review_id']);
        $this->assertSame('Original invoice', $record['instruction']['comment']);
        $this->assertSame('1000', $record['total_usd_debit']);
        $this->assertSame('20', $record['included_fee']['amount']);
        $this->assertSame('fixture-customer', $record['evidence']['instruction_validation']['scope']['customer']);
        $this->assertSame('2000.00000000', $record['evidence']['wallet_availability']['available']);
        $this->postJson($this->path(''), ['provider_account_id' => $this->account->id,
            'beneficiary_id' => $this->beneficiary->id, 'balance_id' => $this->wallet->id,
            'client_reference' => 'digest-is-not-executable-consent', 'accepted_fingerprint' => $first['review_id']])
            ->assertStatus(503)->assertJsonPath('reason_codes', ['dynamic_registered_account_payload_changed']);
        $this->noAdmission();
    }

    public function test_corrupted_review_storage_fails_binding_validation_without_reserving_funds(): void
    {
        $this->catalogue();
        $this->providerQuote();
        $id = $this->postJson($this->path('/quotes'), $this->input())->assertCreated()->json('dynamic_quote_id');
        $prepared = $this->postJson($this->path('/reviews'), ['beneficiary_id' => $this->beneficiary->id,
            'dynamic_quote_id' => $id,
            'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']])
            ->assertStatus(503)->json('review');
        Cache::store('database')->put('nium:dynamic:review:v1:'.$prepared['review_id'], 'corrupted-ciphertext', now()->addHour());
        $this->postJson($this->path(''), ['provider_account_id' => $this->account->id,
            'beneficiary_id' => $this->beneficiary->id, 'balance_id' => $this->wallet->id,
            'client_reference' => 'corrupt-preparation', 'accepted_fingerprint' => $prepared['review_id']])
            ->assertStatus(409)->assertJsonPath('reason_codes', ['dynamic_review_binding_changed']);
        $this->noAdmission();
    }

    public function test_missing_or_revoked_registration_prevents_provider_quote(): void
    {
        $this->exchange->update(['response_received' => false]);
        $this->nium->shouldNotReceive('post');
        $this->nium->shouldNotReceive('get');
        $this->postJson($this->path('/quotes'), $this->input())->assertStatus(503)
            ->assertJsonPath('reason_codes', ['dynamic_quote_registration_evidence_unavailable']);
        $this->noAdmission();
    }

    public function test_withdrawn_original_registration_evidence_invalidates_stored_reference_review(): void
    {
        $this->catalogue();
        $this->providerQuote();
        $quote = $this->postJson($this->path('/quotes'), $this->input())->assertCreated()->json('dynamic_quote_id');
        $this->exchange->update(['response_received' => false]);

        $this->postJson($this->path('/reviews'), [
            'beneficiary_id' => $this->beneficiary->id, 'dynamic_quote_id' => $quote,
            'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice'],
        ])->assertStatus(503)->assertJsonPath('reason_codes', ['dynamic_quote_registration_evidence_unavailable'])
            ->assertJsonMissingPath('accepted_fingerprint');
        $this->noAdmission();
    }

    #[DataProvider('modes')]
    public function test_consumed_reference_cannot_be_reviewed_or_reacquired_even_after_cancellation(string $mode): void
    {
        $this->catalogue();
        $this->providerQuote($mode);
        $quote = $this->postJson($this->path('/quotes'), $this->input($mode))->assertCreated()->json('dynamic_quote_id');

        // Only the existing test stub supplies financial authority. The HTTP review
        // source remains the production denial source; this is not Nium fee proof.
        $this->source->review = $this->usdReview(PayoutAmountMode::from($mode),
            ['response' => ['id' => 'real-shaped-quote']]);
        $intent = $this->confirmDynamic();
        $review = ['beneficiary_id' => $this->beneficiary->id, 'dynamic_quote_id' => $quote,
            'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']];
        $this->postJson($this->path('/reviews'), $review)->assertStatus(409)
            ->assertJsonPath('reason_codes', ['dynamic_quote_already_consumed'])
            ->assertJsonMissingPath('accepted_fingerprint');

        $this->confirmation->cancel($this->owner, $intent->id);
        $this->postJson($this->path('/reviews'), $review)->assertStatus(409)
            ->assertJsonPath('reason_codes', ['dynamic_quote_already_consumed']);

        // A provider returning the same ID again cannot create a reusable reference.
        // The catalogue is cached, so only quote transport is expected again.
        $this->providerQuote($mode);
        $this->postJson($this->path('/quotes'), $this->input($mode))->assertStatus(409)
            ->assertJsonPath('reason_codes', ['dynamic_quote_already_consumed']);
        $this->assertSame(1, NiumDynamicPayoutQuote::query()->count());
        $this->assertSame(1, NiumDynamicIntent::query()->count());
        $this->assertSame('released', NiumDynamicReservation::query()->sole()->state);
        $this->assertSame('cancelled', NiumDynamicPayoutOutbox::query()->sole()->state);
        $this->assertSame(0, NiumDynamicPayoutOutbox::query()->sole()->attempts);
        $this->assertSame('0.00000000', $this->wallet->fresh()->reserved_balance);
    }

    public function test_consuming_another_quote_does_not_mark_this_reference_consumed(): void
    {
        $this->source->review = $this->usdReview(); // Different provider quote ID.
        $this->confirmDynamic();
        $this->catalogue();
        $this->providerQuote();
        $quote = $this->postJson($this->path('/quotes'), $this->input())->assertCreated()->json('dynamic_quote_id');
        $this->postJson($this->path('/reviews'), [
            'beneficiary_id' => $this->beneficiary->id, 'dynamic_quote_id' => $quote,
            'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice'],
        ])->assertStatus(503)->assertJsonPath('reason_codes', $this->preparationReasons())
            ->assertJsonMissingPath('accepted_fingerprint');
        $this->assertSame(1, NiumDynamicPayoutQuote::query()->count());
        $this->assertSame(1, NiumDynamicIntent::query()->count());
        $this->assertSame('held', NiumDynamicPayoutOutbox::query()->sole()->state);
        $this->assertSame(0, NiumDynamicPayoutOutbox::query()->sole()->attempts);
        $this->assertSame('1000.00000000', $this->wallet->fresh()->reserved_balance);
    }

    public static function referenceMutations(): array
    {
        return [
            'provider quote identity' => [['quote_id' => 'rewritten-quote']],
            'original request' => [['request_json' => '{}']],
            'original response' => [['response_json' => '{}']],
            'binding' => [['binding' => '{}']],
            'expiry' => [['expires_at' => '2030-01-02T01:00:00Z']],
            'delete' => [null],
        ];
    }

    #[DataProvider('referenceMutations')]
    public function test_database_rejects_reference_quote_rewriting_outside_model_hooks(?array $changes): void
    {
        $this->catalogue();
        $this->providerQuote();
        $id = $this->postJson($this->path('/quotes'), $this->input())->assertCreated()->json('dynamic_quote_id');
        $before = NiumDynamicPayoutQuote::query()->findOrFail($id)->getRawOriginal();
        try {
            DB::transaction(function () use ($id, $changes): void {
                $query = DB::table('nium_dynamic_payout_quotes')->where('id', $id);
                $changes === null ? $query->delete() : $query->update($changes);
            });
            $this->fail('Original provider reference must be immutable in the database.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Dynamic reference quotes are immutable', $exception->getMessage());
        }
        $this->assertSame($before, NiumDynamicPayoutQuote::query()->findOrFail($id)->getRawOriginal());
        $this->noAdmission();
    }

    public function test_configured_corridor_cannot_override_missing_provider_support(): void
    {
        $this->catalogue(false);
        $this->nium->shouldNotReceive('post');
        $this->postJson($this->path('/quotes'), $this->input())->assertStatus(503)
            ->assertJsonPath('reason_codes', ['dynamic_corridor_unsupported']);
        $this->noAdmission();
    }

    public function test_provider_contract_missing_echoes_is_unavailable_without_persisting_quote(): void
    {
        $this->catalogue();
        $this->providerQuote(body: '{"id":"quote","sourceAmount":980,"destinationAmount":784,"netExchangeRate":0.8}');
        $this->postJson($this->path('/quotes'), $this->input())->assertStatus(503)
            ->assertJsonPath('reason_codes', ['dynamic_provider_quote_contract_unavailable']);
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
    }

    public function test_balance_including_existing_holds_is_checked_after_provider_response(): void
    {
        $this->wallet->update(['reserved_balance' => '1500']);
        $this->catalogue();
        $this->providerQuote();
        $this->postJson($this->path('/quotes'), $this->input())->assertStatus(409)
            ->assertJsonPath('reason_codes', ['dynamic_insufficient_available_funds']);
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->assertSame('1500.00000000', $this->wallet->fresh()->reserved_balance);
    }

    public function test_destination_quote_requires_funds_for_principal_plus_the_included_fee(): void
    {
        $this->wallet->update(['available_balance' => '999', 'raw_data' => ['provider_available_balance' => '999']]);
        $this->catalogue();
        $this->providerQuote('DESTINATION_AMOUNT');
        $this->postJson($this->path('/quotes'), $this->input('DESTINATION_AMOUNT'))->assertStatus(409)
            ->assertJsonPath('reason_codes', ['dynamic_insufficient_available_funds']);
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
    }

    public function test_account_change_during_quote_io_cannot_be_persisted(): void
    {
        $this->catalogue();
        $this->providerQuote(during: fn () => $this->account->update(['external_customer_id' => 'changed-customer']));
        $this->postJson($this->path('/quotes'), $this->input())->assertStatus(409)
            ->assertJsonPath('reason_codes', ['dynamic_quote_binding_changed']);
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
    }

    public function test_quote_network_preflight_is_refused_inside_a_transaction(): void
    {
        $this->nium->shouldNotReceive('get');
        $this->nium->shouldNotReceive('post');
        $this->expectExceptionMessage('dynamic_quote_requires_network_outside_transaction');
        DB::transaction(fn () => app(NiumDynamicPayoutQuoteService::class)
            ->availability($this->owner, $this->beneficiary, $this->wallet));
    }

    public function test_foreign_customer_cannot_review_a_persisted_reference_quote(): void
    {
        $this->catalogue();
        $this->providerQuote();
        $quote = $this->postJson($this->path('/quotes'), $this->input())->assertCreated()->json('dynamic_quote_id');
        $other = User::factory()->create();
        $recipient = $this->beneficiary->replicate();
        $recipient->user_id = $other->id;
        $recipient->external_beneficiary_id = 'other-registered-payee';
        $recipient->save();
        ApiToken::query()->create(['user_id' => $other->id, 'name' => 'offline-other-reference',
            'token_hash' => hash('sha256', 'offline-other-reference'), 'expires_at' => now()->addDay()]);
        $this->withToken('offline-other-reference')->postJson(
            '/api/user/users/'.$other->id.'/nium/dynamic-payouts/reviews',
            ['beneficiary_id' => $recipient->id, 'dynamic_quote_id' => $quote,
                'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']])
            ->assertNotFound()->assertJsonMissingPath('errors')->assertJsonMissingPath('dynamic_quote_id')
            ->assertJsonMissingPath('provider_quote_id')->assertJsonMissingPath('binding');
        $this->noAdmission();
    }

    public static function legacyLanes(): array
    {
        return [['USD', 'SWIFT'], ['SGD', 'LOCAL'], ['GBP', 'SWIFT']];
    }

    #[DataProvider('legacyLanes')]
    public function test_enabled_reference_quoting_cannot_enroll_hk_sgd_or_other_nonlocal_lanes(string $currency, string $rail): void
    {
        $this->beneficiary->update(['currency' => $currency, 'payout_method' => $rail]);
        $this->nium->shouldNotReceive('get');
        $this->nium->shouldNotReceive('post');
        $this->postJson($this->path('/quotes'), [...$this->input(), 'destination_currency' => $currency])->assertStatus(503)
            ->assertJsonPath('reason_codes', ['dynamic_corridor_unsupported']);
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
    }
}
