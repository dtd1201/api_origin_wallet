<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutAmountMode;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutQuoteRequest;
use App\Models\ApiRequestLog;
use App\Models\AmlScreening;
use App\Models\ApiToken;
use App\Models\FxQuote;
use App\Models\NiumApiExchangeEvidence;
use App\Models\NiumDynamicIntent;
use App\Models\NiumDynamicPayoutOutbox;
use App\Models\NiumDynamicPayoutQuote;
use App\Models\NiumDynamicReservation;
use App\Models\NiumRfiCase;
use App\Models\LedgerEntry;
use App\Models\Transfer;
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
use App\Services\Nium\DynamicConfirmation\DynamicTransferConfirmationService;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutWalletSnapshot;
use Tests\Support\DynamicPayoutTransportStub;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
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
    private bool $detailsBinding = false;
    private int $detailsReads = 0;
    private array $detailsChanges = [];
    private ?array $detailsReceivingInputs = null;
    private ?string $detailsSchemaBody = null;
    private int $detailsSchemaReads = 0;
    private int $walletReads = 0;
    private ?string $walletResponseBody = null;
    private int $walletResponseStatus = 200;
    private ?\Closure $duringWalletRead = null;
    private ?\Closure $walletOutgoing = null;

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
            'services.nium.beneficiary_validation_schema_endpoint' => '/api/v2/client/{clientHashId}/customer/{customerHashId}/currency/{currencyCode}/validationSchemas',
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
        $this->nium->shouldReceive('get')->withArgs(static fn ($path, $query = [], $user = null, $operation = null): bool =>
            $operation === 'dynamic_beneficiary_binding_schema')->andReturnUsing(function ($path, $query, $user, $operation,
                $reference, $beforeDispatch): Response {
                $this->assertTrue($this->detailsBinding);
                $this->assertSame(0, DB::transactionLevel());
                $this->assertSame('/api/v2/client/fixture-client/customer/fixture-customer/currency/'.$this->beneficiary->currency.'/validationSchemas', $path);
                $this->assertSame($this->beneficiary->country_code, $query['destinationCountry']);
                $this->assertSame($this->beneficiary->currency, $query['destinationCurrency']);
                $this->assertSame($this->beneficiary->payout_method, $query['payoutMethod']);
                $this->detailsSchemaReads++;
                $url = rtrim((string) config('services.nium.base_url'), '/').$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
                $beforeDispatch(new Request(new PsrRequest('GET', $url, ['x-api-key' => 'synthetic-wallet-test-key'])));
                return $this->response($this->detailsSchemaBody ?? \Tests\Support\SyntheticDynamicBeneficiarySchema::body());
            });
        $this->nium->shouldReceive('get')->withArgs(static fn ($path, $query = [], $user = null, $operation = null): bool =>
            $operation === 'dynamic_beneficiary_details')->andReturnUsing(function ($path, $query, $user, $operation,
                $reference, $beforeDispatch): Response {
                $this->assertTrue($this->detailsBinding, 'Unexpected Details GET for a registration without sufficient receiving evidence.');
                $this->assertSame(0, DB::transactionLevel());
                $this->assertSame('/api/v2/client/fixture-client/customer/fixture-customer/beneficiaries/fixture-registered-payee', $path);
                $this->detailsReads++;
                $beforeDispatch(new Request(new PsrRequest('GET', rtrim((string) config('services.nium.base_url'), '/').$path,
                    ['x-api-key' => 'synthetic-wallet-test-key'])));
                $fields = app(NiumBeneficiaryService::class)->receivingAccountBinding($this->beneficiary->fresh());
                unset($fields['clientLegalEntity']);
                return $this->response(json_encode(['beneficiaryHashId' => $this->beneficiary->external_beneficiary_id,
                    'beneficiaryBankCode' => null, 'routingCodeType2' => null, 'routingCodeValue2' => null,
                    ...$fields, ...$this->detailsChanges], JSON_THROW_ON_ERROR));
            });
        $this->nium->shouldReceive('post')->withArgs(static fn ($path, $payload, $user, $transferId, $operation): bool =>
            $operation === 'dynamic_remittance_validate')->andReturnUsing(function ($path, $payload, $user,
                $transferId, $operation, $reference, $beforeDispatch): Response {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertSame('/api/v1/client/fixture-client/customer/fixture-customer/wallet/fixture-wallet/remittance/validate', $path);
                $this->assertArrayNotHasKey('additionalFees', $payload);
                $this->assertArrayNotHasKey('additionalFees', $payload['payout']);
                $beforeDispatch(new Request(new PsrRequest('POST', rtrim((string) config('services.nium.base_url'), '/').$path,
                    ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR))));
                if ($this->duringValidation !== null) ($this->duringValidation)();
                return $this->response($this->validationResponse);
            });
        $this->nium->shouldReceive('get')->withArgs(static fn ($path, $query = [], $user = null, $operation = null): bool =>
            $operation === 'dynamic_wallet_availability')->andReturnUsing(function ($path, $query, $user, $operation,
                $reference, $beforeDispatch): Response {
                $this->walletReads++;
                $this->assertSame(0, DB::transactionLevel());
                $this->assertFalse(DB::connection()->getPdo()->inTransaction());
                \App\Services\Wallet\Concurrency\OwnerFirst::outside();
                $this->assertSame('/api/v1/client/fixture-client/customer/fixture-customer/wallet/fixture-wallet', $path);
                $this->assertSame([], $query);
                $this->assertSame($this->owner->id, $user->id);
                $this->assertSame((string) $this->wallet->id, $reference);
                $url = rtrim((string) config('services.nium.base_url'), '/').$path;
                $outgoing = $this->walletOutgoing === null
                    ? new Request(new PsrRequest('GET', $url, ['x-api-key' => 'synthetic-wallet-test-key']))
                    : ($this->walletOutgoing)($url);
                if ($outgoing !== null) $beforeDispatch($outgoing);
                if ($this->duringWalletRead !== null) ($this->duringWalletRead)();
                if ($this->walletResponseBody !== null) {
                    return new Response(new PsrResponse($this->walletResponseStatus, ['Content-Type' => 'application/json'], $this->walletResponseBody));
                }
                if (config('services.nium.base_url') === 'https://api.spend.nium.com') {
                    return $this->response('[{"walletHashId":"fixture-wallet","currencyCode":"USD","balance":2000}]');
                }
                return $this->response('{"walletHashId":"fixture-wallet","currencies":[{"currencyCode":"USD","availableBalance":2000}]}');
            });
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            // Mockery::close() can throw before Laravel reaches its handler cleanup.
            HandleExceptions::flushState($this);
        }
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
        config(['services.nium.supported_corridors' => [['destinationCountry' => $this->beneficiary->country_code,
            'destinationCurrency' => $this->beneficiary->currency, 'payoutMethod' => $this->beneficiary->payout_method]]]);
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
        // Register only the provider Quote response. Production preparation must
        // obtain its own authenticated Wallet evidence through the real writer.
        $origin = rtrim((string) config('services.nium.base_url'), '/');
        $this->nium->shouldReceive('post')->once()->withArgs(static fn ($path, $payload, $user, $transferId, $operation): bool =>
            $operation === 'dynamic_payout_reference_quote')->andReturnUsing(function ($path, $payload, $user,
            $relatedTransferId, $operation, $externalReference, $beforeDispatch, PayoutQuoteRequest $dynamicReferenceQuote) use ($mode, $body, $during, $outgoing, $origin): Response {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('/api/v1/client/fixture-client/quotes', $path);
            $this->assertSame('dynamic_payout_reference_quote', $operation);
            $this->assertSame('at_conversion_time', $payload['executionType']);
            $this->assertSame('immediate', $payload['conversionSchedule']);
            $this->assertSame($this->owner->id, $user->id);
            $this->assertSame(ExactProviderJson::decode($dynamicReferenceQuote->json()),
                ExactProviderJson::decode(json_encode($payload, JSON_THROW_ON_ERROR)));
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
        $this->beneficiary->update(['raw_data' => ['nium' => ['payoutMethod' => $this->beneficiary->payout_method,
            ...($this->detailsBinding ? ['beneficiaryBankAccountType' => 'Savings'] : [])]],
            ...($this->detailsBinding ? ['account_number' => '0012345678', 'bank_name' => 'Synthetic Bank', 'swift_bic' => 'TESTSGSGXXX'] : [])]);
        if ($this->detailsBinding && $this->detailsReceivingInputs !== null) $this->beneficiary->update($this->detailsReceivingInputs);
        $this->exchange->apiRequestLog->update(['request_body' => ['payout_method' => $this->beneficiary->payout_method]]);
        $request = json_encode(app(NiumBeneficiaryService::class)->receivingAccountBinding($this->beneficiary), JSON_THROW_ON_ERROR);
        $response = json_encode(['beneficiaryHashId' => $this->beneficiary->external_beneficiary_id,
            ...($this->detailsBinding ? [] : ['customerHashId' => $this->account->external_customer_id])], JSON_THROW_ON_ERROR);
        $this->exchange->update(['raw_request_body' => $request, 'raw_response_body' => $response,
            'request_body_sha256' => hash('sha256', $request), 'response_body_sha256' => hash('sha256', $response)]);
    }

    private function executionEvidence(?\Closure $corridors = null, string|\Closure|null $schemaBody = null, ?\Closure $schemaOutgoing = null): void
    {
        $this->nium->shouldReceive('get')->withArgs(static fn ($path, $query = [], $user = null, $operation = null): bool =>
            $operation === 'dynamic_customer_corridors')->andReturnUsing(function ($path, $query, $user, $operation,
                $reference, $beforeDispatch) use ($corridors): Response {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertSame('/api/v1/client/fixture-client/customer/fixture-customer/supportedCorridors', $path);
                $this->assertSame($this->owner->id, $user->id);
                $beforeDispatch(new Request(new PsrRequest('GET', rtrim((string) config('services.nium.base_url'), '/').$path)));
                return $this->response($this->corridorWithdrawn ? '{"data":[]}' : ($corridors === null
                    ? json_encode(['data' => [['destination_country' => [$this->beneficiary->country_code],
                        'destination_currency' => $this->beneficiary->currency,
                        'payout_method' => $this->beneficiary->payout_method, 'routing_code_type' => 'synthetic-route']]], JSON_THROW_ON_ERROR)
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
                $dialect = config('services.nium.base_url') === 'https://api.spend.nium.com'
                    ? 'https://json-schema.org/draft/2020-12/schema' : 'http://json-schema.org/draft-07/schema#';
                return $this->response(($schemaBody instanceof \Closure ? $schemaBody() : $schemaBody) ?? '{"$schema":"'.$dialect.'","type":"object",'
                    // Hypothetical both-mode requirements; not retained Production evidence.
                    .'"required":["beneficiary.id"],"allOf":[{"if":{"required":["synthetic.flag"],"properties":{"synthetic.flag":{"const":true}}},'
                    .'"then":{"required":["synthetic.conditionalField"]}},'
                    .'{"if":{"required":["payout.sourceAmount"]},"then":{"required":["payout.sourceAmount","payout.sourceCurrency"]}},'
                    .'{"if":{"required":["payout.destinationAmount"]},"then":{"required":["payout.destinationAmount","payout.sourceCurrency"]}}],'
                    .'"properties":{"payout.sourceAmount":{"type":"number","maximumDecimals":"2"},'
                    .'"payout.destinationAmount":{"type":"number","maximumDecimals":"'.($this->beneficiary->currency === 'JPY' ? '0' : '2').'"}}}');
            });
    }

    /** Synthetic schema using the observed shape, never a copy of Production required fields. */
    private function destinationSchemaBody(array $constraints = ['minimum' => 1]): string
    {
        return json_encode(['$schema' => config('services.nium.base_url') === 'https://api.spend.nium.com'
            ? 'https://json-schema.org/draft/2020-12/schema' : 'http://json-schema.org/draft-07/schema#',
            'type' => 'object', 'required' => ['payout.destinationAmount', 'payout.sourceCurrency', 'beneficiary.id', 'purposeCode', 'sourceOfFunds'],
            'properties' => ['payout.destinationAmount' => ['$id' => '/properties/payout.destinationAmount',
                'type' => 'number', 'title' => 'Synthetic destination amount', 'description' => 'Synthetic numeric constraints',
                'maximumDecimals' => in_array($this->beneficiary->currency, ['JPY', 'KRW'], true) ? 0 : 2, ...$constraints]]], JSON_THROW_ON_ERROR);
    }

    private function readyReview(string $mode = 'SOURCE_AMOUNT', bool $checkOptions = false): array
    {
        // Real accepted customer authorization; only provider HTTP is synthetic.
        $this->fullRegistration();
        $this->customerListing();
        $this->executionEvidence();
        $this->providerQuote($mode);
        if ($checkOptions) {
            $options = $this->getJson($this->path('/options?beneficiary_id='.$this->beneficiary->id))->assertOk();
            $this->assertSame([], $options->json('reason_codes'), 'Options refusal: '.$options->getContent());
            $options->assertJsonPath('dynamic_available', true)->assertJsonPath('customer_entitlement', 'VERIFIED')
                ->assertJsonPath('executable_amount_modes', [])->assertJsonPath('reason_codes', []);
        }
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
        $this->assertSame(['currency' => 'USD', 'amount' => '20'], $review['contracted_nium_transaction_fee']);
        $this->assertSame($quote['contracted_nium_transaction_fee'], $review['contracted_nium_transaction_fee']);
        $this->assertSame('NIUM', $review['customer_pricing']['fee_owner']);
        $this->assertSame('NIUM', $review['customer_pricing']['fx_margin_owner']);
        $this->assertSame('UNVERIFIED', $review['customer_pricing']['fee_collection']);
        $this->assertSame('NOT_RECOGNIZED', $review['customer_pricing']['origin_revenue']);
        $this->assertArrayNotHasKey('origin_service_fee', $quote);
        $this->assertArrayNotHasKey('origin_service_fee', $review);
        $this->assertSame($quote['provider_quote_id'], $review['provider_quote_id']);
        $this->assertSame($quote['nium_quoted_fx_rate'], $review['nium_quoted_fx_rate']);
        $this->assertSame('20', $review['customer_pricing']['contracted_nium_transaction_fee_usd']);
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
            'altered Nium contractual fee' => ['contracted_nium_transaction_fee', ['currency' => 'USD', 'amount' => '1']],
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
        $this->assertSame(0, $this->walletReads);
        $this->assertArrayNotHasKey('dynamic_authenticated_availability', $this->wallet->fresh()->raw_data);
        $id = $this->postJson($this->path('/quotes'), $this->input($mode))->assertCreated()->json('dynamic_quote_id');
        $this->assertSame(1, $this->walletReads);
        $stored = NiumDynamicPayoutQuote::query()->findOrFail($id);
        $this->assertSame('2000.00000000', app(DynamicPayoutWalletSnapshot::class)
            ->current($this->owner, $this->wallet->fresh(), $this->account));
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

    public static function excludedDynamicDestinations(): array { return [['USD']]; }

    #[DataProvider('excludedDynamicDestinations')]
    public function test_dynamic_fx_quote_still_refuses_same_currency_usd(string $currency): void
    {
        $this->beneficiary->currency = $currency;
        $this->nium->shouldNotReceive('get');
        $this->nium->shouldNotReceive('post');
        $this->assertQuoteFailure('dynamic_quote_local_eligibility_changed', fn () => $this->createReference());
        $this->noAdmission();
    }

    public function test_sgd_listing_without_full_registration_evidence_cannot_enable_dynamic(): void
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

    /** Reuse the real authenticated GET projector; no new RFI marker or case is fabricated. */
    private function authenticatedAccountWithoutRfi(): void
    {
        $this->account->update(['rfi_status' => null]);
        $updates = app(\App\Services\Nium\NiumAuthenticatedStateProjector::class)->accountUpdates($this->account, [
            'customerHashId' => $this->account->external_customer_id,
            'walletHashId' => $this->account->external_account_id, 'status' => 'clear', 'subStatus' => null,
        ], 'nium_v5_customer_get_response');
        $this->account->update($updates);
    }

    /** Produce the mixed history through the same writer used by customer reconciliation. */
    private function reconciledCustomerRfiHistory(bool $verifiedNotification = false,
        string $template = 'CUSTOMER_STATUS_WEBHOOK'): void
    {
        for ($index = 0; $index < 4; $index++) {
            NiumRfiCase::query()->create(['provider_id' => $this->account->provider_id,
                'user_provider_account_id' => $this->account->id, 'scope' => 'customer', 'origin' => 'webhook',
                'provider_reference_fingerprint' => hash('sha256', 'historical-webhook-'.$index),
                'status' => 'provisional', 'evidence' => ['status' => 'pending', 'subStatus' => 'rfi_requested']]);
        }
        $this->account->update(['provider_status' => 'pending', 'provider_sub_status' => 'under_review',
            'rfi_status' => 'responded']);
        $rfis = [];
        for ($index = 0; $index < 7; $index++) {
            $rfis[] = ['rfiHashId' => 'fixture-rfi-'.$index, 'templateId' => 'fixture-template-'.$index,
                'template' => [], 'status' => 'RFI_RESPONDED'];
        }
        $workflow = app(\App\Services\Nium\NiumRfiWorkflowService::class);
        $workflow->reconcileCustomerEvidence($this->account->fresh(), $rfis);
        $this->travelTo(now()->addSecond());
        $this->account->refresh();
        $updates = app(\App\Services\Nium\NiumAuthenticatedStateProjector::class)->accountUpdates($this->account, [
            'customerHashId' => $this->account->external_customer_id,
            'walletHashId' => $this->account->external_account_id, 'status' => 'clear', 'subStatus' => null,
        ], 'nium_v5_customer_get_response');
        $this->account->update($updates);
        if ($verifiedNotification) {
            // Match processCustomerLifecyclePayload(): GET first, verified details
            // second, RFI reconciliation last. Metadata may be recorded seconds later.
            $this->travelTo(now()->addSeconds(2));
            $this->account = app(\App\Services\Nium\NiumProviderAccountStateService::class)->recordVerifiedNotificationDetails(
                $this->account, ['template' => $template, 'status' => 'clear', 'subStatus' => null],
                'nium_webhook_notification:'.strtolower($template));
        }
        $workflow->reconcileCustomerEvidence($this->account);
        $this->account->refresh();
    }

    public static function historicalRfiCorridors(): array
    {
        $cases = [];
        foreach ([['HK', 'HKD'], ['SG', 'SGD'], ['GB', 'GBP'], ['DK', 'EUR'], ['DE', 'EUR'],
            ['JP', 'JPY'], ['TH', 'THB'], ['KR', 'KRW'], ['CH', 'EUR']] as [$country, $currency]) {
            foreach ([false, true] as $verifiedNotification) {
                $cases[$country.' '.$currency.($verifiedNotification ? ' verified notification' : ' GET')] = [
                    $country, $currency, $verifiedNotification];
            }
        }
        return $cases;
    }

    public static function verifiedLifecycleTemplates(): array
    {
        return [['CUSTOMER_STATUS_WEBHOOK'], ['CUSTOMER_ENTITY_KYC_STATUS'], ['CUSTOMER_COMPLIANCE_STATUS'],
            ['CUSTOMER_ODD_STATUS_WEBHOOK'], ['CARD_CUSTOMER_REGISTRATION_WEBHOOK']];
    }

    #[DataProvider('verifiedLifecycleTemplates')]
    public function test_verified_notification_details_preserve_clearance_without_rewriting_rfi(string $template): void
    {
        $this->reconciledCustomerRfiHistory(verifiedNotification: true, template: $template);
        $before = $this->account->fresh()->getAttributes();
        $cases = NiumRfiCase::query()->orderBy('id')->get()->toArray();
        $this->assertSame('RESOLVED_RFI', DynamicTransferConfirmationService::rfiState($this->account->fresh()));
        $this->assertTrue(DynamicTransferConfirmationService::permitsRfi($this->account->fresh()));
        $this->assertSame($before, $this->account->fresh()->getAttributes());
        $this->assertSame($cases, NiumRfiCase::query()->orderBy('id')->get()->toArray());
        $this->noAdmission();
    }

    public function test_notification_source_cannot_replace_missing_authoritative_reconciliation(): void
    {
        $this->reconciledCustomerRfiHistory();
        $this->account->update(['provider_status_updated_at' => null, 'reconciled_at' => null]);
        $this->account = app(\App\Services\Nium\NiumProviderAccountStateService::class)->recordVerifiedNotificationDetails(
            $this->account, ['template' => 'CUSTOMER_STATUS_WEBHOOK', 'status' => 'clear'],
            'nium_webhook_notification:customer_status_webhook');
        $this->assertSame('UNKNOWN', DynamicTransferConfirmationService::rfiState($this->account->fresh()));
        $this->assertFalse(DynamicTransferConfirmationService::permitsRfi($this->account->fresh()));
        $this->noAdmission();
    }

    public static function webhookReconciliationFailures(): array
    {
        return ['pending reconciliation' => [false], 'failed reconciliation' => [true]];
    }

    #[DataProvider('webhookReconciliationFailures')]
    public function test_restrictive_notification_invalidates_previous_rfi_clearance(bool $failed): void
    {
        $this->reconciledCustomerRfiHistory(verifiedNotification: true);
        $state = app(\App\Services\Nium\NiumProviderAccountStateService::class);
        $source = 'nium_webhook_notification:customer_status_webhook';
        $this->account = $state->applyRestrictiveNotification($this->account,
            ['status' => 'pending', 'subStatus' => 'rfi_requested'], $source);
        if ($failed) $this->account = $state->markReconciliationFailure($this->account, 'authoritative_get_customer_failed', $source);
        $this->assertSame($failed ? 'failed' : 'pending', $this->account->reconciliation_status);
        $this->assertSame('OPEN_RFI', DynamicTransferConfirmationService::rfiState($this->account->fresh()));
        $this->assertFalse(DynamicTransferConfirmationService::permitsRfi($this->account->fresh()));
        $this->noAdmission();
    }

    #[DataProvider('historicalRfiCorridors')]
    public function test_reconciled_mixed_rfi_history_allows_dynamic_options_through_single_dispatch(
        string $country, string $currency, bool $verifiedNotification): void
    {
        $this->beneficiary->update(['country_code' => $country, 'currency' => $currency]);
        $this->reconciledCustomerRfiHistory($verifiedNotification);
        $this->assertSame($verifiedNotification ? 'nium_webhook_notification:customer_status_webhook' : 'nium_v5_customer_get_response',
            $this->account->metadata['nium_last_state_source']);
        $this->assertSame(7, NiumRfiCase::query()->where('status', 'resolved_authoritative_clear')->count());
        $this->assertSame(4, NiumRfiCase::query()->where('status', 'historical_webhook_evidence')->count());
        $this->assertSame('RESOLVED_RFI', DynamicTransferConfirmationService::rfiState($this->account->fresh()));
        $beforeAccount = $this->account->fresh()->getAttributes();
        $beforeCases = NiumRfiCase::query()->orderBy('id')->get()->toArray();
        $review = $this->readyReview(checkOptions: true);
        $request = $this->submitReady($review);
        $id = $this->postJson($this->path(''), $request)->assertCreated()->json('id');
        $this->postJson($this->path(''), $request)->assertOk()->assertJsonPath('id', $id);
        $wire = new DynamicPayoutTransportStub;
        $this->app->instance(DynamicPayoutTransport::class, $wire);
        $outbox = NiumDynamicPayoutOutbox::query()->sole();
        $this->travelTo(now()->addSeconds(31));
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        $this->assertSame(1, $wire->submissions);
        $this->assertSame(1, $outbox->fresh()->attempts);
        $record = app(NiumDynamicReviewService::class)->load($review['accepted_fingerprint']);
        $this->assertSame($record['evidence']['instruction_validation']['request_json'], $wire->bodies[0]);
        $this->assertSame(1, NiumDynamicReservation::query()->count());
        $this->assertSame('1000.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame($beforeAccount, $this->account->fresh()->getAttributes());
        $this->assertSame($beforeCases, NiumRfiCase::query()->orderBy('id')->get()->toArray());
    }

    #[DataProvider('modes')]
    public function test_authenticated_clear_customer_without_rfi_history_can_complete_dynamic_admission(string $mode): void
    {
        $this->owner->kycProfile()->update(['status' => 'verified', 'applicant_type' => 'business']);
        $this->beneficiary->update(['country_code' => 'SG', 'currency' => 'SGD']);
        $this->authenticatedAccountWithoutRfi();
        $before = $this->account->fresh()->getAttributes();
        $this->assertSame('NO_RFI', DynamicTransferConfirmationService::rfiState($this->account->fresh()));
        $review = $this->readyReview($mode, checkOptions: true);
        $id = $this->postJson($this->path(''), $this->submitReady($review))->assertCreated()->json('id');
        $this->postJson($this->path(''), $this->submitReady($review))->assertOk()->assertJsonPath('id', $id);
        $wire = new DynamicPayoutTransportStub;
        $this->app->instance(DynamicPayoutTransport::class, $wire);
        $outbox = NiumDynamicPayoutOutbox::query()->sole();
        $this->travelTo(now()->addSeconds(31));
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        $this->assertSame(1, $wire->submissions);
        $this->assertSame(1, $outbox->fresh()->attempts);
        $record = app(NiumDynamicReviewService::class)->load($review['accepted_fingerprint']);
        $this->assertSame($record['evidence']['instruction_validation']['request_json'], $wire->bodies[0]);
        $this->assertNull($this->account->fresh()->rfi_status);
        $this->assertSame($before, $this->account->fresh()->getAttributes());
        $this->assertSame(0, NiumRfiCase::query()->count());
        $this->assertSame('1000.00000000', $this->wallet->fresh()->reserved_balance);
    }

    public static function dynamicRfiEvidenceStates(): array
    {
        return [
            'no history with GET evidence' => [null, null, null, true, 'NO_RFI'],
            'explicit not required' => ['not_required', null, null, false, 'NO_RFI'],
            'absence without evidence' => [null, null, null, false, 'UNKNOWN'],
            'explicit unknown despite GET' => ['unknown', null, null, true, 'UNKNOWN'],
            'empty summary despite GET' => ['', null, null, true, 'UNKNOWN'],
            'unsupported pending summary' => ['pending', null, null, true, 'UNKNOWN'],
            'requested without case' => ['requested', null, null, true, 'OPEN_RFI'],
            'responded without resolution' => ['responded', null, null, true, 'OPEN_RFI'],
            'cleared without mandatory case' => ['cleared', null, null, false, 'RESOLVED_RFI'],
            'legacy clear summary' => ['clear', null, null, false, 'RESOLVED_RFI'],
            'legacy closed case' => ['closed', 'closed', null, false, 'RESOLVED_RFI'],
            'resolved authoritative case' => ['cleared', 'resolved_authoritative_clear', 'past', false, 'RESOLVED_RFI'],
            'resolution missing timestamp' => ['cleared', 'resolved_authoritative_clear', null, true, 'UNKNOWN'],
            'resolution future timestamp' => ['cleared', 'resolved_authoritative_clear', 'future', true, 'UNKNOWN'],
            'resolution contradictory summary' => ['unknown', 'resolved_authoritative_clear', 'past', true, 'UNKNOWN'],
            'null summary with history' => [null, 'closed', 'past', true, 'UNKNOWN'],
            'historical webhook covered by authenticated clear' => ['cleared', 'historical_webhook_evidence', 'past', true, 'RESOLVED_RFI'],
            'historical webhook alone is not resolution' => ['cleared', 'historical_webhook_evidence', 'past', false, 'UNKNOWN'],
            'unknown case' => ['cleared', 'unknown', 'past', true, 'UNKNOWN'],
            'open case overrides clear summary' => ['cleared', 'requested', 'past', true, 'OPEN_RFI'],
            'provisional case overrides no RFI' => [null, 'provisional', null, true, 'OPEN_RFI'],
            'responded case still open' => ['cleared', 'responded_under_review', 'past', true, 'OPEN_RFI'],
            'rejected authoritative case blocks' => ['cleared', 'rejected_authoritative', 'past', true, 'OPEN_RFI'],
        ];
    }

    #[DataProvider('dynamicRfiEvidenceStates')]
    public function test_dynamic_rfi_state_uses_evidence_without_writing_a_status(
        ?string $summary, ?string $caseStatus, ?string $resolution, bool $authenticated, string $expected): void
    {
        if ($authenticated) $this->authenticatedAccountWithoutRfi();
        $this->account->update(['rfi_status' => $summary]);
        if ($caseStatus !== null) {
            NiumRfiCase::query()->create(['provider_id' => $this->account->provider_id,
                'user_provider_account_id' => $this->account->id, 'scope' => 'customer',
                'provider_reference_fingerprint' => str_repeat('b', 64), 'status' => $caseStatus,
                'reconciled_at' => $resolution === null ? null : ($resolution === 'future' ? now()->addMinute() : now()),
                'evidence' => []]);
        }
        $beforeAccount = $this->account->fresh()->getAttributes();
        $beforeCases = NiumRfiCase::query()->orderBy('id')->get()->toArray();
        $this->assertSame($expected, DynamicTransferConfirmationService::rfiState($this->account->fresh()));
        $this->assertSame(in_array($expected, ['NO_RFI', 'RESOLVED_RFI'], true),
            DynamicTransferConfirmationService::permitsRfi($this->account->fresh()));
        $this->assertSame($beforeAccount, $this->account->fresh()->getAttributes());
        $this->assertSame($beforeCases, NiumRfiCase::query()->orderBy('id')->get()->toArray());
        $this->noAdmission();
    }

    public static function unverifiedNoRfiObservations(): array
    {
        return [
            'missing reconciliation time' => [['reconciled_at' => null], []],
            'future provider observation' => [['provider_status_updated_at' => '2030-01-01T00:11:00Z'], []],
            'pending account reconciliation' => [['reconciliation_status' => 'pending'], []],
            'reconciliation error' => [['reconciliation_error' => 'failed'], []],
            'customer binding unavailable' => [['customer_id_verified_at' => null], []],
            'wallet binding unavailable' => [['wallet_id_verified_at' => null], []],
            'unknown source' => [[], ['nium_last_state_source' => 'unknown']],
            'webhook alone' => [[], ['nium_last_state_source' => 'nium_webhook_notification:customer_status_webhook']],
            'missing timestamp' => [[], ['nium_last_state_at' => null]],
            'malformed timestamp' => [[], ['nium_last_state_at' => 'invalid']],
            'contradictory observation time' => [[], ['nium_last_state_at' => '2030-01-01T00:09:00Z']],
        ];
    }

    #[DataProvider('unverifiedNoRfiObservations')]
    public function test_no_rfi_absence_cannot_replace_an_unverified_observation(array $changes, array $metadata): void
    {
        $this->authenticatedAccountWithoutRfi();
        $this->account->update([...$changes, 'metadata' => [...$this->account->metadata, ...$metadata]]);
        $this->assertSame('UNKNOWN', DynamicTransferConfirmationService::rfiState($this->account->fresh()));
        $this->assertFalse(DynamicTransferConfirmationService::permitsRfi($this->account->fresh()));
        $this->noAdmission();
    }

    public static function invalidHistoricalRfiEvidence(): array
    {
        $cases = [];
        foreach (self::unverifiedNoRfiObservations() as $name => [$changes, $metadata]) {
            // A known notification source now preserves an existing reconciled
            // clearance. The NO_RFI absence policy remains unchanged.
            if ($name === 'webhook alone') continue;
            $cases[$name] = [$changes, [], $metadata, null, 'UNKNOWN'];
        }
        $cases += [
            'history missing reconciliation' => [[], ['reconciled_at' => null], [], null, 'UNKNOWN'],
            'history future reconciliation' => [[], ['reconciled_at' => 'future'], [], null, 'UNKNOWN'],
            'history newer than CLEAR' => [[], ['reconciled_at' => 'newer_than_clear'], [], null, 'UNKNOWN'],
            'history with wrong origin' => [[], ['origin' => 'authoritative_fetch'], [], null, 'UNKNOWN'],
            'history with transaction scope' => [[], ['scope' => 'transaction'], [], null, 'UNKNOWN'],
            'history with null summary' => [['rfi_status' => null], [], [], null, 'UNKNOWN'],
            'history with unknown summary' => [['rfi_status' => 'unknown'], [], [], null, 'UNKNOWN'],
            'history with legacy clear summary' => [['rfi_status' => 'clear'], [], [], null, 'UNKNOWN'],
            'contradictory provider status' => [['provider_status' => 'pending'], [], [], null, 'UNKNOWN'],
            'contradictory provider substatus' => [['provider_sub_status' => 'rfi_requested'], [], [], null, 'UNKNOWN'],
            'failed reconciliation' => [['reconciliation_status' => 'failed'], [], [], null, 'UNKNOWN'],
            'new reconciliation supersedes CLEAR' => [['reconciliation_requested_at' => '2030-01-01T00:10:02Z'], [], [], null, 'UNKNOWN'],
            'security conflict' => [['security_conflict_reason' => 'verified_identifier_conflict'], [], [], null, 'UNKNOWN'],
            'unrecognized webhook source' => [[], [], ['nium_last_state_source' => 'nium_webhook_notification:unverified'], null, 'UNKNOWN'],
            'future notification timestamp' => [[], [], ['nium_last_state_at' => '2030-01-01T00:11:00Z'], null, 'UNKNOWN'],
            'missing provider observation time' => [['provider_status_updated_at' => null], [], [], null, 'UNKNOWN'],
            'provider observation differs from reconciliation' => [['provider_status_updated_at' => '2030-01-01T00:10:02Z'], [], [], null, 'UNKNOWN'],
            'wallet verified after authoritative reconciliation' => [['wallet_id_verified_at' => '2030-01-01T00:10:02Z'], [], [], null, 'UNKNOWN'],
        ];
        foreach (['provisional', 'requested', 'responded_under_review', 'rejected_authoritative', 'unknown'] as $status) {
            $cases['new '.$status.' case'] = [[], [], [], $status, $status === 'unknown' ? 'UNKNOWN' : 'OPEN_RFI'];
        }
        return $cases;
    }

    #[DataProvider('invalidHistoricalRfiEvidence')]
    public function test_history_cannot_override_missing_invalidated_or_contradictory_rfi_evidence(
        array $accountChanges, array $caseChanges, array $metadataChanges, ?string $newCaseStatus, string $expected): void
    {
        $this->reconciledCustomerRfiHistory(verifiedNotification: true);
        $review = $this->readyReview();
        $historical = NiumRfiCase::query()->where('status', 'historical_webhook_evidence')->orderBy('id')->firstOrFail();
        if (($caseChanges['reconciled_at'] ?? null) === 'future') $caseChanges['reconciled_at'] = now()->addMinute();
        if (($caseChanges['reconciled_at'] ?? null) === 'newer_than_clear') {
            $this->travelTo(now()->addSecond());
            $caseChanges['reconciled_at'] = now();
        }
        if ($caseChanges !== []) $historical->update($caseChanges);
        if ($accountChanges !== [] || $metadataChanges !== []) {
            $this->account->update([...$accountChanges, 'metadata' => [...$this->account->metadata, ...$metadataChanges]]);
        }
        if ($newCaseStatus !== null) NiumRfiCase::query()->create([
            'provider_id' => $this->account->provider_id, 'user_provider_account_id' => $this->account->id,
            'scope' => 'customer', 'origin' => 'authoritative_fetch',
            'provider_reference_fingerprint' => hash('sha256', 'new-rfi-after-clear'),
            'status' => $newCaseStatus, 'evidence' => [], 'reconciled_at' => now(),
        ]);
        $beforeAccount = $this->account->fresh()->getAttributes();
        $beforeCases = NiumRfiCase::query()->orderBy('id')->get()->toArray();

        $this->assertSame($expected, DynamicTransferConfirmationService::rfiState($this->account->fresh()));
        $this->assertFalse(DynamicTransferConfirmationService::permitsRfi($this->account->fresh()));
        $this->getJson($this->path('/options?beneficiary_id='.$this->beneficiary->id))->assertOk()
            ->assertJsonPath('dynamic_available', false)
            ->assertJsonPath('reason_codes.0', fn (string $reason): bool => in_array($reason,
                ['dynamic_quote_local_eligibility_changed', 'dynamic_provider_account_ineligible'], true));
        $this->postJson($this->path('/reviews'), ['beneficiary_id' => $this->beneficiary->id,
            'dynamic_quote_id' => NiumDynamicPayoutQuote::query()->sole()->id,
            'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']])
            ->assertStatus(503)->assertJsonMissingPath('accepted_fingerprint')
            ->assertJsonPath('reason_codes.0', fn (string $reason): bool => in_array($reason,
                ['dynamic_quote_local_eligibility_changed', 'dynamic_provider_account_ineligible'], true));
        try {
            app(DynamicTransferConfirmationService::class)->confirm($this->owner, $this->account->id,
                $this->beneficiary->id, $this->wallet->id, 'invalidated-rfi-history', $review['accepted_fingerprint']);
            $this->fail('Invalidated RFI evidence must block confirmation of the previously accepted Review.');
        } catch (RuntimeException $exception) {
            $this->assertContains($exception->getMessage(), ['dynamic_compliance_hold_or_evidence_unavailable',
                'dynamic_customer_account_beneficiary_or_wallet_ineligible']);
        }
        $this->assertSame($beforeAccount, $this->account->fresh()->getAttributes());
        $this->assertSame($beforeCases, NiumRfiCase::query()->orderBy('id')->get()->toArray());
        $this->noAdmission();
    }

    public function test_open_rfi_after_no_rfi_review_blocks_review_and_confirmation(): void
    {
        $this->authenticatedAccountWithoutRfi();
        $review = $this->readyReview();
        NiumRfiCase::query()->create(['provider_id' => $this->account->provider_id,
            'user_provider_account_id' => $this->account->id, 'scope' => 'customer',
            'provider_reference_fingerprint' => str_repeat('b', 64), 'status' => 'requested', 'evidence' => []]);
        $this->postJson($this->path('/reviews'), ['beneficiary_id' => $this->beneficiary->id,
            'dynamic_quote_id' => NiumDynamicPayoutQuote::query()->sole()->id,
            'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']])
            ->assertStatus(503)->assertJsonMissingPath('accepted_fingerprint')
            ->assertJsonPath('reason_codes', ['dynamic_quote_local_eligibility_changed']);
        $this->assertQuoteFailure('dynamic_compliance_hold_or_evidence_unavailable', fn () =>
            app(DynamicTransferConfirmationService::class)->confirm($this->owner, $this->account->id,
                $this->beneficiary->id, $this->wallet->id, 'new-rfi-after-review', $review['accepted_fingerprint']));
        $this->assertNull($this->account->fresh()->rfi_status);
        $this->noAdmission();
    }

    public static function rfiArrivalStages(): array
    {
        return ['before claim without history' => [false, false], 'before wire without history' => [true, false],
            'before claim with reconciled history' => [false, true], 'before wire with reconciled history' => [true, true]];
    }

    #[DataProvider('rfiArrivalStages')]
    public function test_open_rfi_after_confirmation_blocks_dispatch_without_a_wire_attempt(bool $beforeWire, bool $history): void
    {
        if ($history) $this->reconciledCustomerRfiHistory(verifiedNotification: true);
        else $this->authenticatedAccountWithoutRfi();
        $review = $this->readyReview();
        $this->postJson($this->path(''), $this->submitReady($review))->assertCreated();
        $wire = new DynamicPayoutTransportStub;
        $openRfi = function (): void {
            NiumRfiCase::query()->create(['provider_id' => $this->account->provider_id,
                'user_provider_account_id' => $this->account->id, 'scope' => 'customer',
                'provider_reference_fingerprint' => str_repeat('b', 64), 'status' => 'requested', 'evidence' => []]);
        };
        if ($beforeWire) $wire->beforeWire = $openRfi;
        else $openRfi();
        $this->app->instance(DynamicPayoutTransport::class, $wire);
        $outbox = NiumDynamicPayoutOutbox::query()->sole();

        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);

        $this->assertSame(0, $wire->submissions);
        $this->assertSame([], $wire->bodies);
        $this->assertSame(0, $outbox->fresh()->attempts);
        $this->assertNull($outbox->fresh()->submission_started_at);
        $this->assertSame('exception', $outbox->fresh()->state);
        $this->assertSame($beforeWire ? 'dynamic_submission_not_started'
            : 'dynamic_compliance_hold_or_evidence_unavailable', $outbox->fresh()->exception_code);
        $this->assertSame($history ? 'cleared' : null, $this->account->fresh()->rfi_status);
        $this->assertSame(1, NiumRfiCase::query()->where('status', 'requested')->count());
        $this->assertSame($history ? 12 : 1, NiumRfiCase::query()->count());
        $this->assertSame('held', NiumDynamicReservation::query()->sole()->state);
        $this->assertSame('1000.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'debit')->count());
    }

    public static function acceptedDynamicKycProfiles(): array
    {
        return [
            'approved individual' => ['approved', 'individual'],
            'verified individual' => ['verified', 'individual'],
            'approved business' => ['approved', 'business'],
            'verified business' => ['verified', 'business'],
        ];
    }

    #[DataProvider('acceptedDynamicKycProfiles')]
    public function test_accepted_kyc_profile_reaches_sgd_quote_review_confirmation_and_dispatch(string $status, string $type): void
    {
        $this->owner->kycProfile()->update(['status' => $status, 'applicant_type' => $type]);
        $this->beneficiary->update(['country_code' => 'SG', 'currency' => 'SGD']);
        $review = $this->readyReview(checkOptions: true);
        $request = $this->submitReady($review);
        $id = $this->postJson($this->path(''), $request)->assertCreated()->json('id');
        $this->postJson($this->path(''), $request)->assertOk()->assertJsonPath('id', $id);
        $wire = new DynamicPayoutTransportStub;
        $this->app->instance(DynamicPayoutTransport::class, $wire);
        $outbox = NiumDynamicPayoutOutbox::query()->sole();
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        $this->assertSame(1, $wire->submissions);
        $this->assertSame(1, $outbox->fresh()->attempts);
        $this->assertSame($status, $this->owner->kycProfile()->sole()->status);
        $this->assertSame($type, $this->owner->kycProfile()->sole()->applicant_type);
        $this->assertSame('clear', $this->account->fresh()->provider_status);
        $this->assertSame('1000.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame(1, NiumDynamicReservation::query()->count());
    }

    public static function unacceptedDynamicKycProfiles(): array
    {
        return [['pending'], ['rejected']];
    }

    #[DataProvider('unacceptedDynamicKycProfiles')]
    public function test_pending_or_rejected_profile_blocks_options_and_quote_despite_clear_account(string $status): void
    {
        $this->owner->kycProfile()->update(['status' => $status]);
        $this->nium->shouldNotReceive('get');
        $this->nium->shouldNotReceive('post');
        $this->getJson($this->path('/options?beneficiary_id='.$this->beneficiary->id))->assertOk()
            ->assertJsonPath('dynamic_available', false)
            ->assertJsonPath('reason_codes', ['dynamic_quote_local_eligibility_changed']);
        $this->postJson($this->path('/quotes'), $this->input())->assertStatus(503)
            ->assertJsonPath('reason_codes', ['dynamic_quote_local_eligibility_changed']);
        $this->assertSame($status, $this->owner->kycProfile()->sole()->status);
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
    }

    #[DataProvider('unacceptedDynamicKycProfiles')]
    public function test_profile_revoked_after_quote_blocks_review_and_confirmation(string $status): void
    {
        $this->owner->kycProfile()->update(['status' => 'verified']);
        $review = $this->readyReview();
        $this->owner->kycProfile()->update(['status' => $status]);
        $this->postJson($this->path('/reviews'), ['beneficiary_id' => $this->beneficiary->id,
            'dynamic_quote_id' => NiumDynamicPayoutQuote::query()->sole()->id,
            'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']])
            ->assertStatus(503)->assertJsonPath('reason_codes', ['dynamic_quote_local_eligibility_changed'])
            ->assertJsonMissingPath('accepted_fingerprint');
        $this->assertQuoteFailure('dynamic_customer_account_beneficiary_or_wallet_ineligible', fn () =>
            app(DynamicTransferConfirmationService::class)->confirm($this->owner, $this->account->id,
                $this->beneficiary->id, $this->wallet->id, 'revoked-kyc-profile', $review['accepted_fingerprint']));
        $this->assertSame($status, $this->owner->kycProfile()->sole()->status);
        $this->noAdmission();
    }

    public function test_verified_business_profile_can_dispatch_without_completing_superseded_pending_aml(): void
    {
        $this->owner->kycProfile()->update(['status' => 'verified', 'applicant_type' => 'business']);
        $this->beneficiary->update(['country_code' => 'SG', 'currency' => 'SGD']);
        $profile = $this->owner->kycProfile()->sole();
        for ($index = 0; $index < 3; $index++) {
            AmlScreening::query()->create(['user_id' => $this->owner->id, 'kyc_profile_id' => $profile->id,
                'subject_type' => 'individual', 'subject_name' => 'Unresolved screening '.$index,
                'status' => 'pending', 'compliance_decision' => 'pending_review', 'superseded_at' => now()]);
        }
        $before = AmlScreening::query()->where('user_id', $this->owner->id)->orderBy('id')->get()->toArray();
        $this->fullRegistration(); $this->customerListing(); $this->executionEvidence(); $this->providerQuote();
        // A valid current clearance exists; superseded jobs need not be completed again.
        $this->getJson($this->path('/options?beneficiary_id='.$this->beneficiary->id))->assertOk()
            ->assertJsonPath('status', 'QUOTE_REFERENCE_ONLY');
        $id = $this->postJson($this->path('/quotes'), $this->input())->assertCreated()->json('dynamic_quote_id');
        $review = $this->postJson($this->path('/reviews'), ['beneficiary_id' => $this->beneficiary->id, 'dynamic_quote_id' => $id,
            'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']])
            ->assertOk()->assertJsonPath('status', 'READY')->json();
        $this->noAdmission();
        $request = $this->submitReady($review);
        $intentId = $this->postJson($this->path(''), $request)->assertCreated()->json('id');
        $this->postJson($this->path(''), $request)->assertOk()->assertJsonPath('id', $intentId);
        $wire = new DynamicPayoutTransportStub;
        $this->app->instance(DynamicPayoutTransport::class, $wire);
        $outbox = NiumDynamicPayoutOutbox::query()->sole();
        $this->travelTo(now()->addSeconds(31));
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        $this->assertSame(1, $wire->submissions);
        $this->assertSame(1, $outbox->fresh()->attempts);
        $record = app(NiumDynamicReviewService::class)->load($review['accepted_fingerprint']);
        $this->assertSame($record['evidence']['instruction_validation']['request_json'], $wire->bodies[0]);
        $this->assertSame(1, NiumDynamicReservation::query()->count());
        $this->assertSame('1000.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame($before, AmlScreening::query()->where('user_id', $this->owner->id)->orderBy('id')->get()->toArray());
        $this->assertSame('verified', $this->owner->kycProfile()->sole()->status);
        $this->assertSame('clear', $this->account->fresh()->provider_status);
    }

    public function test_approved_to_verified_profile_change_does_not_reuse_old_review_consent(): void
    {
        $review = $this->readyReview();
        $this->owner->kycProfile()->update(['status' => 'verified']);
        $this->assertQuoteFailure('dynamic_review_binding_changed', fn () =>
            app(DynamicTransferConfirmationService::class)->confirm($this->owner, $this->account->id,
                $this->beneficiary->id, $this->wallet->id, 'changed-accepted-kyc-profile', $review['accepted_fingerprint']));
        $this->assertSame('verified', $this->owner->kycProfile()->sole()->status);
        $this->noAdmission();
    }

    public static function inapplicableLocalAmlExecutionStates(): array
    {
        $cases = [];
        foreach ([null, 'pending', 'running', 'failed', 'unknown'] as $state) {
            foreach (['SOURCE_AMOUNT', 'DESTINATION_AMOUNT'] as $mode) {
                $cases[($state === null ? 'no rows' : 'superseded '.$state).' '.$mode] = [$state, $mode];
            }
        }
        return $cases;
    }

    #[DataProvider('inapplicableLocalAmlExecutionStates')]
    public function test_dynamic_admission_does_not_require_absent_or_superseded_aml_execution(?string $state, string $mode): void
    {
        $this->owner->kycProfile()->update(['status' => 'verified', 'applicant_type' => 'business']);
        $this->beneficiary->update(['country_code' => 'SG', 'currency' => 'SGD']);
        $this->authenticatedAccountWithoutRfi();
        $screening = AmlScreening::query()->where('user_id', $this->owner->id)->sole();
        if ($state === null) {
            $screening->delete(); // Remove only the synthetic clear fixture, not provider evidence.
        } else {
            // Preserve the current valid clearance and add an obsolete execution record.
            $historical = $screening->replicate();
            $historical->fill(['status' => $state, 'compliance_decision' => 'pending_review',
                'screening_result' => null, 'completed_at' => null, 'superseded_at' => now(),
                'result_summary' => $state === 'failed' ? ['error' => 'provider_failure'] : null])->save();
        }
        $before = AmlScreening::query()->where('user_id', $this->owner->id)->get()->toArray();
        $review = $this->readyReview($mode, checkOptions: true);
        $request = $this->submitReady($review);
        $id = $this->postJson($this->path(''), $request)->assertCreated()->json('id');
        $this->postJson($this->path(''), $request)->assertOk()->assertJsonPath('id', $id);
        $wire = new DynamicPayoutTransportStub;
        $this->app->instance(DynamicPayoutTransport::class, $wire);
        $outbox = NiumDynamicPayoutOutbox::query()->sole();
        $this->travelTo(now()->addSeconds(31));
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        $this->assertSame(1, $wire->submissions);
        $this->assertSame(1, $outbox->fresh()->attempts);
        $record = app(NiumDynamicReviewService::class)->load($review['accepted_fingerprint']);
        $this->assertSame($record['evidence']['instruction_validation']['request_json'], $wire->bodies[0]);
        $this->assertSame(1, NiumDynamicReservation::query()->count());
        $this->assertSame('1000.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame($before, AmlScreening::query()->where('user_id', $this->owner->id)->get()->toArray());
        $this->assertNull($this->account->fresh()->rfi_status);
        $this->assertSame(0, NiumRfiCase::query()->count());
    }

    public static function verifiedProfileRestrictions(): array
    {
        return [
            'account not clear' => ['account', ['provider_status' => 'pending'], 'dynamic_customer_account_beneficiary_or_wallet_ineligible'],
            'wrong wallet binding' => ['wallet', ['external_account_id' => 'foreign-wallet'], 'dynamic_customer_account_beneficiary_or_wallet_ineligible'],
            'changed beneficiary binding' => ['beneficiary', ['account_number' => 'CHANGED'], 'dynamic_quote_binding_changed'],
            'real compliance hold' => ['account', ['compliance_status' => 'hold'], 'dynamic_customer_account_beneficiary_or_wallet_ineligible'],
            'unresolved AML finding' => ['screening', ['status' => 'manual_review', 'compliance_decision' => 'pending_review',
                'screening_result' => 'match'],
                'dynamic_compliance_hold_or_evidence_unavailable'],
        ];
    }

    public static function recordedLocalAmlRestrictions(): array
    {
        $cases = [
            'manual review' => [['status' => 'manual_review'], false],
            'rejected decision' => [['compliance_decision' => 'rejected'], false],
            'unresolved sanctions match' => [[], true],
        ];
        // The same local admission gate protects all nine contracted Dynamic corridors.
        foreach ([['HK', 'HKD'], ['SG', 'SGD'], ['GB', 'GBP'], ['DK', 'EUR'], ['DE', 'EUR'],
            ['JP', 'JPY'], ['TH', 'THB'], ['KR', 'KRW'], ['CH', 'EUR']] as [$country, $currency]) {
            foreach (['pending', 'running', 'failed', 'unknown'] as $state) {
                $cases[$country.' '.$currency.' active '.$state] = [
                    ['status' => $state, 'compliance_decision' => 'pending_review',
                        'screening_result' => null, 'completed_at' => null,
                        'result_summary' => $state === 'failed' ? ['error' => 'provider_failure'] : null],
                    false, $country, $currency,
                ];
            }
        }
        return $cases;
    }

    #[DataProvider('recordedLocalAmlRestrictions')]
    public function test_recorded_aml_restrictions_still_block_review_and_confirmation(
        array $changes, bool $match, string $country = 'GB', string $currency = 'GBP'): void
    {
        $this->owner->kycProfile()->update(['status' => 'verified']);
        $this->beneficiary->update(['country_code' => $country, 'currency' => $currency]);
        $this->authenticatedAccountWithoutRfi();
        $screening = AmlScreening::query()->where('user_id', $this->owner->id)->sole();
        $review = $this->readyReview();
        if (! $match) $screening->update(['status' => 'pending', 'compliance_decision' => 'pending_review',
            'screening_result' => null, 'completed_at' => null, ...$changes]);
        if ($match) $screening->matches()->create(['list_type' => 'sanctions', 'source' => 'fixture',
            'matched_name' => 'Fixture unresolved match', 'status' => 'open']);
        $before = $screening->fresh()->getAttributes();
        $beforeMatches = $screening->matches()->get()->toArray();

        $this->postJson($this->path('/reviews'), ['beneficiary_id' => $this->beneficiary->id,
            'dynamic_quote_id' => NiumDynamicPayoutQuote::query()->sole()->id,
            'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']])
            ->assertStatus(503)->assertJsonPath('reason_codes', ['dynamic_compliance_hold_or_evidence_unavailable'])
            ->assertJsonMissingPath('accepted_fingerprint');
        $this->assertQuoteFailure('dynamic_compliance_hold_or_evidence_unavailable', fn () =>
            app(DynamicTransferConfirmationService::class)->confirm($this->owner, $this->account->id,
                $this->beneficiary->id, $this->wallet->id, 'actual-aml-restriction', $review['accepted_fingerprint']));

        $this->assertSame($before, $screening->fresh()->getAttributes());
        $this->assertSame($beforeMatches, $screening->matches()->get()->toArray());
        $this->noAdmission();
    }

    #[DataProvider('verifiedProfileRestrictions')]
    public function test_verified_profile_does_not_override_account_binding_or_compliance_restrictions(
        string $record, array $changes, string $reason): void
    {
        $this->owner->kycProfile()->update(['status' => 'verified']);
        $review = $this->readyReview();
        $changed = $record === 'screening'
            ? AmlScreening::query()->where('user_id', $this->owner->id)->sole() : $this->{$record};
        $changed->update($changes);
        $before = $changed->fresh()->getAttributes();
        $this->assertQuoteFailure($reason, fn () =>
            app(DynamicTransferConfirmationService::class)->confirm($this->owner, $this->account->id,
                $this->beneficiary->id, $this->wallet->id, 'verified-kyc-restriction', $review['accepted_fingerprint']));
        $this->assertSame($before, $changed->fresh()->getAttributes());
        $this->assertSame('verified', $this->owner->kycProfile()->sole()->status);
        $this->noAdmission();
    }

    public static function dynamicProfilesAndModes(): array
    {
        return [
            'approved source' => ['approved', 'SOURCE_AMOUNT'],
            'approved destination' => ['approved', 'DESTINATION_AMOUNT'],
            'verified source' => ['verified', 'SOURCE_AMOUNT'],
            'verified destination' => ['verified', 'DESTINATION_AMOUNT'],
        ];
    }

    #[DataProvider('dynamicProfilesAndModes')]
    public function test_optional_auxiliary_observations_can_change_between_dynamic_boundaries_without_new_consent(
        string $profileStatus, string $mode): void
    {
        $this->owner->kycProfile()->update(['status' => $profileStatus, 'applicant_type' => 'business']);
        $this->beneficiary->update(['country_code' => 'SG', 'currency' => 'SGD']);
        $this->authenticatedAccountWithoutRfi();
        $this->account->update(['compliance_status' => null, 'odd_status' => 'unknown']);
        // Optional observations can change during the provider validation round trip.
        $this->duringValidation = function (): void {
            $this->account->update(['compliance_status' => null, 'odd_status' => null]);
        };
        $review = $this->readyReview($mode, checkOptions: true);
        $this->assertNull($this->account->fresh()->compliance_status);
        $this->assertNull($this->account->fresh()->odd_status);

        $this->account->update(['compliance_status' => 'unknown', 'odd_status' => 'clear']);
        $request = $this->submitReady($review);
        $id = $this->postJson($this->path(''), $request)->assertCreated()->json('id');
        $this->postJson($this->path(''), $request)->assertOk()->assertJsonPath('id', $id);
        $intent = NiumDynamicIntent::query()->sole();
        $terms = $intent->getAttributes();
        $this->assertSame($review['accepted_fingerprint'], $intent->terms_fingerprint);

        $this->account->update(['compliance_status' => 'approved', 'odd_status' => 'not_required']);
        $this->duringValidation = null;
        $wire = new DynamicPayoutTransportStub;
        $wire->beforeWire = function (): void {
            $this->account->update(['compliance_status' => 'unknown', 'odd_status' => null]);
        };
        $this->app->instance(DynamicPayoutTransport::class, $wire);
        $outbox = NiumDynamicPayoutOutbox::query()->sole();
        $this->travelTo(now()->addSeconds(31));
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);

        $this->assertSame(1, $wire->submissions);
        $this->assertSame(1, $outbox->fresh()->attempts);
        $record = app(NiumDynamicReviewService::class)->load($review['accepted_fingerprint']);
        $this->assertSame($record['evidence']['instruction_validation']['request_json'], $wire->bodies[0]);
        $this->assertSame($terms, $intent->fresh()->getAttributes());
        $this->assertSame($profileStatus, $this->owner->kycProfile()->sole()->status);
        $this->assertSame('unknown', $this->account->fresh()->compliance_status);
        $this->assertNull($this->account->fresh()->odd_status);
        $this->assertNull($this->account->fresh()->rfi_status);
        $this->assertSame('NO_RFI', DynamicTransferConfirmationService::rfiState($this->account->fresh()));
        $this->assertSame(0, NiumRfiCase::query()->count());
        $this->assertSame(1, NiumDynamicIntent::query()->count());
        $this->assertSame(1, NiumDynamicReservation::query()->count());
        $this->assertSame('1000.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'debit')->count());
    }

    #[DataProvider('providerClearWithAccountRestrictions')]
    public function test_review_prepare_keeps_preflight_restrictions_before_provider_validation(array $changes): void
    {
        $this->owner->kycProfile()->update(['status' => 'verified']);
        $this->authenticatedAccountWithoutRfi();
        $this->account->update(['compliance_status' => null, 'odd_status' => 'unknown']);
        $this->readyReview();
        $this->account->update($changes);
        $before = $this->account->fresh()->getAttributes();
        $this->duringValidation = function (): void {
            $this->fail('Review must refuse a current restriction before remittance validation.');
        };

        $this->postJson($this->path('/reviews'), ['beneficiary_id' => $this->beneficiary->id,
            'dynamic_quote_id' => NiumDynamicPayoutQuote::query()->sole()->id,
            'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']])
            ->assertStatus(503)->assertJsonPath('reason_codes', ['dynamic_quote_local_eligibility_changed'])
            ->assertJsonMissingPath('accepted_fingerprint');

        $this->assertSame($before, $this->account->fresh()->getAttributes());
        $this->noAdmission();
    }

    public static function contractedTuplesAndModes(): array
    {
        $cases = [];
        foreach ([['HK', 'HKD'], ['SG', 'SGD'], ['GB', 'GBP'], ['DK', 'EUR'], ['DE', 'EUR'],
            ['JP', 'JPY'], ['TH', 'THB'], ['KR', 'KRW'], ['CH', 'EUR']] as [$country, $currency]) {
            foreach (['SOURCE_AMOUNT', 'DESTINATION_AMOUNT'] as $mode) {
                $cases[$country.' '.$mode] = [$country, $currency, $mode, false];
                if (in_array($country, ['SG', 'GB'], true)) {
                    $cases[$country.' '.$mode.' missing auxiliary observations'] = [$country, $currency, $mode, true];
                }
            }
            $cases[$country.' destination-only schema without allOf'] = [$country, $currency, 'DESTINATION_AMOUNT', false, true];
        }
        return $cases;
    }

    #[DataProvider('contractedTuplesAndModes')]
    public function test_four_field_corridors_exercise_options_review_submit_single_dispatch_and_reconciliation(
        string $country, string $currency, string $mode, bool $missingObservations, bool $destinationOnly = false): void
    {
        // Only HTTP is synthetic; the actual financial authorization and engine are exercised.
        $this->beneficiary->update(['country_code' => $country, 'currency' => $currency]);
        $this->account->update(['rfi_status' => 'cleared']);
        if ($missingObservations) {
            // Account 6/8-like policy: do not invent clearance or bypass scoped evidence.
            $this->account->update(['compliance_status' => null, 'odd_status' => 'unknown']);
        }
        if ($destinationOnly) {
            $this->useQuoteOrigin();
            config(['nium_dynamic_payouts.live_automatic_dispatch_authorized' => true,
                'nium_dynamic_payouts.operator_authorization_reference' => 'synthetic-offline-test',
                'services.nium.transfer_endpoint' => '/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/remittance']);
            $this->fullRegistration(); $this->customerListing();
            $this->executionEvidence(schemaBody: $this->destinationSchemaBody());
            $this->providerQuote($mode);
            $this->getJson($this->path('/options?beneficiary_id='.$this->beneficiary->id))->assertOk()
                ->assertJsonPath('dynamic_available', true)->assertJsonPath('supported_amount_modes', ['DESTINATION_AMOUNT'])
                ->assertJsonPath('currency_scales', ['USD' => 2, $currency => in_array($currency, ['JPY', 'KRW'], true) ? 0 : 2])
                ->assertJsonPath('executable_amount_modes', [])->assertJsonPath('requires_instruction_validation', true);
            $quote = $this->postJson($this->path('/quotes'), $this->input($mode))->assertCreated()->json('dynamic_quote_id');
            $review = $this->postJson($this->path('/reviews'), ['beneficiary_id' => $this->beneficiary->id,
                'dynamic_quote_id' => $quote, 'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']])
                ->assertOk()->assertJsonPath('status', 'READY')->json();
        } else {
            $review = $this->readyReview($mode, checkOptions: true);
        }
        $this->assertSame('LOCAL', $review['payout_rail']);
        $this->assertSame('nium-contracted-local-usd-inclusive-v4', $review['customer_pricing']['version']);
        $this->assertSame('20', $review['customer_pricing']['contracted_nium_transaction_fee_usd']);
        $this->assertSame('INCLUDED_IN_TOTAL_USD_DEBIT', $review['customer_pricing']['fee_treatment']);
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
        $instruction = ExactProviderJson::decode($wire->bodies[0]);
        $this->assertSame('USD', $instruction['payout']['sourceCurrency']);
        $this->assertSame($mode === 'SOURCE_AMOUNT' ? '980' : '784',
            $instruction['payout'][$mode === 'SOURCE_AMOUNT' ? 'sourceAmount' : 'destinationAmount']);
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
        $debit = LedgerEntry::query()->where('entry_type', 'debit')->sole();
        $this->assertSame('-1000.00000000', $debit->amount);
        $this->assertSame('NIUM', $debit->raw_data['fee_classification']['fee_owner']);
        $this->assertFalse($debit->raw_data['origin_revenue_recognized']);
        $this->assertSame(0, LedgerEntry::query()->whereNotIn('entry_type', ['hold', 'debit'])->count());
        $this->assertSame('20', $outbox->fresh()->financial_evidence['fee_usd']);
        $this->assertSame('1000', $outbox->fresh()->financial_evidence['wallet_debit_usd']);
        $this->assertFalse($outbox->fresh()->financial_evidence['origin_revenue_recognized']);
        $this->assertSame('consumed', NiumDynamicReservation::query()->sole()->state);
        $this->assertSame(1, $wire->submissions);
        $this->assertSame(0, Transfer::query()->count()); // No legacy instruction or Admin approval path.
        $this->assertSame(0, FxQuote::query()->count()); // Dynamic quote storage and pricing only.
        if ($missingObservations) {
            $this->assertNull($this->account->fresh()->compliance_status);
            $this->assertSame('unknown', $this->account->fresh()->odd_status);
        }
    }

    public static function detailsCorridors(): array
    {
        // Entirely synthetic schema/receiving examples, not claimed Nium country rules.
        $rows = [
            ['HK', 'HKD', 'HK-ACCOUNT-A01', '^[A-Z0-9-]+$', 'SWIFT', 'TESTHKHHXXX', '^[A-Z0-9]{11}$', null, 'business'],
            ['SG', 'SGD', '0012345678', '^[0-9]{10}$', 'SWIFT', 'TESTSGSGXXX', '^[A-Z0-9]{11}$', 'Saving', 'individual'],
            ['GB', 'GBP', '00012345', '^[0-9]{8}$', 'SORT CODE', '12-34-56', '^[0-9]{2}-[0-9]{2}-[0-9]{2}$', 'Current', 'business'],
            ['DK', 'EUR', 'DK5000400440116243', '^[A-Z]{2}[0-9]+$', null, null, null, null, 'individual'],
            ['DE', 'EUR', 'DE89370400440532013000', '^[A-Z]{2}[0-9]+$', 'BANK CODE', '37040044', '^[0-9]{8}$', 'Current', 'individual'],
            ['JP', 'JPY', '0123456', '^[0-9]{7}$', 'BANK CODE', '0001', '^[0-9]{4}$', 'Saving', 'business'],
            ['TH', 'THB', '123-4-56789-0', '^[0-9-]+$', 'BANK CODE', '004', '^[0-9]{3}$', null, 'individual'],
            ['KR', 'KRW', '110-123-456789', '^[0-9-]+$', 'BANK CODE', '088', '^[0-9]{3}$', 'Saving', 'individual'],
            ['CH', 'EUR', 'CH9300762011623852957', '^[A-Z]{2}[0-9]+$', 'CLEARING CODE', '00762', '^[0-9]{5}$', null, 'business'],
        ];
        $cases = [];
        foreach ($rows as [$country, $currency, $account, $accountPattern, $routingType, $routing, $routingPattern, $bankType, $beneficiaryType]) {
            $fields = ['beneficiaryAccountNumber' => ['type' => 'string', 'pattern' => $accountPattern],
                'beneficiaryBankName' => ['type' => 'string'],
                'beneficiaryBankAccountType' => ['type' => 'string', 'enum' => ['Saving', 'Current']],
                'routingCodeType2' => ['type' => 'string', 'const' => 'BRANCH CODE'],
                'routingCodeValue2' => ['type' => 'string', 'pattern' => '^[0-9]{3}$']];
            $required = [];
            $routingInfo = [];
            if ($routingType !== null) {
                $fields['routingCodeType1'] = ['type' => 'string', 'const' => $routingType];
                $fields['routingCodeValue1'] = ['type' => 'string', 'pattern' => $routingPattern];
                $required = ['routingCodeType1', 'routingCodeValue1'];
                $routingInfo[] = ['type' => $routingType, 'value' => $routing];
            }
            if ($country === 'JP') {
                $routingInfo[] = ['type' => 'BRANCH CODE', 'value' => '001'];
            }
            if ($bankType !== null) {
                $required[] = 'beneficiaryBankAccountType';
            }
            $clauses = [['if' => ['properties' => ['beneficiaryAccountType' => ['const' => 'Corporate']], 'required' => ['beneficiaryAccountType']],
                'then' => ['required' => ['beneficiaryBankName']]],
                ['if' => ['properties' => ['destinationCountry' => ['const' => 'SG']], 'required' => ['destinationCountry']],
                    'then' => ['required' => ['beneficiaryBankAccountType']]],
                ['if' => ['properties' => ['destinationCountry' => ['const' => 'JP']], 'required' => ['destinationCountry']],
                    'then' => ['required' => ['routingCodeType2', 'routingCodeValue2']]],
                ['if' => ['properties' => ['payoutMethod' => ['const' => 'LOCAL']], 'required' => ['payoutMethod']],
                    'then' => ['required' => ['beneficiaryAccountNumber']]]];
            $cases[$country] = [$country, $currency, ['account_number' => $account, 'swift_bic' => null,
                'beneficiary_type' => $beneficiaryType, 'raw_data' => ['nium' => ['payoutMethod' => 'LOCAL',
                    ...($bankType === null ? [] : ['beneficiaryBankAccountType' => $bankType]),
                    ...($routingInfo === [] ? [] : ['routingInfo' => $routingInfo])]]],
                \Tests\Support\SyntheticDynamicBeneficiarySchema::body($fields, $required, $clauses)];
        }
        return $cases;
    }

    #[DataProvider('detailsCorridors')]
    public function test_v2_binding_reuses_old_beneficiary_through_consent_refresh_and_single_dispatch(
        string $country, string $currency, array $receivingInputs, string $schema): void
    {
        $this->detailsBinding = true;
        $this->detailsReceivingInputs = $receivingInputs;
        $this->detailsSchemaBody = $schema;
        $this->beneficiary->update(['country_code' => $country, 'currency' => $currency]);
        if (in_array($country, ['GB', 'JP'], true)) $this->owner->kycProfile()->update(['applicant_type' => 'business']);
        $this->useQuoteOrigin();
        config(['nium_dynamic_payouts.live_automatic_dispatch_authorized' => true,
            'nium_dynamic_payouts.operator_authorization_reference' => 'synthetic-offline-test',
            'services.nium.transfer_endpoint' => '/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/remittance']);
        $review = $this->readyReview(checkOptions: true);
        $quote = NiumDynamicPayoutQuote::query()->sole();
        $bindingHash = $quote->binding['beneficiary_customer_binding_sha256'];
        $record = app(NiumDynamicReviewService::class)->load($review['accepted_fingerprint']);
        $this->assertSame($bindingHash, $record['evidence']['registered_receiving_account']['customer_binding_sha256']);
        $this->assertSame($bindingHash, $record['evidence']['beneficiary_customer_binding']['binding_sha256']);
        $this->assertSame('https://api.spend.nium.com', $record['evidence']['beneficiary_customer_binding']['scope']['origin']);
        $this->assertSame(1, $this->detailsReads); // Options + Quote + Review share a bounded observation.
        $this->assertSame(1, $this->detailsSchemaReads);
        $request = $this->submitReady($review);
        $id = $this->postJson($this->path(''), $request)->assertCreated()->json('id');
        $this->postJson($this->path(''), $request)->assertOk()->assertJsonPath('id', $id);
        $this->travelTo(now()->addSeconds(31));
        $wire = new DynamicPayoutTransportStub;
        $this->app->instance(DynamicPayoutTransport::class, $wire);
        $outbox = NiumDynamicPayoutOutbox::query()->sole();
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        $this->assertSame(2, $this->detailsReads); // Authenticated renewal before dispatch, outside locks.
        $this->assertSame(2, $this->detailsSchemaReads);
        $this->assertSame(1, $wire->submissions);
        $this->assertSame(1, $outbox->fresh()->attempts);
        $this->assertSame(1, NiumDynamicIntent::query()->count());
        $this->assertSame(1, NiumDynamicReservation::query()->count());
        $this->assertSame($record['evidence']['instruction_validation']['request_json'], $wire->bodies[0]);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame(0, Transfer::query()->count());
    }

    public function test_v2_evidence_expired_after_consent_blocks_confirm_without_a_get_under_locks(): void
    {
        $this->detailsBinding = true;
        $review = $this->readyReview();
        $this->travelTo(now()->addSeconds(31));
        $this->postJson($this->path(''), $this->submitReady($review))->assertStatus(409)
            ->assertJsonPath('reason_codes', ['dynamic_quote_binding_changed']);
        $this->assertSame(1, $this->detailsReads);
        $this->noAdmission();
    }

    public function test_v2_provider_account_change_after_quote_cannot_be_refreshed_into_the_original_binding(): void
    {
        $this->detailsBinding = true;
        $this->fullRegistration(); $this->customerListing(); $this->executionEvidence(); $this->providerQuote();
        $id = $this->postJson($this->path('/quotes'), $this->input())->assertCreated()->json('dynamic_quote_id');
        $this->travelTo(now()->addSeconds(31));
        $this->detailsChanges = ['beneficiaryAccountNumber' => '0099999999'];
        $this->postJson($this->path('/reviews'), ['beneficiary_id' => $this->beneficiary->id, 'dynamic_quote_id' => $id,
            'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']])
            ->assertStatus(409)->assertJsonPath('reason_codes', ['dynamic_quote_binding_changed'])
            ->assertJsonMissingPath('accepted_fingerprint');
        $this->assertSame(2, $this->detailsReads);
        $this->noAdmission();
    }

    public function test_v2_binding_change_after_confirmation_prevents_dispatch_and_preserves_reservation(): void
    {
        $this->detailsBinding = true;
        $review = $this->readyReview();
        $this->postJson($this->path(''), $this->submitReady($review))->assertCreated();
        $this->travelTo(now()->addSeconds(31));
        $this->detailsChanges = ['routingCodeValue1' => 'OTHRSGSGXXX'];
        $wire = new DynamicPayoutTransportStub;
        $this->app->instance(DynamicPayoutTransport::class, $wire);
        $outbox = NiumDynamicPayoutOutbox::query()->sole();
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        $this->assertSame(0, $wire->submissions);
        $this->assertSame(0, $outbox->fresh()->attempts);
        $this->assertSame('exception', $outbox->fresh()->state);
        $this->assertSame('held', NiumDynamicReservation::query()->sole()->state);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'debit')->count());
    }

    public function test_provider_schema_change_after_consent_cannot_authorize_dispatch_under_new_rules(): void
    {
        $this->detailsBinding = true;
        $review = $this->readyReview();
        $this->postJson($this->path(''), $this->submitReady($review))->assertCreated();
        $this->travelTo(now()->addSeconds(31));
        // The account still validates, but these are not the schema bytes bound
        // to the quote/consent. Do not silently rewrite the accepted evidence.
        $this->detailsSchemaBody = \Tests\Support\SyntheticDynamicBeneficiarySchema::body([
            'beneficiaryAccountNumber' => ['type' => 'string', 'pattern' => '^[0-9]{10}$']]);
        $wire = new DynamicPayoutTransportStub;
        $this->app->instance(DynamicPayoutTransport::class, $wire);
        $outbox = NiumDynamicPayoutOutbox::query()->sole();
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        $this->assertSame(2, $this->detailsReads);
        $this->assertSame(2, $this->detailsSchemaReads);
        $this->assertSame(0, $wire->submissions);
        $this->assertSame(0, $outbox->fresh()->attempts);
        $this->assertSame('exception', $outbox->fresh()->state);
        $this->assertSame('held', NiumDynamicReservation::query()->sole()->state);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'debit')->count());
    }

    public function test_v2_expiry_before_wire_requires_authenticated_renewal_and_refuses_changed_details(): void
    {
        $this->detailsBinding = true;
        $review = $this->readyReview();
        $this->postJson($this->path(''), $this->submitReady($review))->assertCreated();
        $wire = new DynamicPayoutTransportStub;
        $wire->beforeWire = function (): void {
            $this->travelTo(now()->addSeconds(31));
            $this->detailsChanges = ['beneficiaryAccountNumber' => '0099999999'];
        };
        $this->app->instance(DynamicPayoutTransport::class, $wire);
        $outbox = NiumDynamicPayoutOutbox::query()->sole();
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        $this->assertSame(0, $wire->submissions);
        $this->assertSame(2, $this->detailsReads); // Renewal happens outside locks; stale evidence grants no wire attempt.
        $this->assertSame(0, $outbox->fresh()->attempts);
        $this->assertSame('dynamic_submission_not_started', $outbox->fresh()->exception_code);
        $this->assertSame('held', NiumDynamicReservation::query()->sole()->state);
    }

    public static function v2MappingChanges(): array { return [['customer'], ['beneficiary'], ['account']]; }

    #[DataProvider('v2MappingChanges')]
    public function test_v2_mapping_change_after_consent_cannot_reuse_the_accepted_binding(string $change): void
    {
        $this->detailsBinding = true;
        $review = $this->readyReview();
        if ($change === 'customer') $this->account->update(['external_customer_id' => 'foreign']);
        if ($change === 'beneficiary') $this->beneficiary->update(['external_beneficiary_id' => 'foreign']);
        if ($change === 'account') $this->beneficiary->update(['account_number' => '0099999999']);
        $this->postJson($this->path(''), $this->submitReady($review))->assertStatus($change === 'beneficiary' ? 503 : 409);
        $this->assertSame(1, $this->detailsReads);
        $this->noAdmission();
    }

    public function test_changed_v2_observation_after_consent_cannot_bypass_the_original_response_hash(): void
    {
        $this->detailsBinding = true;
        $review = $this->readyReview();
        $snapshot = app(NiumDynamicReviewService::class)->load($review['accepted_fingerprint'])['evidence']['beneficiary_customer_binding'];
        $key = 'nium:dynamic:beneficiary:v2:'.hash('sha256', json_encode($snapshot['scope'], JSON_THROW_ON_ERROR));
        $snapshot['response_json'] = '{}';
        Cache::store('database')->put($key, Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR)), now()->addMinute());
        $this->postJson($this->path(''), $this->submitReady($review))->assertStatus(409)
            ->assertJsonPath('reason_codes', ['dynamic_quote_binding_changed']);
        $this->assertSame(1, $this->detailsReads);
        $this->noAdmission();
    }

    public static function dynamicCountries(): array { return [['SG', 'SGD'], ['GB', 'GBP']]; }

    #[DataProvider('dynamicCountries')]
    public function test_options_expose_entitled_sgd_and_gbp_without_granting_execution(string $country, string $currency): void
    {
        $this->beneficiary->update(['country_code' => $country, 'currency' => $currency]);
        $this->account->update(['rfi_status' => 'cleared']);
        $this->fullRegistration(); $this->customerListing(); $this->executionEvidence();
        $this->getJson($this->path('/options?beneficiary_id='.$this->beneficiary->id))->assertOk()
            ->assertJsonPath('status', 'QUOTE_REFERENCE_ONLY')->assertJsonPath('dynamic_available', true)
            ->assertJsonPath('customer_entitlement', 'VERIFIED')
            ->assertJsonPath('financial_authorization', 'CUSTOMER_PRICING_AUTHORIZED')
            ->assertJsonPath('quote_availability.destination_currencies', [$currency])
            ->assertJsonPath('supported_amount_modes', ['SOURCE_AMOUNT', 'DESTINATION_AMOUNT'])
            ->assertJsonPath('currency_scales', ['USD' => 2, $currency => 2])
            ->assertJsonPath('executable_amount_modes', [])->assertJsonPath('requires_instruction_validation', true);
        $this->assertSame(0, $this->walletReads);
        $this->assertArrayNotHasKey('dynamic_authenticated_availability', $this->wallet->fresh()->raw_data);
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
        $this->assertSame(0, Transfer::query()->count());
    }

    public static function spendOptions(): array
    {
        return [['SG', 'SGD', true], ['SG', 'SGD', false], ['GB', 'GBP', true], ['GB', 'GBP', false]];
    }

    #[DataProvider('spendOptions')]
    public function test_spend_options_require_complete_customer_schema_without_a_sample_corridor_allowlist(
        string $country, string $currency, bool $sourcePrecision): void
    {
        $this->useQuoteOrigin();
        $this->beneficiary->update(['country_code' => $country, 'currency' => $currency]);
        $this->account->update(['rfi_status' => 'cleared']);
        $this->fullRegistration(); $this->customerListing();
        $properties = ['payout.destinationAmount' => ['type' => 'number', 'maximumDecimals' => 2]];
        if ($sourcePrecision) $properties['payout.sourceAmount'] = ['type' => 'number', 'maximumDecimals' => 2];
        $schema = ['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'type' => 'object',
            'properties' => $properties, 'required' => ['beneficiary.id'],
            'allOf' => [['if' => ['properties' => ['synthetic.flag' => ['const' => true]]],
                'then' => ['required' => ['synthetic.conditionalField']]],
                ['if' => ['required' => ['payout.sourceAmount']],
                    'then' => ['required' => ['payout.sourceAmount', 'payout.sourceCurrency']]],
                ['if' => ['required' => ['payout.destinationAmount']],
                    'then' => ['required' => ['payout.destinationAmount', 'payout.sourceCurrency']]]]];
        $this->executionEvidence(schemaBody: json_encode($schema, JSON_THROW_ON_ERROR));
        $response = $this->getJson($this->path('/options?beneficiary_id='.$this->beneficiary->id))->assertOk()
            ->assertJsonPath('customer_entitlement', 'VERIFIED')
            ->assertJsonPath('financial_authorization', 'CUSTOMER_PRICING_AUTHORIZED')
            ->assertJsonPath('dynamic_available', true)
            ->assertJsonPath('executable_amount_modes', [])->assertJsonPath('requires_instruction_validation', true);
        if ($sourcePrecision) {
            $response->assertJsonPath('status', 'QUOTE_REFERENCE_ONLY')
                ->assertJsonPath('supported_amount_modes', ['SOURCE_AMOUNT', 'DESTINATION_AMOUNT'])
                ->assertJsonPath('currency_scales', ['USD' => 2, $currency => 2])->assertJsonPath('reason_codes', []);
        } else {
            $response->assertJsonPath('status', 'QUOTE_REFERENCE_ONLY')->assertJsonPath('supported_amount_modes', ['DESTINATION_AMOUNT'])
                ->assertJsonPath('currency_scales', ['USD' => 2, $currency => 2])->assertJsonPath('reason_codes', []);
        }
        $this->assertSame(0, $this->walletReads);
        $this->assertArrayNotHasKey('dynamic_authenticated_availability', $this->wallet->fresh()->raw_data);
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
        $this->assertSame(0, Transfer::query()->count());
    }

    #[DataProvider('modes')]
    public function test_spend_conditional_amount_instruction_keeps_one_validated_body_through_confirmation_and_retry(string $mode): void
    {
        // Only the origin/dialect are Production-shaped. All HTTP remains synthetic.
        $this->useQuoteOrigin();
        config(['nium_dynamic_payouts.live_automatic_dispatch_authorized' => true,
            'nium_dynamic_payouts.operator_authorization_reference' => 'synthetic-offline-test',
            'services.nium.transfer_endpoint' => '/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/remittance']);
        $review = $this->readyReview($mode, checkOptions: true);
        $record = app(NiumDynamicReviewService::class)->load($review['accepted_fingerprint']);
        $schema = $record['evidence']['execution_eligibility']['schema_observation'];
        $this->assertFalse($schema['eligible']);
        $this->assertFalse($schema['instruction_validated']);
        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['schema_uri']);
        $request = $this->submitReady($review);
        $id = $this->postJson($this->path(''), $request)->assertCreated()->json('id');
        $this->postJson($this->path(''), $request)->assertOk()->assertJsonPath('id', $id);
        $this->travelTo(now()->addSeconds(31)); // Refresh the same instruction before wire admission.
        $wire = new DynamicPayoutTransportStub;
        $this->app->instance(DynamicPayoutTransport::class, $wire);
        $outbox = NiumDynamicPayoutOutbox::query()->sole();
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        $this->assertSame(1, $wire->submissions);
        $this->assertSame(1, $outbox->fresh()->attempts);
        $this->assertSame($record['evidence']['instruction_validation']['request_json'], $wire->bodies[0]);
        $this->assertSame($record['evidence']['instruction_validation']['request_sha256'], hash('sha256', $wire->bodies[0]));
        $this->assertSame(1, NiumDynamicReservation::query()->count());
        $this->assertSame('1000.00000000', $this->wallet->fresh()->reserved_balance);
    }

    #[DataProvider('modes')]
    public function test_spend_conditional_amount_observation_cannot_replace_provider_instruction_acceptance(string $mode): void
    {
        $this->useQuoteOrigin();
        config(['nium_dynamic_payouts.live_automatic_dispatch_authorized' => true,
            'nium_dynamic_payouts.operator_authorization_reference' => 'synthetic-offline-test',
            'services.nium.transfer_endpoint' => '/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/remittance']);
        $this->fullRegistration(); $this->customerListing(); $this->executionEvidence();
        $this->providerQuote($mode);
        $this->validationResponse = '{"valid":false,"issues":[{"field":"payout.sourceAmount","code":"synthetic-rejection"}]}';
        $id = $this->postJson($this->path('/quotes'), $this->input($mode))->assertCreated()->json('dynamic_quote_id');
        $this->postJson($this->path('/reviews'), ['beneficiary_id' => $this->beneficiary->id, 'dynamic_quote_id' => $id,
            'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']])
            ->assertStatus(503)->assertJsonPath('reason_codes', ['dynamic_payout_instruction_rejected'])
            ->assertJsonMissingPath('accepted_fingerprint');
        $this->noAdmission();
    }

    #[DataProvider('modes')]
    public function test_precision_only_schema_cannot_offer_modes_or_create_an_accepted_review(string $mode): void
    {
        $this->fullRegistration(); $this->customerListing();
        $this->executionEvidence(schemaBody: '{"$schema":"http://json-schema.org/draft-07/schema#","type":"object",'
            .'"required":["beneficiary.id"],"allOf":[{"if":{},"then":{}}],'
            .'"properties":{"payout.sourceAmount":{"type":"number","maximumDecimals":2},'
            .'"payout.destinationAmount":{"type":"number","maximumDecimals":2}}}');
        $this->getJson($this->path('/options?beneficiary_id='.$this->beneficiary->id))->assertOk()
            ->assertJsonPath('dynamic_available', false)->assertJsonPath('supported_amount_modes', [])
            ->assertJsonPath('reason_codes', ['dynamic_payout_amount_modes_unverified']);
        $this->providerQuote($mode);
        $id = $this->postJson($this->path('/quotes'), $this->input($mode))->assertCreated()->json('dynamic_quote_id');
        $this->postJson($this->path('/reviews'), ['beneficiary_id' => $this->beneficiary->id, 'dynamic_quote_id' => $id,
            'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']])
            ->assertStatus(503)->assertJsonMissingPath('accepted_fingerprint')
            ->assertJsonPath('reason_codes', [$mode === 'SOURCE_AMOUNT'
                ? 'dynamic_payout_source_amount_contract_unverified' : 'dynamic_payout_destination_amount_contract_unverified']);
        $this->noAdmission();
    }

    public static function unverifiedAccountStates(): array
    {
        $cases = [];
        foreach (['rfi_status' => [null, 'unknown', 'pending', 'failed', 'requested', 'responded'],
            'compliance_status' => ['pending', 'failed', 'action_required', 'rejected', 'blocked', 'hold', 'completed'],
            'odd_status' => ['pending', 'failed', 'odd_due', 'blocked', 'hold', 'odd_ineligible', 'odd_not_due', 'completed']] as $field => $values) {
            foreach ($values as $value) $cases[$field.' '.($value ?? 'NULL')] = [$field, $value];
        }
        return $cases;
    }

    #[DataProvider('unverifiedAccountStates')]
    public function test_quote_does_not_infer_approval_from_unverified_account_states(string $field, ?string $value): void
    {
        $this->account->update([$field => $value]);
        $this->assertQuoteFailure('dynamic_quote_local_eligibility_changed', fn () => $this->createReference());
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
    }

    public static function providerClearWithAccountRestrictions(): array
    {
        // A missing observation in one field must never override a restriction in the other.
        return [
            'explicit compliance action required' => [['compliance_status' => 'action_required', 'odd_status' => 'unknown']],
            'explicit compliance rejected' => [['compliance_status' => 'rejected', 'odd_status' => 'unknown']],
            'explicit compliance hold' => [['compliance_status' => 'hold', 'odd_status' => null]],
            'explicit ODD due' => [['compliance_status' => null, 'odd_status' => 'odd_due']],
            'explicit ODD hold' => [['compliance_status' => 'unknown', 'odd_status' => 'hold']],
        ];
    }

    #[DataProvider('providerClearWithAccountRestrictions')]
    public function test_provider_clear_cannot_override_account_restrictions_at_quote(array $changes): void
    {
        $this->account->update(['rfi_status' => 'cleared', ...$changes]);
        $this->getJson($this->path('/options?beneficiary_id='.$this->beneficiary->id))->assertOk()
            ->assertJsonPath('dynamic_available', false)
            ->assertJsonPath('reason_codes', ['dynamic_quote_local_eligibility_changed']);
        $this->assertQuoteFailure('dynamic_quote_local_eligibility_changed', fn () => $this->createReference());
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
        $this->noAdmission();
    }

    #[DataProvider('providerClearWithAccountRestrictions')]
    public function test_provider_clear_cannot_override_account_restrictions_at_review(array $changes): void
    {
        $this->readyReview();
        $this->account->update(['rfi_status' => 'cleared', ...$changes]);

        $this->postJson($this->path('/reviews'), [
            'beneficiary_id' => $this->beneficiary->id,
            'dynamic_quote_id' => NiumDynamicPayoutQuote::query()->sole()->id,
            'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice'],
        ])->assertStatus(503)->assertJsonPath('reason_codes', ['dynamic_quote_local_eligibility_changed'])
            ->assertJsonMissingPath('accepted_fingerprint');
        $this->assertSame(0, LedgerEntry::query()->count());
        $this->noAdmission();
    }

    #[DataProvider('providerClearWithAccountRestrictions')]
    public function test_provider_clear_cannot_override_account_restrictions_at_confirmation(array $changes): void
    {
        $review = $this->readyReview();
        $this->account->update(['rfi_status' => 'cleared', ...$changes]);

        $this->assertQuoteFailure('dynamic_customer_account_beneficiary_or_wallet_ineligible', fn () =>
            app(DynamicTransferConfirmationService::class)->confirm($this->owner, $this->account->id,
                $this->beneficiary->id, $this->wallet->id, 'account-clearance-regression', $review['accepted_fingerprint']));
        $this->assertSame(0, LedgerEntry::query()->count());
        $this->noAdmission();
    }

    public static function providerClearWithAccountRestrictionsAtDispatch(): array
    {
        $cases = [];
        foreach (self::providerClearWithAccountRestrictions() as $name => [$changes]) {
            $cases[$name.' before claim'] = [$changes, false];
            $cases[$name.' before wire'] = [$changes, true];
        }
        foreach (['aml', 'rfi', 'sanctions_match', 'pending', 'running', 'failed', 'unknown'] as $hold) {
            foreach ([false, true] as $beforeWire) {
                $cases[$hold.($beforeWire ? ' before wire' : ' before claim')] = [
                    ['compliance_status' => null, 'odd_status' => 'unknown'], $beforeWire, $hold,
                ];
            }
        }

        return $cases;
    }

    #[DataProvider('providerClearWithAccountRestrictionsAtDispatch')]
    public function test_provider_clear_cannot_override_account_restrictions_at_dispatch(
        array $changes, bool $beforeWire, ?string $evidenceHold = null): void
    {
        $review = $this->readyReview();
        $this->postJson($this->path(''), $this->submitReady($review))->assertCreated();
        $intent = NiumDynamicIntent::query()->sole();
        $terms = $intent->getAttributes();
        $wire = new DynamicPayoutTransportStub;
        $restrictAccount = function () use ($changes, $evidenceHold): void {
            $this->account->update(['rfi_status' => 'cleared', ...$changes]);
            if ($evidenceHold === 'aml') {
                AmlScreening::query()->where('user_id', $this->owner->id)->update(['compliance_decision' => 'pending_review']);
            } elseif (in_array($evidenceHold, ['pending', 'running', 'failed', 'unknown'], true)) {
                AmlScreening::query()->where('user_id', $this->owner->id)->sole()->update([
                    'status' => $evidenceHold, 'compliance_decision' => 'pending_review',
                    'screening_result' => null, 'completed_at' => null,
                    'result_summary' => $evidenceHold === 'failed' ? ['error' => 'provider_failure'] : null]);
            } elseif ($evidenceHold === 'sanctions_match') {
                $screening = AmlScreening::query()->where('user_id', $this->owner->id)->sole();
                $screening->update(['status' => 'failed', 'compliance_decision' => 'pending_review',
                    'screening_result' => null, 'superseded_at' => now()]);
                $screening->matches()->create(['list_type' => 'sanctions', 'source' => 'fixture',
                    'matched_name' => 'Fixture unresolved match', 'status' => 'open']);
            } elseif ($evidenceHold === 'rfi') {
                NiumRfiCase::query()->create(['provider_id' => $this->account->provider_id,
                    'user_provider_account_id' => $this->account->id, 'scope' => 'customer',
                    'provider_reference_fingerprint' => str_repeat('a', 64), 'status' => 'requested', 'evidence' => []]);
            }
        };
        if ($beforeWire) {
            $wire->beforeWire = $restrictAccount;
        } else {
            $restrictAccount();
        }
        $this->app->instance(DynamicPayoutTransport::class, $wire);
        $outbox = NiumDynamicPayoutOutbox::query()->sole();

        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);

        $this->assertSame(0, $wire->submissions);
        $this->assertSame([], $wire->bodies);
        $this->assertSame(0, $outbox->fresh()->attempts);
        $this->assertNull($outbox->fresh()->submission_started_at);
        $this->assertSame('exception', $outbox->fresh()->state);
        $this->assertSame($beforeWire ? 'dynamic_submission_not_started'
            : ($evidenceHold !== null ? 'dynamic_compliance_hold_or_evidence_unavailable'
                : 'dynamic_customer_account_beneficiary_or_wallet_ineligible'), $outbox->fresh()->exception_code);
        $this->assertSame('held', NiumDynamicReservation::query()->sole()->state);
        $this->assertSame('1000.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame('1000.00000000', $this->wallet->fresh()->available_balance);
        $this->assertSame('2000.00000000', $this->wallet->fresh()->ledger_balance);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame($terms, $intent->fresh()->getAttributes());
    }

    #[DataProvider('dynamicCountries')]
    public function test_withdrawn_customer_corridor_is_refused_before_dispatch_without_releasing_or_resubmitting(string $country, string $currency): void
    {
        $this->beneficiary->update(['country_code' => $country, 'currency' => $currency]);
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

    public static function conflictingCustomerCorridors(): array
    {
        $row = ['destination_currency' => 'GBP', 'payout_method' => 'LOCAL',
            'destination_country' => ['GB'], 'routing_code_type' => 'synthetic-route-a'];
        return [
            'Spend four fields and two matching rows' => [[$row, [...$row, 'routing_code_type' => 'synthetic-route-b']],
                'dynamic_customer_corridor_ambiguous'],
            'conflicting entity' => [[[...$row, 'client_legal_entity' => ['LEGAL_ENTITY_SG']]],
                'dynamic_customer_corridor_not_permitted'],
            'unsupported lookup flag' => [[[...$row, 'ignoreLookup' => true]],
                'dynamic_customer_corridor_response_contract_unavailable'],
            'malformed routing' => [[[...$row, 'routing_code_type' => null]],
                'dynamic_customer_corridor_response_contract_unavailable'],
        ];
    }

    #[DataProvider('conflictingCustomerCorridors')]
    public function test_conflicting_or_ambiguous_customer_corridors_cannot_produce_an_admissible_review(array $rows, string $reason): void
    {
        // Sandbox fixtures isolate corridor validation from unrelated Spend guards.
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

    #[DataProvider('dynamicCountries')]
    public function test_schema_if_then_is_not_treated_as_a_successful_instruction_validation(string $country, string $currency): void
    {
        $this->beneficiary->update(['country_code' => $country, 'currency' => $currency]);
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
        $this->assertSame(0, $this->walletReads);
        $this->assertArrayNotHasKey('dynamic_authenticated_availability', $this->wallet->fresh()->raw_data);
        $response = $this->postJson($this->path('/quotes'), $this->input($mode))->assertCreated()
            ->assertJsonPath('status', 'QUOTE_REFERENCE_ONLY')->assertJsonPath('quoted_principal.amount', '980')
            ->assertJsonPath('planned_included_fee.amount', '20')->assertJsonPath('planned_total_usd_debit', '1000')
            ->assertJsonPath('nium_quoted_fx_rate', '0.800000000000000001')
            ->assertJsonPath('quote_expires_at', '2030-01-01T01:00:00.000000Z')->assertJsonPath('review', 'REQUIRED');
        $this->assertSame(1, $this->walletReads);
        $this->assertSame('2000.00000000', app(DynamicPayoutWalletSnapshot::class)
            ->current($this->owner, $this->wallet->fresh(), $this->account));
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
        return [['FR', 'EUR', 'LOCAL'], ['GB', 'GBP', 'SWIFT'], ['TH', 'THB', 'CASH'],
            ['SG', 'USD', 'SWIFT'], ['HK', 'USD', 'SWIFT']];
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
            ->assertJsonPath('reason_codes', ['dynamic_pricing_corridor_unsupported'])
            ->assertJsonPath('quote_availability.reason_codes', ['dynamic_pricing_corridor_unsupported',
                ...($currency === 'USD' ? ['dynamic_same_currency_quote_contract_unverified'] : [])]);
        $this->postJson($this->path('/quotes'), $this->input())->assertStatus(503)
            ->assertJsonPath('reason_codes', [$currency === 'USD'
                ? 'dynamic_same_currency_quote_contract_unverified' : 'dynamic_pricing_corridor_unsupported']);
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
    }

    public function test_spend_four_field_corridor_support_allows_options_but_requires_instruction_validation(): void
    {
        $this->useQuoteOrigin();
        $this->fullRegistration(); $this->customerListing();
        $this->executionEvidence(fn () => '{"data":[{"destination_currency":"GBP","payout_method":"LOCAL",'
            .'"destination_country":["GB"],"routing_code_type":"synthetic-route"}]}');
        $this->nium->shouldNotReceive('post');
        $this->getJson($this->path('/options?beneficiary_id='.$this->beneficiary->id))->assertOk()
            ->assertJsonPath('provider_listing.status', 'LISTED')->assertJsonPath('provider_listing.eligible', false)
            ->assertJsonPath('customer_entitlement', 'VERIFIED')
            ->assertJsonPath('financial_authorization', 'CUSTOMER_PRICING_AUTHORIZED')
            ->assertJsonPath('dynamic_available', true)->assertJsonPath('supported_amount_modes', ['SOURCE_AMOUNT', 'DESTINATION_AMOUNT'])
            ->assertJsonPath('executable_amount_modes', [])->assertJsonPath('requires_instruction_validation', true)
            ->assertJsonPath('reason_codes', []);
        $this->noAdmission();
    }

    public function test_four_field_corridor_listing_does_not_replace_missing_beneficiary_customer_binding(): void
    {
        $this->beneficiary->update(['account_number' => null]);
        $this->fullRegistration();
        // ID/corridor alone cannot replace missing full account/routing inputs.
        $response = json_encode(['beneficiaryHashId' => $this->beneficiary->external_beneficiary_id], JSON_THROW_ON_ERROR);
        $this->exchange->update(['raw_response_body' => $response,
            'response_body_sha256' => hash('sha256', $response)]);
        $this->customerListing();
        $this->nium->shouldNotReceive('post');
        $this->nium->shouldNotReceive('get')->withArgs(static fn ($path, $query, $user, $operation): bool =>
            in_array($operation, ['dynamic_customer_corridors', 'dynamic_payout_schema'], true));
        $this->getJson($this->path('/options?beneficiary_id='.$this->beneficiary->id))->assertOk()
            ->assertJsonPath('provider_listing.status', 'LISTED')
            ->assertJsonPath('customer_entitlement', 'UNAVAILABLE')
            ->assertJsonPath('dynamic_available', false)
            ->assertJsonPath('reason_codes', ['dynamic_registered_account_customer_scope_unavailable']);
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
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

    public static function schemaAmountRefusals(): array
    {
        return [
            'below minimum' => [['minimum' => 785], 'dynamic_payout_amount_out_of_schema_bounds'],
            'above maximum' => [['maximum' => 783], 'dynamic_payout_amount_out_of_schema_bounds'],
            'malformed minimum' => [['minimum' => '1'], 'dynamic_payout_schema_contract_unavailable'],
            'conflicting bounds' => [['minimum' => 785, 'maximum' => 783], 'dynamic_payout_schema_contract_unavailable'],
            'unsupported constraint' => [['exclusiveMinimum' => 1], 'dynamic_payout_schema_contract_unavailable'],
        ];
    }

    #[DataProvider('schemaAmountRefusals')]
    public function test_schema_amount_refusal_never_validates_or_admits_an_instruction(array $constraints, string $reason): void
    {
        $this->fullRegistration(); $this->customerListing();
        $this->executionEvidence(schemaBody: $this->destinationSchemaBody($constraints));
        $this->providerQuote('DESTINATION_AMOUNT');
        $this->duringValidation = function (): void { $this->fail('An invalid schema amount must fail before instruction validation.'); };
        $id = $this->postJson($this->path('/quotes'), $this->input('DESTINATION_AMOUNT'))->assertCreated()->json('dynamic_quote_id');
        $this->postJson($this->path('/reviews'), ['beneficiary_id' => $this->beneficiary->id,
            'dynamic_quote_id' => $id, 'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']])
            ->assertStatus(503)->assertJsonPath('reason_codes', [$reason])->assertJsonMissingPath('accepted_fingerprint');
        $this->noAdmission();
    }

    public function test_destination_only_schema_does_not_waive_other_required_fields_at_instruction_validation(): void
    {
        $this->fullRegistration(); $this->customerListing();
        $schema = json_decode($this->destinationSchemaBody(), true, 64, JSON_THROW_ON_ERROR);
        $schema['required'][] = 'synthetic.mandatoryField';
        $schema['properties']['synthetic.mandatoryField'] = ['type' => 'string'];
        $this->executionEvidence(schemaBody: json_encode($schema, JSON_THROW_ON_ERROR));
        $this->providerQuote('DESTINATION_AMOUNT');
        $this->validationResponse = '{"valid":false,"issues":[{"field":"synthetic.mandatoryField","code":"required"}]}';
        $id = $this->postJson($this->path('/quotes'), $this->input('DESTINATION_AMOUNT'))->assertCreated()->json('dynamic_quote_id');
        $this->postJson($this->path('/reviews'), ['beneficiary_id' => $this->beneficiary->id,
            'dynamic_quote_id' => $id, 'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']])
            ->assertStatus(503)->assertJsonPath('reason_codes', ['dynamic_payout_instruction_rejected'])
            ->assertJsonMissingPath('accepted_fingerprint');
        $this->noAdmission();
    }

    #[DataProvider('modes')]
    public function test_cached_review_rechecks_numeric_bounds_from_raw_schema_before_locked_admission(string $mode): void
    {
        $review = $this->readyReview($mode);
        $service = app(NiumDynamicReviewService::class);
        $record = $service->load($review['accepted_fingerprint']);
        $eligibility = &$record['evidence']['execution_eligibility'];
        $schema = json_decode($eligibility['schema_observation']['schema_json'], true, 64, JSON_THROW_ON_ERROR);
        $field = $mode === 'SOURCE_AMOUNT' ? 'payout.sourceAmount' : 'payout.destinationAmount';
        $schema['properties'][$field]['minimum'] = $mode === 'SOURCE_AMOUNT' ? 981 : 785;
        $eligibility['schema_observation']['schema_json'] = json_encode($schema, JSON_THROW_ON_ERROR);
        $eligibility['payout_schema_sha256'] = hash('sha256', $eligibility['schema_observation']['schema_json']);
        // The former summary/consent contains no minimum. Raw constraints still take effect.
        $stored = NiumDynamicPayoutQuote::query()->findOrFail($record['dynamic_quote_id']);
        $this->assertQuoteFailure('dynamic_payout_amount_out_of_schema_bounds', fn () => $service->assemble($record, $stored));
        $this->noAdmission();
    }

    public function test_changed_schema_minimum_after_confirmation_blocks_dispatch_without_a_retry_post(): void
    {
        $this->fullRegistration(); $this->customerListing();
        $changed = false;
        $this->executionEvidence(schemaBody: function () use (&$changed): string {
            return $this->destinationSchemaBody(['minimum' => $changed ? 785 : 1]);
        });
        $this->providerQuote('DESTINATION_AMOUNT');
        $id = $this->postJson($this->path('/quotes'), $this->input('DESTINATION_AMOUNT'))->assertCreated()->json('dynamic_quote_id');
        $review = $this->postJson($this->path('/reviews'), ['beneficiary_id' => $this->beneficiary->id,
            'dynamic_quote_id' => $id, 'instruction' => ['purpose_code' => 'IR005', 'source_of_funds' => 'Salary', 'comment' => 'Invoice']])
            ->assertOk()->assertJsonPath('status', 'READY')->json();
        $this->postJson($this->path(''), $this->submitReady($review))->assertCreated();
        $changed = true;
        $this->travelTo(now()->addSeconds(31));
        $wire = new DynamicPayoutTransportStub;
        $this->app->instance(DynamicPayoutTransport::class, $wire);
        $outbox = NiumDynamicPayoutOutbox::query()->sole();
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        app(DynamicPayoutDispatcher::class)->dispatch($outbox->id);
        $this->assertSame(0, $wire->submissions);
        $this->assertSame([], $wire->bodies);
        $this->assertSame(0, $outbox->fresh()->attempts);
        $this->assertNull($outbox->fresh()->submission_started_at);
        $this->assertSame('exception', $outbox->fresh()->state);
        $this->assertSame('dynamic_payout_amount_out_of_schema_bounds', $outbox->fresh()->exception_code);
        $this->assertSame('held', NiumDynamicReservation::query()->sole()->state);
        $this->assertSame('1000.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'debit')->count());
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
        unset($price['contracted_nium_transaction_fee_usd'], $price['fee_owner'], $price['fee_type'],
            $price['fee_basis'], $price['fx_margin_owner'], $price['fee_collection'], $price['origin_revenue']);
        $binding = $stored->binding;
        $binding['customer_pricing_sha256'] = hash('sha256', json_encode($price, JSON_THROW_ON_ERROR));
        // Model-only stale snapshot: immutable persisted quotes are never rewritten.
        $stored->binding = $binding;
        $this->assertQuoteFailure('dynamic_quote_binding_changed', fn () => app(NiumDynamicPayoutQuoteService::class)->project($stored));
        $this->noAdmission();
    }

    public function test_same_amount_cannot_reassign_nium_fee_or_margin_to_origin(): void
    {
        $review = $this->readyReview();
        $service = app(NiumDynamicReviewService::class);
        $record = $service->load($review['accepted_fingerprint']);
        $stored = NiumDynamicPayoutQuote::query()->findOrFail($record['dynamic_quote_id']);
        foreach (['fee_owner' => 'ORIGIN', 'fx_margin_owner' => 'ORIGIN', 'origin_revenue' => 'RECOGNIZED'] as $field => $value) {
            $altered = $record;
            $altered['customer_pricing'][$field] = $value;
            $this->assertQuoteFailure('dynamic_financial_terms_changed', fn () => $service->assemble($altered, $stored));
        }
        $this->noAdmission();
    }

    public function test_previous_origin_owned_fee_cannot_be_reinterpreted_as_nium_consent(): void
    {
        $this->customerListing(); $this->providerQuote();
        $stored = $this->createReference();
        $price = app(\App\Services\Nium\DynamicConfirmation\DynamicPayoutFinancialAuthorization::class)->pricing($this->beneficiary);
        $price['version'] = 'origin-nium-contracted-local-usd-inclusive-v3';
        $price['origin_service_fee_usd'] = $price['contracted_nium_transaction_fee_usd'];
        unset($price['contracted_nium_transaction_fee_usd'], $price['fee_owner'], $price['fee_type'],
            $price['fee_basis'], $price['fx_margin_owner'], $price['fee_collection'], $price['origin_revenue']);
        // Old immutable records require a new quote/consent; never migrate their meaning in place.
        $binding = $stored->binding;
        $binding['customer_pricing_sha256'] = hash('sha256', json_encode($price, JSON_THROW_ON_ERROR));
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

    #[DataProvider('dynamicCountries')]
    public function test_configured_corridor_cannot_override_missing_provider_support(string $country, string $currency): void
    {
        $this->beneficiary->update(['country_code' => $country, 'currency' => $currency]);
        $this->fullRegistration(); // Bind registration bytes and hashes to this data-provider corridor.
        $this->customerListing(false);
        $this->nium->shouldNotReceive('post');
        $this->postJson($this->path('/quotes'), $this->input())->assertStatus(503)
            ->assertJsonPath('reason_codes', ['dynamic_customer_corridor_not_permitted']);
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
    }

    #[DataProvider('dynamicCountries')]
    public function test_provider_contract_missing_echoes_is_unavailable_without_persisting_quote(string $country, string $currency): void
    {
        $this->beneficiary->update(['country_code' => $country, 'currency' => $currency]);
        $this->fullRegistration(); // Reach provider contract validation with valid registration evidence.
        $this->customerListing();
        $this->providerQuote(body: '{"id":"quote","sourceAmount":980,"destinationAmount":784,"netExchangeRate":0.8}');
        $this->postJson($this->path('/quotes'), $this->input())->assertStatus(503)
            ->assertJsonPath('reason_codes', ['dynamic_provider_quote_contract_unavailable']);
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
    }

    public static function providerBalanceRepresentations(): array
    {
        return [[2000], ['2000'], ['2000.00000000']];
    }

    public static function invalidProviderBalances(): array
    {
        return [[2000.0], [true], [false], [null], [-1], ['2e3'], ['2000.000000001'], ['1000000000000']];
    }

    public static function quoteWalletFailures(): array
    {
        $valid = '[{"curSymbol":"USD","balance":2000}]';
        return [
            'redirect' => [$valid, 302],
            'unauthorized' => [$valid, 401],
            'provider error' => [$valid, 500],
            'malformed JSON' => ['{"currencies":', 200],
            'duplicate amount keys' => ['[{"curSymbol":"USD","balance":1,"balance":2000}]', 200],
            'wrong client' => ['[{"clientHashId":"foreign","curSymbol":"USD","balance":2000}]', 200],
            'wrong customer' => ['[{"customerHashId":"foreign","curSymbol":"USD","balance":2000}]', 200],
            'wrong wallet' => ['[{"walletHashId":"foreign","curSymbol":"USD","balance":2000}]', 200],
            'missing USD' => ['[{"curSymbol":"EUR","balance":2000}]', 200],
            'ambiguous USD' => ['[{"curSymbol":"USD","balance":2000},{"curSymbol":"USD","balance":2000}]', 200],
            'conflicting balances' => ['[{"curSymbol":"USD","balance":2000,"availableBalance":1}]', 200],
            'exponent amount' => ['[{"curSymbol":"USD","balance":2e3}]', 200],
            'excess precision' => ['[{"curSymbol":"USD","balance":2000.000000001}]', 200],
        ];
    }

    #[DataProvider('quoteWalletFailures')]
    public function test_failed_wallet_refresh_prevents_quote_http_and_all_admission(string $body, int $status): void
    {
        $this->customerListing();
        $this->nium->shouldNotReceive('post');
        $this->walletResponseBody = $body;
        $this->walletResponseStatus = $status;
        $raw = $this->wallet->fresh()->raw_data;
        $this->postJson($this->path('/quotes'), $this->input('DESTINATION_AMOUNT'))->assertStatus(503);
        $this->assertSame(1, $this->walletReads);
        $this->assertSame($raw, $this->wallet->fresh()->raw_data);
        $this->assertSame('2000.00000000', $this->wallet->fresh()->available_balance);
        $this->assertSame('2000.00000000', $this->wallet->fresh()->ledger_balance);
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
        $this->noAdmission();
    }

    public function test_wallet_timeout_cannot_fall_back_to_an_existing_valid_authenticated_snapshot(): void
    {
        app(DynamicPayoutWalletSnapshot::class)->refresh($this->owner, $this->wallet);
        $raw = $this->wallet->fresh()->raw_data;
        $this->customerListing();
        $this->nium->shouldNotReceive('post');
        $this->duringWalletRead = static function (): void {
            throw new \Illuminate\Http\Client\ConnectionException('synthetic offline timeout');
        };
        $this->postJson($this->path('/quotes'), $this->input())->assertStatus(503);
        $this->assertSame(2, $this->walletReads);
        $this->assertSame($raw, $this->wallet->fresh()->raw_data);
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
    }

    public function test_wallet_refresh_expiring_during_get_cannot_create_a_quote_or_snapshot(): void
    {
        $this->customerListing();
        $this->nium->shouldNotReceive('post');
        $this->duringWalletRead = fn () => $this->travelTo(now()->addSeconds(30));
        $raw = $this->wallet->fresh()->raw_data;
        $this->postJson($this->path('/quotes'), $this->input())->assertStatus(503)
            ->assertJsonPath('reason_codes', ['dynamic_wallet_authenticated_snapshot_unavailable']);
        $this->assertSame(1, $this->walletReads);
        $this->assertSame($raw, $this->wallet->fresh()->raw_data);
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
    }

    public static function quoteWalletBindingChanges(): array
    {
        return [['customer'], ['wallet'], ['currency'], ['origin'], ['credential']];
    }

    #[DataProvider('quoteWalletBindingChanges')]
    public function test_binding_drift_during_wallet_get_prevents_quote_http(string $binding): void
    {
        $this->customerListing();
        $this->nium->shouldNotReceive('post');
        $raw = $this->wallet->fresh()->raw_data;
        $this->duringWalletRead = function () use ($binding): void {
            match ($binding) {
                'customer' => $this->account->update(['external_customer_id' => 'foreign-customer']),
                'wallet' => $this->wallet->update(['external_account_id' => 'foreign-wallet']),
                'currency' => $this->wallet->update(['currency' => 'EUR']),
                'origin' => config(['services.nium.base_url' => 'https://gateway.nium.com']),
                'credential' => config(['services.nium.auth.header_value' => 'rotated-synthetic-key']),
            };
        };
        $this->postJson($this->path('/quotes'), $this->input())->assertStatus(503)
            ->assertJsonPath('reason_codes', ['dynamic_wallet_snapshot_binding_changed']);
        $this->assertSame(1, $this->walletReads);
        $this->assertSame($raw, $this->wallet->fresh()->raw_data);
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
    }

    public function test_wallet_credentials_cannot_be_replaced_by_a_generic_balance(): void
    {
        $this->customerListing();
        $this->nium->shouldNotReceive('post');
        config(['services.nium.auth.header_value' => '']);
        $raw = $this->wallet->fresh()->raw_data;
        $this->postJson($this->path('/quotes'), $this->input())->assertStatus(503);
        $this->assertSame(0, $this->walletReads);
        $this->assertSame($raw, $this->wallet->fresh()->raw_data);
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
    }

    public static function quoteSnapshotBindingChanges(): array
    {
        return [
            ['customer', 'foreign-customer'], ['wallet', 'foreign-wallet'], ['account_id', 'foreign-account'],
            ['owner', 'foreign-owner'], ['currency', 'EUR'], ['response_sha256', 'invalid'], ['available', '999'],
        ];
    }

    #[DataProvider('quoteSnapshotBindingChanges')]
    public function test_changed_snapshot_after_refresh_cannot_be_used_to_persist_a_quote(string $field, string $value): void
    {
        $this->customerListing();
        $this->providerQuote(during: function () use ($field, $value): void {
            $raw = $this->wallet->fresh()->raw_data;
            $raw['dynamic_authenticated_availability'][$field] = $value;
            $this->wallet->refresh()->update(['raw_data' => $raw]);
        });
        $this->postJson($this->path('/quotes'), $this->input())->assertStatus(503)
            ->assertJsonPath('reason_codes', ['dynamic_quote_wallet_balance_unavailable']);
        $this->assertSame(1, $this->walletReads);
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
    }

    #[DataProvider('invalidProviderBalances')]
    public function test_invalid_provider_balance_cannot_bypass_a_valid_snapshot(mixed $value): void
    {
        $this->customerListing();
        $this->providerQuote('DESTINATION_AMOUNT', during: function () use ($value): void {
            $this->wallet->refresh();
            $raw = $this->wallet->raw_data;
            $raw['provider_available_balance'] = $value;
            // Mutate after authenticated preparation, preserving the float token.
            $this->wallet->setRawAttributes([...$this->wallet->getAttributes(),
                'raw_data' => json_encode($raw, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)]);
            $this->wallet->save();
        });
        $this->postJson($this->path('/quotes'), $this->input('DESTINATION_AMOUNT'))->assertStatus(503)
            ->assertJsonPath('reason_codes', ['dynamic_quote_wallet_balance_unavailable']);
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->assertSame(1, $this->walletReads);
        $this->noAdmission();
    }

    #[DataProvider('providerBalanceRepresentations')]
    public function test_exact_provider_balance_mirror_allows_quote_only_with_authenticated_snapshot(mixed $integer): void
    {
        $this->customerListing();
        $snapshot = null;
        $this->providerQuote('DESTINATION_AMOUNT', during: function () use ($integer, &$snapshot): void {
            $raw = $this->wallet->fresh()->raw_data;
            $snapshot = $raw['dynamic_authenticated_availability'];
            $raw['provider_available_balance'] = $integer;
            $this->wallet->refresh()->update(['raw_data' => $raw]);
        });
        $this->postJson($this->path('/quotes'), $this->input('DESTINATION_AMOUNT'))->assertCreated();
        $this->assertSame(1, $this->walletReads);
        $this->assertSame($integer, $this->wallet->fresh()->raw_data['provider_available_balance']);
        $this->assertEquals($snapshot, $this->wallet->fresh()->raw_data['dynamic_authenticated_availability']);
        $this->assertSame('2000.00000000', $this->wallet->fresh()->available_balance);
        $this->noAdmission();
    }

    #[DataProvider('providerBalanceRepresentations')]
    public function test_legacy_amount_without_authenticated_evidence_remains_blocked(mixed $value): void
    {
        $this->customerListing();
        $legacy = ['provider_available_balance' => $value];
        $this->providerQuote('DESTINATION_AMOUNT', during: function () use ($legacy): void {
            $this->assertArrayHasKey('dynamic_authenticated_availability', $this->wallet->fresh()->raw_data);
            // A generic sync during Quote HTTP revokes authentication. Never
            // reconstruct the snapshot from the remaining numeric mirror.
            $this->wallet->refresh()->update(['raw_data' => $legacy]);
        });
        $this->postJson($this->path('/quotes'), $this->input('DESTINATION_AMOUNT'))->assertStatus(503)
            ->assertJsonPath('reason_codes', ['dynamic_quote_wallet_balance_unavailable']);
        $this->assertSame(1, $this->walletReads);
        $this->assertSame($legacy, $this->wallet->fresh()->raw_data);
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
    }

    #[DataProvider('providerBalanceRepresentations')]
    public function test_legacy_amount_never_authenticated_remains_blocked(mixed $value): void
    {
        $this->customerListing();
        $this->nium->shouldNotReceive('post');
        $this->walletOutgoing = static fn (string $url): ?Request => null;
        $legacy = ['provider_available_balance' => $value];
        $this->wallet->refresh()->update(['raw_data' => $legacy]);
        $this->assertSame($legacy, $this->wallet->fresh()->raw_data);
        $this->assertSame(0, $this->walletReads);

        $this->postJson($this->path('/quotes'), $this->input('DESTINATION_AMOUNT'))->assertStatus(503)
            ->assertJsonPath('reason_codes', ['dynamic_wallet_authenticated_snapshot_unavailable']);
        $this->assertSame(1, $this->walletReads);
        $this->assertSame($legacy, $this->wallet->fresh()->raw_data);
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
    }

    public function test_snapshot_expiring_during_quote_http_is_not_refreshed_or_accepted(): void
    {
        $this->customerListing();
        $this->providerQuote('DESTINATION_AMOUNT', during: fn () => $this->travelTo(now()->addSeconds(30)));
        $this->postJson($this->path('/quotes'), $this->input('DESTINATION_AMOUNT'))->assertStatus(503)
            ->assertJsonPath('reason_codes', ['dynamic_quote_wallet_balance_unavailable']);
        $this->assertSame(1, $this->walletReads);
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
    }

    #[DataProvider('providerBalanceRepresentations')]
    public function test_confirm_reservation_and_execution_revalidation_share_exact_balance_normalization(mixed $integer): void
    {
        $review = $this->readyReview('DESTINATION_AMOUNT');
        $raw = $this->wallet->fresh()->raw_data;
        $raw['provider_available_balance'] = $integer;
        $this->wallet->update(['raw_data' => $raw]);
        $reads = $this->walletReads;
        $this->postJson($this->path(''), $this->submitReady($review))->assertCreated();
        $intent = NiumDynamicIntent::query()->sole();
        \App\Services\Wallet\Concurrency\OwnerFirst::users([$this->owner->id], function () use ($intent): void {
            $lockedIntent = NiumDynamicIntent::query()->lockForUpdate()->findOrFail($intent->id);
            NiumDynamicPayoutOutbox::query()->where('intent_id', $intent->id)->lockForUpdate()->sole();
            app(DynamicTransferConfirmationService::class)->revalidateForExecution($lockedIntent);
        });
        $this->assertSame($reads, $this->walletReads);
        $this->assertSame('1000.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame('1000.00000000', $this->wallet->fresh()->available_balance);
        $this->assertSame('2000.00000000', $this->wallet->fresh()->ledger_balance);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'hold')->count());
        $this->assertSame(0, NiumDynamicPayoutOutbox::query()->sole()->attempts);
    }

    public function test_normalized_integer_cannot_replace_the_authenticated_review_amount(): void
    {
        $review = $this->readyReview('DESTINATION_AMOUNT');
        $raw = $this->wallet->fresh()->raw_data;
        $raw['provider_available_balance'] = 1999;
        $this->wallet->update(['raw_data' => $raw]);
        $this->postJson($this->path(''), $this->submitReady($review))->assertStatus(409)
            ->assertJsonPath('reason_codes', ['dynamic_review_binding_changed']);
        $this->noAdmission();
    }

    public function test_authenticated_provider_funds_below_debit_remain_insufficient_despite_local_books(): void
    {
        $this->walletResponseBody = '[{"curSymbol":"USD","balance":999}]';
        $this->customerListing();
        $this->providerQuote('DESTINATION_AMOUNT');
        $this->postJson($this->path('/quotes'), $this->input('DESTINATION_AMOUNT'))->assertStatus(409)
            ->assertJsonPath('reason_codes', ['dynamic_insufficient_available_funds']);
        $this->assertSame(1, $this->walletReads);
        $this->assertSame('999.00000000', app(DynamicPayoutWalletSnapshot::class)
            ->current($this->owner, $this->wallet->fresh(), $this->account));
        $this->assertSame('2000.00000000', $this->wallet->fresh()->available_balance);
        $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
        $this->noAdmission();
    }

    #[DataProvider('providerBalanceRepresentations')]
    public function test_balance_including_existing_holds_is_checked_after_provider_response(mixed $integer): void
    {
        $this->wallet->update(['reserved_balance' => '1500']);
        $this->customerListing();
        $this->providerQuote(during: function () use ($integer): void {
            $raw = $this->wallet->fresh()->raw_data;
            $raw['provider_available_balance'] = $integer;
            $this->wallet->refresh()->update(['raw_data' => $raw]);
        });
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

    public static function quoteTransactionContexts(): array { return [[false], [true]]; }

    #[DataProvider('quoteTransactionContexts')]
    public function test_quote_preparation_cannot_refresh_inside_transactions_or_owner_locks(bool $ownerLock): void
    {
        $this->nium->shouldNotReceive('get');
        $this->nium->shouldNotReceive('post');
        $work = fn () => app(NiumDynamicPayoutQuoteService::class)
            ->create($this->owner, $this->beneficiary, $this->wallet, PayoutAmountMode::Source, '1000');
        try {
            if ($ownerLock) {
                \App\Services\Wallet\Concurrency\OwnerFirst::users([$this->owner->id], $work);
            } else {
                DB::transaction($work);
            }
            $this->fail('Quote preparation attempted HTTP inside a transaction.');
        } catch (\App\Services\Wallet\Concurrency\OwnerLockContractViolation) {
            $this->assertSame(0, $this->walletReads);
            $this->assertSame(0, NiumDynamicPayoutQuote::query()->count());
            $this->noAdmission();
        }
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
