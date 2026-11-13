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
use App\Services\Nium\DynamicConfirmation\DynamicPayoutReconciler;
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
    private string $validationResponse = '{"valid":true,"issues":[]}';
    private ?\Closure $duringValidation = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDynamicFixtures('2000');
        $this->wallet->update(['currency' => 'USD']);
        config(['services.nium.base_url' => 'https://gatewaysandbox.nium.com',
            'services.nium.auth.mode' => 'header', 'services.nium.auth.header_name' => 'x-api-key',
            'services.nium.auth.header_value' => 'synthetic-wallet-test-key',
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
        $this->nium->shouldReceive('clientId')->andReturnUsing(static fn (): string => (string) config('services.nium.client_id'));
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
                if ($this->duringValidation !== null) ($this->duringValidation)();
                return $this->response($this->validationResponse);
            });
        $this->nium->shouldReceive('get')->withArgs(static fn ($path, $query = [], $user = null, $operation = null): bool =>
            $operation === 'dynamic_wallet_availability')->andReturnUsing(function ($path, $query, $user, $operation,
                $reference, $beforeDispatch): Response {
                $this->assertSame(0, DB::transactionLevel());
                $beforeDispatch(new Request(new PsrRequest('GET', 'https://gatewaysandbox.nium.com'.$path,
                    ['x-api-key' => 'synthetic-wallet-test-key'])));
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

    private function customerListing(bool $listed = true): void
    {
        // A configured client catalogue never substitutes for this authenticated customer GET.
        config(['services.nium.supported_corridors' => [['destinationCountry' => 'GB',
            'destinationCurrency' => 'GBP', 'payoutMethod' => 'LOCAL']]]);
        $this->nium->shouldReceive('get')->withArgs(static fn ($path, $query, $user, $operation): bool =>
            $operation === 'dynamic_customer_corridor_listing')->andReturnUsing(function ($path, $query, $user,
                $operation, $reference, $beforeDispatch) use ($listed): Response {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertSame('/api/v1/client/fixture-client/customer/fixture-customer/supportedCorridors', $path);
                $this->assertSame([], $query);
                $this->assertSame($this->owner->id, $user->id);
                $beforeDispatch(new Request(new PsrRequest('GET', rtrim((string) config('services.nium.base_url'), '/').$path)));
                return $this->response(json_encode(['data' => $listed ? [[
                    'destination_country' => [$this->beneficiary->country_code],
                    'destination_currency' => $this->beneficiary->currency,
                    'payout_method' => $this->beneficiary->payout_method, 'routing_code_type' => 'synthetic-route',
                ]] : []], JSON_THROW_ON_ERROR));
            });
    }

    private function providerQuote(string $mode = 'SOURCE_AMOUNT', ?string $body = null, ?\Closure $during = null,
        ?\Closure $outgoing = null): void
    {
        $origin = rtrim((string) config('services.nium.base_url'), '/');
        $this->nium->shouldReceive('post')->once()->withArgs(static fn ($path, $payload, $user, $transferId, $operation): bool =>
            $operation === 'dynamic_payout_reference_quote')->andReturnUsing(function ($path, $payload, $user,
            $relatedTransferId, $operation, $externalReference, $beforeDispatch) use ($mode, $body, $during, $outgoing, $origin): Response {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('/api/v1/client/fixture-client/quotes', $path);
            $this->assertSame('dynamic_payout_reference_quote', $operation);
            $this->assertSame('at_conversion_time', $payload['executionType']);
            $this->assertSame('immediate', $payload['conversionSchedule']);
            $this->assertSame($this->owner->id, $user->id);
            $field = $mode === 'SOURCE_AMOUNT' ? 'sourceAmount' : 'destinationAmount';
            $this->assertSame($mode === 'SOURCE_AMOUNT' ? 980 : 784, $payload[$field]);
            $request = $outgoing === null
                ? new Request(new PsrRequest('POST', $origin.$path,
                    ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR)))
                : $outgoing($origin.$path, $payload);
            if ($request !== null) {
                $beforeDispatch($request);
            }
            if ($during !== null) {
                $during();
            }

            return $this->response($body ?? '{"id":"real-shaped-quote","quoteType":"payout","quoteIntent":"EXECUTABLE",'
                .'"customerHashId":"fixture-customer","sourceCurrencyCode":"USD","destinationCurrencyCode":"'.$this->beneficiary->currency.'",'
                .'"conversionSchedule":"immediate","executionType":"at_conversion_time","lockPeriod":"5_mins",'
                .'"sourceAmount":980,"destinationAmount":784,"netExchangeRate":0.800000000000000001,'
                .'"expiryTime":"2030-01-01T01:00:00Z"}');
        });
    }

    private function input(string $mode = 'SOURCE_AMOUNT'): array
    {
        return ['beneficiary_id' => $this->beneficiary->id, 'destination_currency' => $this->beneficiary->currency,
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
        $this->beneficiary->update(['raw_data' => ['nium' => ['payoutMethod' => $this->beneficiary->payout_method]]]);
        $this->exchange->apiRequestLog->update(['request_body' => ['payout_method' => $this->beneficiary->payout_method]]);
        $request = json_encode(app(NiumBeneficiaryService::class)->receivingAccountBinding($this->beneficiary), JSON_THROW_ON_ERROR);
        $response = json_encode(['beneficiaryHashId' => $this->beneficiary->external_beneficiary_id,
            'customerHashId' => $this->account->external_customer_id], JSON_THROW_ON_ERROR);
        $this->exchange->update(['raw_request_body' => $request, 'raw_response_body' => $response,
            'request_body_sha256' => hash('sha256', $request), 'response_body_sha256' => hash('sha256', $response)]);
    }

    private function executionEvidence(?\Closure $corridors = null, ?string $schemaBody = null, ?\Closure $schemaOutgoing = null): void
    {
        $this->nium->shouldReceive('get')->withArgs(static fn ($path, $query = [], $user = null, $operation = null): bool =>
            $operation === 'dynamic_customer_corridors')->andReturnUsing(function ($path, $query, $user, $operation,
                $reference, $beforeDispatch) use ($corridors): Response {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertSame('/api/v1/client/fixture-client/customer/fixture-customer/supportedCorridors', $path);
                $this->assertSame($this->owner->id, $user->id);
                $beforeDispatch(new Request(new PsrRequest('GET', rtrim((string) config('services.nium.base_url'), '/').$path)));
                return $this->response($this->corridorWithdrawn ? '{"data":[]}' : ($corridors === null
                    ? json_encode(['data' => [['client_legal_entity' => ['LEGAL_ENTITY_HK'],
                        'destination_country' => [$this->beneficiary->country_code], 'destination_currency' => $this->beneficiary->currency,
                        'payout_method' => $this->beneficiary->payout_method, 'routing_code_type' => 'synthetic-route', 'ignoreLookup' => false]]], JSON_THROW_ON_ERROR)
                    : $corridors()));
            });
        $this->nium->shouldReceive('get')->withArgs(static fn ($path, $query = [], $user = null, $operation = null): bool =>
            $operation === 'dynamic_payout_schema')->andReturnUsing(function ($path, $query, $user, $operation,
                $reference, $beforeDispatch) use ($schemaBody, $schemaOutgoing): Response {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertSame('/api/v1/client/fixture-client/customer/fixture-customer/currency/'.$this->beneficiary->currency.'/payout/schema', $path);
                $this->assertSame(['destinationCountry' => $this->beneficiary->country_code, 'payoutMethod' => $this->beneficiary->payout_method], $query);
                $url = rtrim((string) config('services.nium.base_url'), '/').$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
                $beforeDispatch($schemaOutgoing === null ? new Request(new PsrRequest('GET', $url)) : $schemaOutgoing($url));
                return $this->response($schemaBody ?? '{"$schema":"http://json-schema.org/draft-07/schema#","type":"object",'
                    // Synthetic both-mode schema; the retained destination-required example is tested separately.
                    .'"required":["beneficiary.id"],"allOf":[{"if":{"required":["synthetic.flag"],"properties":{"synthetic.flag":{"const":true}}},'
                    .'"then":{"required":["synthetic.conditionalField"]}}],'
                    .'"properties":{"payout.sourceAmount":{"type":"number","maximumDecimals":"2"},'
                    .'"payout.destinationAmount":{"type":"number","maximumDecimals":"'.($this->beneficiary->currency === 'JPY' ? '0' : '2').'"}}}');
            });
    }

    private function readyReview(string $mode = 'SOURCE_AMOUNT'): array
    {
        // Real accepted customer authorization; only provider HTTP is synthetic.
        $this->fullRegistration();
        $this->customerListing();
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
    public function test_accepted_contract_includes_one_fee_without_changing_the_provider_rate(string $mode): void
    {
        $review = $this->readyReview($mode);
        $quote = app(NiumDynamicPayoutQuoteService::class)->project(NiumDynamicPayoutQuote::query()->sole());
        $this->assertSame('CONTRACTUAL_CUSTOMER_TERMS', $quote['pricing_status']);
        $this->assertSame('0.800000000000000001', $quote['nium_quoted_fx_rate']);
        $this->assertSame(['currency' => 'USD', 'amount' => '20'], $review['origin_service_fee']);
        $this->assertSame($quote['origin_service_fee'], $review['origin_service_fee']);
        $this->assertSame($quote['provider_quote_id'], $review['provider_quote_id']);
        $this->assertSame($quote['nium_quoted_fx_rate'], $review['nium_quoted_fx_rate']);
        $this->assertSame('20', $review['customer_pricing']['origin_service_fee_usd']);
        $this->assertSame('CUSTOMER_PRICING_AUTHORIZED_PROVIDER_SETTLEMENT_UNVERIFIED', $quote['financial_verification']);
        $this->assertArrayNotHasKey('provider_fee_usd', $quote);
        $this->assertSame('0.13', $review['customer_pricing']['margin_percent']);
        $this->assertSame('INCLUDED_IN_TOTAL_USD_DEBIT', $review['customer_pricing']['fee_treatment']);
        $this->assertSame('784', $review['destination_amount']['amount']);
        $this->assertSame($mode, $review['amount_mode']);
        $this->assertSame('LOCAL', $review['payout_rail']);
        $this->noAdmission(); // Review alone cannot reserve or dispatch.
    }

    public static function changedFinancialRecords(): array
    {
        return [
            'historical review version' => ['version', 'nium-dynamic-review-v2'],
            'missing pricing' => ['customer_pricing', null],
            'altered service fee' => ['origin_service_fee', ['currency' => 'USD', 'amount' => '1']],
            'altered included fee' => ['included_fee', ['currency' => 'USD', 'amount' => '21']],
            'altered debit' => ['total_usd_debit', '1001'],
            'altered recipient' => ['destination_amount', ['currency' => 'GBP', 'amount' => '785']],
            'altered rate' => ['nium_quoted_fx_rate', '0.81'],
            'altered provider quote identity' => ['provider_quote_id', 'different-provider-quote'],
        ];
    }

    #[DataProvider('changedFinancialRecords')]
    public function test_cached_ready_review_cannot_bypass_changed_financial_terms(string $field, mixed $value): void
    {
        $review = $this->readyReview();
        $record = app(NiumDynamicReviewService::class)->load($review['accepted_fingerprint']);
        $record[$field] = $value;
        // Keep the original consent snapshot: load succeeds, reconstruction must refuse.
        Cache::store('database')->put('nium:dynamic:review:v1:'.$review['accepted_fingerprint'],
            Crypt::encryptString(json_encode($record, JSON_THROW_ON_ERROR)), now()->addHour());
        $this->postJson($this->path(''), $this->submitReady($review))->assertStatus(503)
            ->assertJsonPath('reason_codes', ['dynamic_financial_terms_changed']);
        $this->noAdmission();
    }

    public function test_quote_without_current_pricing_fingerprint_cannot_be_reviewed_or_consumed(): void
    {
        $this->fullRegistration(); $this->customerListing(); $this->providerQuote();
        $id = $this->postJson($this->path('/quotes'), $this->input())->assertCreated()->json('dynamic_quote_id');
        $stored = NiumDynamicPayoutQuote::query()->findOrFail($id);
        $binding = $stored->binding;
        unset($binding['customer_pricing_sha256']);
        // Insert an old-version fixture; never UPDATE the immutable original quote.
        $historical = NiumDynamicPayoutQuote::query()->forceCreate([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $stored->user_id,
            'provider_id' => $stored->provider_id, 'provider_account_id' => $stored->provider_account_id,
            'beneficiary_id' => $stored->beneficiary_id, 'balance_id' => $stored->balance_id,
            'quote_id' => 'historical-quote', 'request_json' => $stored->request_json,
            'response_json' => str_replace('real-shaped-quote', 'historical-quote', $stored->response_json),
            'binding' => $binding, 'expires_at' => $stored->expires_at, 'created_at' => now(),
        ]);
        $this->postJson($this->path('/reviews'), ['beneficiary_id' => $this->beneficiary->id,
            'dynamic_quote_id' => $historical->id, 'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']])
            ->assertStatus(409)->assertJsonPath('reason_codes', ['dynamic_quote_binding_changed'])
            ->assertJsonMissingPath('accepted_fingerprint');
        $this->noAdmission();
    }

    public function test_uncontracted_country_cannot_inherit_eur_pricing_or_request_a_quote(): void
    {
        $this->beneficiary->update(['country_code' => 'FR', 'currency' => 'EUR']);
        $this->fullRegistration();
        $this->nium->shouldNotReceive('get');
        $this->nium->shouldNotReceive('post');
        $this->postJson($this->path('/quotes'), [...$this->input(), 'destination_currency' => 'EUR'])->assertStatus(503)
            ->assertJsonPath('reason_codes', ['dynamic_pricing_corridor_unsupported']);
        $this->noAdmission();
    }

    #[DataProvider('modes')]
    public function test_spend_quote_gate_disabled_refuses_without_any_http(string $mode): void
    {
        config(['services.nium.base_url' => 'https://api.spend.nium.com',
            'nium_dynamic_quotes.quote_contract_enabled' => false]);
        $this->nium->shouldNotReceive('get');
        $this->nium->shouldNotReceive('post');
        $this->getJson($this->path('/options?beneficiary_id='.$this->beneficiary->id))->assertOk()
            ->assertJsonPath('dynamic_available', false)->assertJsonPath('supported_amount_modes', [])
            ->assertJsonPath('reason_codes', ['dynamic_quote_contract_disabled']);
        $this->postJson($this->path('/quotes'), $this->input($mode))->assertStatus(503)
            ->assertJsonPath('reason_codes', ['dynamic_quote_contract_disabled']);
        $this->noAdmission();
    }

    /** Relocate synthetic registration evidence only; no provider evidence is implied. */
    private function useQuoteOrigin(string $origin = 'https://api.spend.nium.com'): void
    {
        config(['services.nium.base_url' => $origin]);
        $url = $origin.'/api/v2/client/[REDACTED]/customer/[REDACTED]/beneficiaries';
        $this->exchange->update(['request_url' => $url]);
        ApiRequestLog::query()->findOrFail($this->exchange->api_request_log_id)->update(['request_url' => $url]);
    }

    private function createReference(string $mode = 'SOURCE_AMOUNT'): NiumDynamicPayoutQuote
    {
        return app(NiumDynamicPayoutQuoteService::class)->create($this->owner, $this->beneficiary,
            $this->wallet, PayoutAmountMode::from($mode), $mode === 'SOURCE_AMOUNT' ? '1000' : '784');
    }

    private function assertQuoteFailure(string $reason, \Closure $action): void
    {
        try {
            $action();
            $this->fail('Expected Quote guard: '.$reason);
        } catch (RuntimeException $exception) {
            $this->assertSame($reason, $exception->getMessage());
        }
    }

    #[DataProvider('modes')]
    public function test_spend_reference_quote_keeps_exact_rate_and_production_identity_without_admission(string $mode): void
    {
        $this->useQuoteOrigin();
        $this->customerListing();
        $this->providerQuote($mode);
        $id = $this->postJson($this->path('/quotes'), $this->input($mode))->assertCreated()->json('dynamic_quote_id');
        $stored = NiumDynamicPayoutQuote::query()->findOrFail($id);
        $service = app(NiumDynamicPayoutQuoteService::class);
        $service->assertCurrent($stored, $this->owner, $this->beneficiary, $this->wallet);
        $projection = $service->project($stored);
        $this->assertSame('https://api.spend.nium.com', $stored->binding['base_url']);
        $this->assertSame('0.800000000000000001', $projection['nium_quoted_fx_rate']);
        $this->assertSame('980', $projection['quoted_principal']['amount']);
        $this->assertSame('784', $projection['quoted_destination_amount']['amount']);
        $this->assertSame('DENIED', $projection['dispatch']);
        $this->noAdmission();
    }

    public static function quoteConfigurationFailures(): array
    {
        return [
            ['services.nium.base_url', 'https://unknown.nium.com', 'dynamic_provider_environment_unverified'],
            ['services.nium.quote_endpoint', '/api/v2/client/{clientHashId}/quotes', 'dynamic_quote_endpoint_unverified'],
            ['nium_dynamic_quotes.quote_contract_enabled', false, 'dynamic_provider_quote_disabled'],
            ['services.nium.payout_fx_enabled', false, 'dynamic_provider_quote_disabled'],
        ];
    }

    #[DataProvider('quoteConfigurationFailures')]
    public function test_quote_preflight_refuses_unverified_configuration_without_transport(string $key, mixed $value, string $reason): void
    {
        $this->useQuoteOrigin();
        config([$key => $value]);
        $this->nium->shouldNotReceive('get');
        $this->nium->shouldNotReceive('post');
        $this->assertQuoteFailure($reason, fn () => $this->createReference());
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
    }

    public static function quoteConfigurationDrift(): array
    {
        $cases = [];
        foreach (['before', 'after'] as $stage) {
            $cases[] = [$stage, 'services.nium.base_url', 'https://gateway.nium.com', 'dynamic_provider_scope_unverified'];
            $cases[] = [$stage, 'services.nium.quote_endpoint', '/custom/quotes', 'dynamic_quote_endpoint_unverified'];
            $cases[] = [$stage, 'nium_dynamic_quotes.quote_contract_enabled', false, 'dynamic_provider_quote_disabled'];
            $cases[] = [$stage, 'services.nium.payout_fx_enabled', false, 'dynamic_provider_quote_disabled'];
            $cases[] = [$stage, 'services.nium.client_id', 'foreign-client', $stage === 'before'
                ? 'dynamic_quote_transport_binding_changed' : 'dynamic_quote_binding_changed'];
        }
        return $cases;
    }

    #[DataProvider('quoteConfigurationDrift')]
    public function test_quote_rechecks_configuration_at_transport_and_response(string $stage, string $key, mixed $value, string $reason): void
    {
        $this->useQuoteOrigin();
        $this->customerListing();
        $change = static fn () => config([$key => $value]);
        $this->providerQuote(during: $stage === 'after' ? $change : null,
            outgoing: function ($url, $payload) use ($stage, $change): Request {
                if ($stage === 'before') {
                    $change();
                }
                return new Request(new PsrRequest('POST', $url, [], json_encode($payload, JSON_THROW_ON_ERROR)));
            });
        $this->assertQuoteFailure($reason, fn () => $this->createReference());
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
    }

    public static function quoteWireMutations(): array
    {
        return [['method'], ['origin'], ['path'], ['customer'], ['manual'], ['schedule'], ['amount'], ['missing_callback']];
    }

    #[DataProvider('quoteWireMutations')]
    public function test_quote_wire_guard_refuses_changed_request(string $mutation): void
    {
        $this->useQuoteOrigin();
        $this->customerListing();
        $this->providerQuote(outgoing: static function ($url, $payload) use ($mutation): ?Request {
            if ($mutation === 'missing_callback') {
                return null;
            }
            $method = $mutation === 'method' ? 'GET' : 'POST';
            if ($mutation === 'origin') {
                $url = str_replace('api.spend.nium.com', 'gateway.nium.com', $url);
            } elseif ($mutation === 'path') {
                $url .= '/';
            } elseif ($mutation === 'customer') {
                $payload['customerHashId'] = 'foreign-customer';
            } elseif ($mutation === 'manual') {
                $payload['executionType'] = 'manual';
            } elseif ($mutation === 'schedule') {
                $payload['conversionSchedule'] = 'scheduled';
            } elseif ($mutation === 'amount') {
                $payload['sourceAmount'] = 981;
            }
            return new Request(new PsrRequest($method, $url, [], json_encode($payload, JSON_THROW_ON_ERROR)));
        });
        $this->assertQuoteFailure('dynamic_quote_transport_binding_changed', fn () => $this->createReference());
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
    }

    public function test_stored_spend_reference_cannot_be_rebound_to_gateway_production(): void
    {
        $this->useQuoteOrigin();
        $this->customerListing();
        $this->providerQuote();
        $stored = $this->createReference();
        config(['services.nium.base_url' => 'https://gateway.nium.com']);
        $service = app(NiumDynamicPayoutQuoteService::class);
        $this->assertQuoteFailure('dynamic_provider_scope_unverified', fn () =>
            $service->assertCurrent($stored, $this->owner, $this->beneficiary, $this->wallet));
        $this->assertQuoteFailure('dynamic_provider_scope_unverified', fn () => $service->project($stored));
        $this->noAdmission();
    }

    public function test_stored_quote_cannot_bypass_a_subsequently_disabled_feature_gate(): void
    {
        $this->useQuoteOrigin();
        $this->customerListing();
        $this->providerQuote();
        $stored = $this->createReference();
        config(['nium_dynamic_quotes.quote_contract_enabled' => false]);
        $service = app(NiumDynamicPayoutQuoteService::class);
        $this->assertQuoteFailure('dynamic_provider_quote_disabled', fn () =>
            $service->assertCurrent($stored, $this->owner, $this->beneficiary, $this->wallet));
        $this->assertQuoteFailure('dynamic_provider_quote_disabled', fn () => $service->project($stored));
        $this->noAdmission();
    }

    /** Quote-consumption fixture only; never admitted, reserved or dispatched. */
    private function retainedConsumption(NiumDynamicPayoutQuote $stored, string $environment): NiumDynamicIntent
    {
        $terms = $this->usdReview()->snapshot();
        $terms['context']['provider_origin'] = $stored->binding['base_url'];
        $terms['context']['corridor']['environment'] = $environment;
        $terms['quote_id'] = $stored->quote_id;
        return NiumDynamicIntent::query()->forceCreate([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $this->owner->id,
            'provider_id' => $stored->provider_id, 'provider_account_id' => $stored->provider_account_id,
            'beneficiary_id' => $stored->beneficiary_id, 'balance_id' => $stored->balance_id,
            'client_reference' => 'synthetic-consumed-'.$environment,
            'reference_sha256' => hash('sha256', 'synthetic-consumed-'.$environment),
            'request_fingerprint' => hash('sha256', 'synthetic-request'),
            'terms_fingerprint' => hash('sha256', json_encode($terms, JSON_THROW_ON_ERROR)),
            'quote_consumption_key' => NiumDynamicIntent::quoteConsumptionKey((int) $stored->provider_id,
                $environment, $stored->binding['client'], $stored->binding['customer'], $stored->quote_id),
            'quote_id' => $stored->quote_id, 'quote_expires_at' => $stored->expires_at,
            'customer' => $stored->binding['customer'], 'source_wallet' => $stored->binding['wallet'],
            'amount_mode' => $stored->binding['amount_mode'], 'source_currency' => 'USD',
            'destination_currency' => 'GBP', 'total_debit' => '1000', 'consent_actor_id' => $this->owner->id,
            'consented_at' => now(), 'confirmed_terms' => $terms, 'consent_binding' => [],
            'admission_evidence' => [], 'created_at' => now(),
        ]);
    }

    public function test_spend_consumption_uses_production_key_and_refuses_reacquisition(): void
    {
        $this->useQuoteOrigin();
        $this->customerListing();
        $this->providerQuote();
        $stored = $this->createReference();
        $intent = $this->retainedConsumption($stored, 'production');
        $service = app(NiumDynamicPayoutQuoteService::class);
        $service->assertCurrent($stored, $this->owner, $this->beneficiary, $this->wallet, $intent);
        $this->assertQuoteFailure('dynamic_quote_already_consumed', fn () =>
            $service->assertCurrent($stored, $this->owner, $this->beneficiary, $this->wallet));
        // Same provider ID returned again: assertUnused must use Production, not sandbox.
        $this->providerQuote();
        $this->assertQuoteFailure('dynamic_quote_already_consumed', fn () => $this->createReference());
        $this->assertSame(1, NiumDynamicPayoutQuote::query()->count());
        $this->assertSame(0, FxQuote::query()->count());
        $this->assertSame(0, NiumDynamicReservation::query()->count());
        $this->assertSame(0, NiumDynamicPayoutOutbox::query()->count());
    }

    public static function consumingQuoteMutations(): array
    {
        return [['origin'], ['environment'], ['missing_origin'], ['client'], ['customer'], ['wallet'],
            ['beneficiary'], ['account'], ['provider'], ['sandbox_key']];
    }

    #[DataProvider('consumingQuoteMutations')]
    public function test_consuming_intent_must_match_the_spend_quote_scope(string $mutation): void
    {
        $this->useQuoteOrigin();
        $this->customerListing();
        $this->providerQuote();
        $stored = $this->createReference();
        $intent = $this->retainedConsumption($stored, 'production');
        $context = $intent->confirmed_terms['context'];
        $reason = 'dynamic_quote_already_consumed';
        if (in_array($mutation, ['origin', 'environment', 'missing_origin'], true)) {
            $reason = 'dynamic_provider_scope_unverified';
            if ($mutation === 'origin') {
                $context['provider_origin'] = 'https://gateway.nium.com';
            } elseif ($mutation === 'environment') {
                $context['corridor']['environment'] = 'sandbox';
            } else {
                unset($context['provider_origin']); // Historical Production means gateway only.
            }
        } elseif ($mutation === 'client') {
            $context['corridor']['client'] = 'foreign-client';
        } elseif ($mutation === 'sandbox_key') {
            $intent->quote_consumption_key = NiumDynamicIntent::quoteConsumptionKey((int) $stored->provider_id,
                'sandbox', $stored->binding['client'], $stored->binding['customer'], $stored->quote_id);
        } else {
            $field = match ($mutation) {
                'customer' => 'customer', 'wallet' => 'source_wallet', 'beneficiary' => 'beneficiary_id',
                'account' => 'provider_account_id', 'provider' => 'provider_id',
            };
            $intent->setAttribute($field, in_array($mutation, ['customer', 'wallet'], true) ? 'foreign' : 999999);
        }
        // Mutate only the in-memory test snapshot; immutable records stay unchanged.
        $terms = $intent->confirmed_terms;
        $terms['context'] = $context;
        $intent->confirmed_terms = $terms;
        $this->assertQuoteFailure($reason, fn () => app(NiumDynamicPayoutQuoteService::class)
            ->assertCurrent($stored, $this->owner, $this->beneficiary, $this->wallet, $intent));
        $this->assertSame(0, NiumDynamicReservation::query()->count());
        $this->assertSame(0, NiumDynamicPayoutOutbox::query()->count());
    }

    public function test_quote_request_refuses_lossy_numeric_transport_serialization(): void
    {
        $this->useQuoteOrigin();
        $this->customerListing();
        $this->nium->shouldNotReceive('post');
        $previous = ini_get('serialize_precision');
        try {
            ini_set('serialize_precision', '3');
            $this->assertQuoteFailure('dynamic_quote_request_precision_unavailable', fn () =>
                app(NiumDynamicPayoutQuoteService::class)->create($this->owner, $this->beneficiary,
                    $this->wallet, PayoutAmountMode::Source, '1000.12'));
        } finally {
            ini_set('serialize_precision', $previous);
        }
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
    }

    public function test_spend_response_amount_cannot_be_rounded_into_storage_precision(): void
    {
        $this->useQuoteOrigin();
        $this->customerListing();
        $this->providerQuote(body: '{"id":"precision-quote","quoteType":"payout","quoteIntent":"EXECUTABLE",'
            .'"customerHashId":"fixture-customer","sourceCurrencyCode":"USD","destinationCurrencyCode":"'.$this->beneficiary->currency.'",'
            .'"conversionSchedule":"immediate","executionType":"at_conversion_time","lockPeriod":"5_mins",'
            .'"sourceAmount":980,"destinationAmount":784.001,"netExchangeRate":0.800000000000000001,'
            .'"expiryTime":"2030-01-01T01:00:00Z"}');
        $this->assertQuoteFailure('dynamic_quote_amount_precision_unavailable', fn () => $this->createReference());
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
    }

    public static function excludedDynamicDestinations(): array { return [['SGD'], ['USD']]; }

    #[DataProvider('excludedDynamicDestinations')]
    public function test_dynamic_quote_creation_does_not_enroll_sgd_or_usd_destinations(string $currency): void
    {
        $this->beneficiary->currency = $currency;
        $this->nium->shouldNotReceive('get');
        $this->nium->shouldNotReceive('post');
        $this->assertQuoteFailure('dynamic_quote_local_eligibility_changed', fn () => $this->createReference());
        $this->noAdmission();
    }

    public function test_sgd_local_listing_does_not_enable_dynamic_or_change_legacy_pricing(): void
    {
        $this->beneficiary->update(['country_code' => 'SG', 'currency' => 'SGD']);
        $request = '{"payoutMethod":"LOCAL","destinationCountry":"SG","destinationCurrency":"SGD"}';
        $this->exchange->update(['raw_request_body' => $request, 'request_body_sha256' => hash('sha256', $request)]);
        $this->customerListing();
        $this->getJson($this->path('/options?beneficiary_id='.$this->beneficiary->id))->assertOk()
            ->assertJsonPath('status', 'UNAVAILABLE')->assertJsonPath('dynamic_available', false)
            ->assertJsonPath('quote_availability.destination_currencies', [])
            ->assertJsonPath('reason_codes', ['dynamic_registered_account_payload_changed']);
        $this->noAdmission();
    }

    public static function contractedTuplesAndModes(): array
    {
        $cases = [];
        foreach ([['GB', 'GBP'], ['DE', 'EUR'], ['JP', 'JPY']] as [$country, $currency]) {
            foreach (['SOURCE_AMOUNT', 'DESTINATION_AMOUNT'] as $mode) {
                $cases[$country.' '.$mode] = [$country, $currency, $mode];
            }
        }
        return $cases;
    }

    #[DataProvider('contractedTuplesAndModes')]
    public function test_contracted_authorization_exercises_review_submit_single_dispatch_and_reconciliation(
        string $country, string $currency, string $mode): void
    {
        // Only HTTP is synthetic; the actual financial authorization and engine are exercised.
        $this->beneficiary->update(['country_code' => $country, 'currency' => $currency]);
        $review = $this->readyReview($mode);
        $this->assertSame('LOCAL', $review['payout_rail']);
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
        $this->assertSame($country, $record['binding']['destination_country']);
        $this->assertSame($currency, $record['binding']['destination_currency']);
        $this->assertSame('LOCAL', $record['binding']['payout_method']);
        $corridor = NiumDynamicIntent::query()->sole()->confirmed_terms['context']['corridor'];
        $this->assertSame($country, $corridor['destinationCountry']);
        $this->assertSame($currency, $corridor['destinationCurrency']);
        $this->assertSame('LOCAL', $corridor['payoutMethod']);
        $this->assertSame($record['evidence']['instruction_validation']['request_json'], $wire->bodies[0]);
        $this->assertSame($record['operation_key'], $outbox->fresh()->operation_key);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame('1000.00000000', NiumDynamicIntent::query()->sole()->total_debit);

        // Synthetic settlement rows exercise tuple/currency binding; they do not verify Nium fees.
        $scope = ['clientHashId' => 'fixture-client', 'customerHashId' => 'fixture-customer',
            'walletHashId' => 'fixture-wallet', 'authCurrencyCode' => 'USD', 'debit' => true,
            'settlementStatus' => 'Settled', 'status' => 'Approved'];
        $wire->batch = ['records' => [
            [...$scope, 'authCode' => 'RT-fixture-dynamic', 'externalId' => null, 'originalAuthorizationCode' => null,
                'transactionType' => 'Remittance_Debit', 'settlementAuthAmount' => '980', 'effectiveAuthAmount' => '1000',
                'transactionCurrencyCode' => $currency, 'settlementTransactionAmount' => '784',
                'labels' => ['beneficiaryId' => 'fixture-registered-payee']],
            [...$scope, 'authCode' => 'FT-fixture-fee', 'transactionType' => 'Fee_Debit', 'settlementAuthAmount' => '20',
                'labels' => ['originalAuthorizationCode' => 'RT-fixture-dynamic', 'feeName' => 'REMIT_BANK_FEE']],
        ], 'response_sha256' => [str_repeat('a', 64)], 'observed_at' => now()->toISOString()];
        app(DynamicPayoutReconciler::class)->reconcile($outbox->id);
        app(DynamicPayoutReconciler::class)->reconcile($outbox->id);
        $this->assertSame('matched', $outbox->fresh()->state);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame('consumed', NiumDynamicReservation::query()->sole()->state);
        $this->assertSame(1, $wire->submissions);
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
    public function test_synthetic_review_timeout_recovery_keeps_one_operation_even_with_an_early_callback(bool $early): void
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
        $this->fullRegistration(); $this->customerListing();
        $this->executionEvidence(fn () => $missing ? '{"data":[]}' : '{"content":[]}');
        $this->providerQuote();
        $id = $this->postJson($this->path('/quotes'), $this->input())->assertCreated()->json('dynamic_quote_id');
        $this->postJson($this->path('/reviews'), ['beneficiary_id' => $this->beneficiary->id, 'dynamic_quote_id' => $id,
            'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']])
            ->assertStatus(503)->assertJsonMissingPath('accepted_fingerprint')
            ->assertJsonPath('reason_codes', [$missing ? 'dynamic_customer_corridor_not_permitted' : 'dynamic_customer_corridor_response_contract_unavailable']);
        $this->noAdmission();
    }

    public static function listedCustomerAuthorizationGaps(): array
    {
        $row = ['destination_currency' => 'GBP', 'payout_method' => 'LOCAL',
            'destination_country' => ['GB'], 'routing_code_type' => 'synthetic-route-a'];
        return [
            'Spend four fields and two matching rows' => [[$row, [...$row, 'routing_code_type' => 'synthetic-route-b']],
                'dynamic_customer_corridor_legal_entity_unverified'],
            'legal entity absent' => [[[...$row, 'ignoreLookup' => false]], 'dynamic_customer_corridor_legal_entity_unverified'],
            'lookup policy absent' => [[[...$row, 'client_legal_entity' => ['LEGAL_ENTITY_HK']]],
                'dynamic_customer_corridor_lookup_policy_unverified'],
        ];
    }

    #[DataProvider('listedCustomerAuthorizationGaps')]
    public function test_customer_listing_without_authorization_cannot_produce_an_admissible_review(array $rows, string $reason): void
    {
        // Sandbox execution fixtures isolate authorization from unrelated Spend guards.
        // The four-field response shape is synthetic, following the observed Spend shape.
        $this->fullRegistration();
        $this->customerListing();
        $this->executionEvidence(static fn (): string => json_encode(['requestId' => 'synthetic-request',
            'correlationId' => 'synthetic-correlation', 'timestamp' => 'synthetic-timestamp', 'data' => $rows], JSON_THROW_ON_ERROR));
        $this->providerQuote();
        $id = $this->postJson($this->path('/quotes'), $this->input())->assertCreated()->json('dynamic_quote_id');
        $this->postJson($this->path('/reviews'), ['beneficiary_id' => $this->beneficiary->id, 'dynamic_quote_id' => $id,
            'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']])
            ->assertStatus(503)->assertJsonPath('status', 'UNAVAILABLE')
            ->assertJsonPath('reason_codes', [$reason])->assertJsonMissingPath('accepted_fingerprint');
        $this->noAdmission();
    }

    public function test_schema_if_then_is_not_treated_as_a_successful_instruction_validation(): void
    {
        $this->fullRegistration();
        $this->customerListing();
        $this->executionEvidence(); // Includes a synthetic if/then requirement.
        $this->providerQuote();
        $this->validationResponse = '{"valid":false,"issues":["synthetic_conditional_requirement_missing"]}';
        $id = $this->postJson($this->path('/quotes'), $this->input())->assertCreated()->json('dynamic_quote_id');
        $this->postJson($this->path('/reviews'), ['beneficiary_id' => $this->beneficiary->id, 'dynamic_quote_id' => $id,
            'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']])
            ->assertStatus(503)->assertJsonPath('reason_codes', ['dynamic_payout_instruction_rejected'])
            ->assertJsonMissingPath('accepted_fingerprint');
        $this->noAdmission();
    }

    public function test_schema_missing_destination_amount_cannot_default_precision_and_produce_a_review(): void
    {
        $this->fullRegistration();
        $this->customerListing();
        $this->executionEvidence(schemaBody: '{"$schema":"http://json-schema.org/draft-07/schema#","type":"object",'
            .'"required":["synthetic.field"],"allOf":[{"if":{},"then":{}}],"properties":{}}');
        $this->providerQuote();
        $id = $this->postJson($this->path('/quotes'), $this->input())->assertCreated()->json('dynamic_quote_id');
        $this->postJson($this->path('/reviews'), ['beneficiary_id' => $this->beneficiary->id, 'dynamic_quote_id' => $id,
            'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']])
            ->assertStatus(503)->assertJsonPath('reason_codes', ['dynamic_payout_schema_destination_amount_unverified'])
            ->assertJsonMissingPath('accepted_fingerprint');
        $this->noAdmission();
    }

    public function test_schema_from_a_different_transport_origin_cannot_produce_a_review(): void
    {
        $this->fullRegistration();
        $this->customerListing();
        $this->executionEvidence(schemaOutgoing: static fn ($url): Request =>
            new Request(new PsrRequest('GET', str_replace('gatewaysandbox.nium.com', 'api.spend.nium.com', $url))));
        $this->providerQuote();
        $id = $this->postJson($this->path('/quotes'), $this->input())->assertCreated()->json('dynamic_quote_id');
        $this->postJson($this->path('/reviews'), ['beneficiary_id' => $this->beneficiary->id, 'dynamic_quote_id' => $id,
            'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']])
            ->assertStatus(503)->assertJsonPath('reason_codes', ['dynamic_eligibility_scope_changed'])
            ->assertJsonMissingPath('accepted_fingerprint');
        $this->noAdmission();
    }

    #[DataProvider('modes')]
    public function test_real_quote_is_exact_persisted_and_cannot_authorize_review_or_submission(string $mode): void
    {
        DB::statement("SET TIME ZONE 'Asia/Bangkok'");
        $this->customerListing();
        $this->providerQuote($mode);
        $response = $this->postJson($this->path('/quotes'), $this->input($mode))->assertCreated()
            ->assertJsonPath('status', 'QUOTE_REFERENCE_ONLY')->assertJsonPath('quoted_principal.amount', '980')
            ->assertJsonPath('planned_included_fee.amount', '20')->assertJsonPath('planned_total_usd_debit', '1000')
            ->assertJsonPath('nium_quoted_fx_rate', '0.800000000000000001')
            ->assertJsonPath('quote_expires_at', '2030-01-01T01:00:00.000000Z')->assertJsonPath('review', 'REQUIRED');
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

    public function test_options_require_registered_account_and_do_not_promote_catalogue_only_support(): void
    {
        $this->customerListing();
        $this->nium->shouldNotReceive('post');
        $this->getJson($this->path('/options?beneficiary_id='.$this->beneficiary->id))->assertOk()
            ->assertJsonPath('status', 'UNAVAILABLE')->assertJsonPath('dynamic_available', false)
            ->assertJsonPath('destination_currencies', [])->assertJsonPath('eligible_payout_rails', [])
            ->assertJsonPath('supported_amount_modes', [])
            ->assertJsonPath('reason_codes', ['dynamic_registered_account_payload_changed'])
            ->assertJsonPath('quote_availability.destination_currencies', []);
        $this->noAdmission();
    }

    public function test_options_do_not_offer_a_signed_corridor_without_customer_entitlement(): void
    {
        $this->fullRegistration(); $this->customerListing(); $this->executionEvidence(fn () => '{"data":[]}');
        $this->nium->shouldNotReceive('post');
        $this->getJson($this->path('/options?beneficiary_id='.$this->beneficiary->id))->assertOk()
            ->assertJsonPath('dynamic_available', false)->assertJsonPath('destination_currencies', [])
            ->assertJsonPath('eligible_payout_rails', [])
            ->assertJsonPath('reason_codes', ['dynamic_customer_corridor_not_permitted']);
        $this->noAdmission();
    }

    public function test_options_expose_only_customer_verified_beneficiary_currency_and_local_rail(): void
    {
        $this->fullRegistration(); $this->customerListing(); $this->executionEvidence();
        $this->nium->shouldNotReceive('post');
        $this->getJson($this->path('/options?beneficiary_id='.$this->beneficiary->id))->assertOk()
            ->assertJsonPath('dynamic_available', true)->assertJsonPath('destination_currencies', ['GBP'])
            ->assertJsonPath('eligible_payout_rails', ['LOCAL'])
            ->assertJsonPath('supported_amount_modes', ['SOURCE_AMOUNT', 'DESTINATION_AMOUNT'])
            ->assertJsonPath('currency_scales', ['USD' => 2, 'GBP' => 2])
            ->assertJsonPath('quote_availability.payout_rail', 'LOCAL')->assertJsonPath('reason_codes', []);
        $this->noAdmission();
    }

    public static function unpricedListedTuples(): array
    {
        return [['FR', 'EUR', 'LOCAL'], ['GB', 'GBP', 'SWIFT'], ['TH', 'THB', 'CASH']];
    }

    #[DataProvider('unpricedListedTuples')]
    public function test_customer_listing_never_extends_usd20_pricing_to_other_countries_or_rails(
        string $country, string $currency, string $rail): void
    {
        $this->beneficiary->update(['country_code' => $country, 'currency' => $currency, 'payout_method' => $rail]);
        $this->fullRegistration(); $this->customerListing(); $this->executionEvidence();
        $this->nium->shouldNotReceive('post');
        $this->getJson($this->path('/options?beneficiary_id='.$this->beneficiary->id))->assertOk()
            ->assertJsonPath('provider_listing.status', 'LISTED')
            ->assertJsonPath('provider_listing.eligible', false)
            ->assertJsonPath('provider_listing.matches.0.payout_method', $rail)
            ->assertJsonPath('customer_entitlement', 'VERIFIED')
            ->assertJsonPath('financial_authorization', 'UNAVAILABLE')
            ->assertJsonPath('dynamic_available', false)->assertJsonPath('supported_amount_modes', [])
            ->assertJsonPath('executable_amount_modes', [])
            ->assertJsonPath('reason_codes', ['dynamic_pricing_corridor_unsupported']);
        $this->postJson($this->path('/quotes'), $this->input())->assertStatus(503)
            ->assertJsonPath('reason_codes', ['dynamic_pricing_corridor_unsupported']);
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
    }

    public function test_spend_four_field_listing_stays_distinct_from_entitlement_and_execution(): void
    {
        $this->useQuoteOrigin();
        $this->fullRegistration(); $this->customerListing();
        $this->executionEvidence(fn () => '{"data":[{"destination_currency":"GBP","payout_method":"LOCAL",'
            .'"destination_country":["GB"],"routing_code_type":"synthetic-route"}]}');
        $this->nium->shouldNotReceive('post');
        $this->getJson($this->path('/options?beneficiary_id='.$this->beneficiary->id))->assertOk()
            ->assertJsonPath('provider_listing.status', 'LISTED')->assertJsonPath('provider_listing.eligible', false)
            ->assertJsonPath('customer_entitlement', 'UNAVAILABLE')
            ->assertJsonPath('financial_authorization', 'CUSTOMER_PRICING_AUTHORIZED')
            ->assertJsonPath('dynamic_available', false)->assertJsonPath('supported_amount_modes', [])
            ->assertJsonPath('executable_amount_modes', [])->assertJsonPath('requires_instruction_validation', true)
            ->assertJsonPath('reason_codes', ['dynamic_customer_corridor_legal_entity_unverified']);
        $this->noAdmission();
    }

    #[DataProvider('modes')]
    public function test_destination_only_schema_limits_options_and_review_without_guessing_source_support(string $mode): void
    {
        $this->fullRegistration(); $this->customerListing();
        $this->executionEvidence(schemaBody: '{"$schema":"http://json-schema.org/draft-07/schema#","type":"object",'
            .'"properties":{"payout.destinationAmount":{"type":"number","maximumDecimals":2}},'
            .'"required":["payout.destinationAmount"],"allOf":[{"if":{"required":["synthetic.flag"],"properties":{"synthetic.flag":{"const":true}}},'
            .'"then":{"required":["synthetic.conditionalField"]}}]}');
        $this->getJson($this->path('/options?beneficiary_id='.$this->beneficiary->id))->assertOk()
            ->assertJsonPath('supported_amount_modes', ['DESTINATION_AMOUNT'])
            ->assertJsonPath('executable_amount_modes', [])->assertJsonPath('requires_instruction_validation', true);
        $this->providerQuote($mode);
        $id = $this->postJson($this->path('/quotes'), $this->input($mode))->assertCreated()->json('dynamic_quote_id');
        $review = $this->postJson($this->path('/reviews'), ['beneficiary_id' => $this->beneficiary->id,
            'dynamic_quote_id' => $id, 'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']]);
        if ($mode === 'SOURCE_AMOUNT') {
            $review->assertStatus(503)->assertJsonPath('reason_codes', ['dynamic_payout_source_amount_contract_unverified'])
                ->assertJsonMissingPath('accepted_fingerprint');
        } else {
            $review->assertOk()->assertJsonPath('status', 'READY')->assertJsonPath('amount_mode', 'DESTINATION_AMOUNT');
        }
        $this->noAdmission();
    }

    public function test_review_cannot_substitute_a_different_rail_after_quote_binding(): void
    {
        $review = $this->readyReview();
        $service = app(NiumDynamicReviewService::class);
        $record = $service->load($review['accepted_fingerprint']);
        $record['evidence']['execution_eligibility']['selection']['payout_method'] = 'SWIFT';
        $stored = NiumDynamicPayoutQuote::query()->findOrFail($record['dynamic_quote_id']);
        $this->assertQuoteFailure('dynamic_review_binding_changed', fn () => $service->assemble($record, $stored));
        $this->noAdmission();
    }

    public function test_cached_review_cannot_bypass_a_newly_enforced_schema_amount_requirement(): void
    {
        $review = $this->readyReview('SOURCE_AMOUNT');
        $service = app(NiumDynamicReviewService::class);
        $record = $service->load($review['accepted_fingerprint']);
        $schema = json_decode($record['evidence']['execution_eligibility']['schema_observation']['schema_json'], true, 64, JSON_THROW_ON_ERROR);
        $schema['required'][] = 'payout.destinationAmount';
        $record['evidence']['execution_eligibility']['schema_observation']['schema_json'] = json_encode($schema, JSON_THROW_ON_ERROR);
        // Keep the cached SOURCE_AMOUNT summary and original consent snapshot.
        $stored = NiumDynamicPayoutQuote::query()->findOrFail($record['dynamic_quote_id']);
        $this->assertQuoteFailure('dynamic_payout_source_amount_contract_unverified', fn () => $service->assemble($record, $stored));
        $this->noAdmission();
    }

    public static function disabledReviewProcessing(): array
    {
        return [
            ['nium_dynamic_confirmation.automatic_dispatch_enabled'],
            ['nium_dynamic_confirmation.enabled'],
            ['nium_dynamic_payouts.reconciliation_enabled'],
        ];
    }

    #[DataProvider('disabledReviewProcessing')]
    public function test_review_cannot_be_ready_without_automatic_processing(string $flag): void
    {
        $this->customerListing(); $this->providerQuote();
        $id = $this->postJson($this->path('/quotes'), $this->input())->assertCreated()->json('dynamic_quote_id');
        config([$flag => false]);
        $this->postJson($this->path('/reviews'), ['beneficiary_id' => $this->beneficiary->id,
            'dynamic_quote_id' => $id, 'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']])
            ->assertStatus(503)->assertJsonPath('reason_codes', ['dynamic_payout_submission_disabled'])
            ->assertJsonMissingPath('accepted_fingerprint');
        $this->noAdmission();
    }

    public function test_review_cannot_be_ready_without_production_operator_authorization(): void
    {
        $this->customerListing(); $this->providerQuote();
        $id = $this->postJson($this->path('/quotes'), $this->input())->assertCreated()->json('dynamic_quote_id');
        config(['services.nium.base_url' => 'https://gateway.nium.com',
            'nium_dynamic_payouts.live_automatic_dispatch_authorized' => false,
            'nium_dynamic_payouts.operator_authorization_reference' => null]);
        $this->postJson($this->path('/reviews'), ['beneficiary_id' => $this->beneficiary->id,
            'dynamic_quote_id' => $id, 'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']])
            ->assertStatus(503)->assertJsonPath('reason_codes', ['dynamic_payout_submission_disabled'])
            ->assertJsonMissingPath('accepted_fingerprint');
        $this->noAdmission();
    }

    public function test_review_rechecks_processing_after_provider_observations(): void
    {
        $this->fullRegistration(); $this->customerListing(); $this->executionEvidence(); $this->providerQuote();
        $id = $this->postJson($this->path('/quotes'), $this->input())->assertCreated()->json('dynamic_quote_id');
        $this->duringValidation = static fn () => config(['nium_dynamic_confirmation.automatic_dispatch_enabled' => false]);
        $this->postJson($this->path('/reviews'), ['beneficiary_id' => $this->beneficiary->id,
            'dynamic_quote_id' => $id, 'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']])
            ->assertStatus(503)->assertJsonPath('reason_codes', ['dynamic_payout_submission_disabled'])
            ->assertJsonMissingPath('accepted_fingerprint');
        $this->noAdmission();
    }

    public static function retainedSchemaMismatches(): array
    {
        return [['currency_scales'], ['supported_amount_modes'], ['payout_schema_sha256'], ['raw_precision']];
    }

    #[DataProvider('retainedSchemaMismatches')]
    public function test_reconstructed_review_refuses_schema_summary_or_hash_mismatch(string $field): void
    {
        $review = $this->readyReview();
        $service = app(NiumDynamicReviewService::class);
        $record = $service->load($review['accepted_fingerprint']);
        $eligibility = &$record['evidence']['execution_eligibility'];
        if ($field === 'raw_precision') {
            $schema = json_decode($eligibility['schema_observation']['schema_json'], true, 64, JSON_THROW_ON_ERROR);
            $schema['properties']['payout.destinationAmount']['maximumDecimals'] = '0';
            $eligibility['schema_observation']['schema_json'] = json_encode($schema, JSON_THROW_ON_ERROR);
            $eligibility['payout_schema_sha256'] = hash('sha256', $eligibility['schema_observation']['schema_json']);
        } else {
            $eligibility[$field] = match ($field) {
                'currency_scales' => ['USD' => 2, 'GBP' => 0],
                'supported_amount_modes' => ['SOURCE_AMOUNT'],
                default => str_repeat('0', 64),
            };
        }
        $stored = NiumDynamicPayoutQuote::query()->findOrFail($record['dynamic_quote_id']);
        $this->assertQuoteFailure('dynamic_payout_schema_contract_unavailable', fn () => $service->assemble($record, $stored));
        $this->noAdmission();
    }

    public function test_verified_options_expose_zero_decimal_destination_scale_without_a_global_currency_guess(): void
    {
        $this->beneficiary->update(['country_code' => 'JP', 'currency' => 'JPY']);
        $this->fullRegistration(); $this->customerListing(); $this->executionEvidence();
        $this->getJson($this->path('/options?beneficiary_id='.$this->beneficiary->id))->assertOk()
            ->assertJsonPath('dynamic_available', true)->assertJsonPath('currency_scales', ['USD' => 2, 'JPY' => 0]);
        $this->noAdmission();
    }

    public function test_previous_zero_origin_fee_pricing_requires_a_new_quote_and_consent(): void
    {
        $this->customerListing(); $this->providerQuote();
        $id = $this->postJson($this->path('/quotes'), $this->input())->assertCreated()->json('dynamic_quote_id');
        $stored = NiumDynamicPayoutQuote::query()->findOrFail($id);
        $price = app(\App\Services\Nium\DynamicConfirmation\DynamicPayoutFinancialAuthorization::class)->pricing($this->beneficiary);
        $price['version'] = 'origin-nium-contracted-local-usd-inclusive-v2';
        $price['origin_service_fee_usd'] = '0';
        $binding = $stored->binding;
        $binding['customer_pricing_sha256'] = hash('sha256', json_encode($price, JSON_THROW_ON_ERROR));
        // Model-only stale snapshot: immutable persisted quotes are never rewritten.
        $stored->binding = $binding;
        $this->assertQuoteFailure('dynamic_quote_binding_changed', fn () => app(NiumDynamicPayoutQuoteService::class)->project($stored));
        $this->noAdmission();
    }

    public function test_quote_without_the_new_frozen_tuple_version_requires_fresh_preparation(): void
    {
        $this->customerListing(); $this->providerQuote();
        $stored = $this->createReference();
        // Mutate an in-memory copy only; retained provider references remain immutable.
        $binding = $stored->binding;
        $binding['version'] = 'nium-dynamic-reference-quote-v2';
        unset($binding['destination_country'], $binding['payout_method']);
        $stored->binding = $binding;
        $this->assertQuoteFailure('dynamic_quote_binding_changed', fn () => app(NiumDynamicPayoutQuoteService::class)->project($stored));
        $this->noAdmission();
    }

    public function test_server_owned_review_preparation_is_immutable_and_never_becomes_executable_by_accepting_its_digest(): void
    {
        $this->customerListing();
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
        $this->customerListing();
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
        $this->customerListing();
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
        $this->customerListing();
        $this->providerQuote($mode);
        $quote = $this->postJson($this->path('/quotes'), $this->input($mode))->assertCreated()->json('dynamic_quote_id');

        // Use the existing confirmation stub to consume the exact provider ID.
        // Partial registration still cannot produce an admissible HTTP Review.
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
        // Each new quote repeats the authenticated customer listing read.
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
        $this->customerListing();
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
        $this->customerListing();
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
        $this->customerListing(false);
        $this->nium->shouldNotReceive('post');
        $this->postJson($this->path('/quotes'), $this->input())->assertStatus(503)
            ->assertJsonPath('reason_codes', ['dynamic_customer_corridor_not_permitted']);
        $this->noAdmission();
    }

    public function test_provider_contract_missing_echoes_is_unavailable_without_persisting_quote(): void
    {
        $this->customerListing();
        $this->providerQuote(body: '{"id":"quote","sourceAmount":980,"destinationAmount":784,"netExchangeRate":0.8}');
        $this->postJson($this->path('/quotes'), $this->input())->assertStatus(503)
            ->assertJsonPath('reason_codes', ['dynamic_provider_quote_contract_unavailable']);
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
    }

    public function test_balance_including_existing_holds_is_checked_after_provider_response(): void
    {
        $this->wallet->update(['reserved_balance' => '1500']);
        $this->customerListing();
        $this->providerQuote();
        $this->postJson($this->path('/quotes'), $this->input())->assertStatus(409)
            ->assertJsonPath('reason_codes', ['dynamic_insufficient_available_funds']);
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->assertSame('1500.00000000', $this->wallet->fresh()->reserved_balance);
    }

    public function test_destination_quote_requires_funds_for_principal_plus_the_included_fee(): void
    {
        $this->wallet->update(['available_balance' => '999', 'raw_data' => ['provider_available_balance' => '999']]);
        $this->customerListing();
        $this->providerQuote('DESTINATION_AMOUNT');
        $this->postJson($this->path('/quotes'), $this->input('DESTINATION_AMOUNT'))->assertStatus(409)
            ->assertJsonPath('reason_codes', ['dynamic_insufficient_available_funds']);
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
    }

    public function test_account_change_during_quote_io_cannot_be_persisted(): void
    {
        $this->customerListing();
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
        $this->expectException(\App\Services\Wallet\Concurrency\OwnerLockContractViolation::class);
        DB::transaction(fn () => app(NiumDynamicPayoutQuoteService::class)
            ->availability($this->owner, $this->beneficiary, $this->wallet));
    }

    public function test_foreign_customer_cannot_review_a_persisted_reference_quote(): void
    {
        $this->customerListing();
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
        return [['USD', 'SWIFT', 'dynamic_same_currency_quote_contract_unverified'],
            ['GBP', 'SWIFT', 'dynamic_pricing_corridor_unsupported'], ['GBP', 'CASH', 'dynamic_pricing_corridor_unsupported']];
    }

    #[DataProvider('legacyLanes')]
    public function test_unverified_same_currency_swift_or_other_rails_have_explicit_refusals(string $currency, string $rail, string $reason): void
    {
        $this->beneficiary->update(['currency' => $currency, 'payout_method' => $rail]);
        $this->nium->shouldNotReceive('get');
        $this->nium->shouldNotReceive('post');
        $this->postJson($this->path('/quotes'), [...$this->input(), 'destination_currency' => $currency])->assertStatus(503)
            ->assertJsonPath('reason_codes', [$reason]);
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
    }
}
