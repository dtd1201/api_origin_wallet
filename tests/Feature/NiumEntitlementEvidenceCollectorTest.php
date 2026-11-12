<?php

namespace Tests\Feature;

use App\Domain\Nium\DynamicMultiCurrency\Financial\DocumentaryFeeSchedule;
use App\Models\IntegrationProvider;
use App\Models\NiumApiExchangeEvidence;
use App\Services\Integrations\ProviderHttpClient;
use App\Services\Nium\NiumDynamicQuoteFoundation;
use App\Services\Nium\NiumEntitlementEvidenceProjector;
use App\Services\Nium\NiumEntitlementEvidenceScope;
use App\Services\Nium\NiumEntitlementEvidenceTransport;
use Tests\Support\OwnerFirstPostgresDatabase as RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

final class NiumEntitlementEvidenceCollectorTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '11111111-1111-4111-8111-111111111111';

    private const CUSTOMER = '22222222-2222-4222-8222-222222222222';

    private const OTHER_CUSTOMER = '33333333-3333-4333-8333-333333333333';

    private const SECRET = 'evidence-test-api-key-must-never-survive';

    private string $evidenceCommandOutput = '';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.nium.base_url', 'https://gateway.nium.com');
        config()->set('services.nium.client_id', self::CLIENT);
        config()->set('services.nium.regulatory_region', 'HK');
        config()->set('services.nium.auth', ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => self::SECRET]);
        config()->set('nium_entitlement_evidence', [
            'approved_origins' => ['sandbox' => ['https://gateway.nium.com'], 'production' => []],
            'scopes' => ['scope-00000001' => [
                'verified' => true, 'verification_sha256' => str_repeat('a', 64),
                'app_environment' => 'testing', 'provider_environment' => 'sandbox',
                'origin' => 'https://gateway.nium.com', 'client_hash_id' => self::CLIENT,
                'customer_hash_id' => self::CUSTOMER, 'client_alias' => 'client-00000001',
                'customer_alias' => 'customer-00000001', 'regulatory_region' => 'HK',
                'corridors' => ['corridor-00000001' => ['verified' => true, ...$this->filters()]],
            ]],
        ]);
    }

    public function test_default_evidence_mode_is_a_plan_without_http_or_database_access(): void
    {
        [$http] = $this->fake();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void { $queries[] = $query->sql; });

        $this->assertSame(0, $this->runEvidenceCommand($this->evidenceCommandOptions(false)));
        $document = $this->document();
        $this->assertSame('NOT_COLLECTED', $document['status']);
        $this->assertSame('PLAN', $document['mode']);
        $this->assertSame([], $queries);
        $http->assertNothingSent();
        $this->assertNoSensitiveOutput();
        $this->assertDigest($document);
    }

    public function test_incompatible_modes_and_positional_customer_cannot_reach_network(): void
    {
        [$http] = $this->fake();
        foreach (['--sync', '--quote', '--full', '--client-capabilities', '--compliance-callback'] as $flag) {
            $this->assertSame(1, $this->runEvidenceCommand([...$this->evidenceCommandOptions(), $flag => true]));
        }
        foreach (['--source-currency' => 'USD', '--target-currency' => 'SGD', '--amount' => '1'] as $flag => $value) {
            $this->assertSame(1, $this->runEvidenceCommand([...$this->evidenceCommandOptions(), $flag => $value]));
        }
        $this->assertSame(1, $this->runEvidenceCommand([...$this->evidenceCommandOptions(), 'userId' => '17']));
        $withoutMode = $this->evidenceCommandOptions();
        unset($withoutMode['--entitlement-evidence']);
        $this->assertSame(1, $this->runEvidenceCommand($withoutMode));
        $http->assertNothingSent();
    }

    public function test_unverified_alias_environment_client_customer_and_corridor_fail_closed(): void
    {
        [$http] = $this->fake();
        foreach ([
            ['--evidence-scope' => 'scope-00000002'],
            ['--evidence-environment' => 'production'],
            ['--evidence-corridor' => ['corridor-00000002']],
        ] as $override) {
            $this->assertSame(1, $this->runEvidenceCommand([...$this->evidenceCommandOptions(), ...$override]));
        }
        foreach (['verified' => false, 'app_environment' => 'production', 'client_hash_id' => self::OTHER_CUSTOMER,
            'customer_hash_id' => 'not-a-provider-id'] as $key => $value) {
            $old = config('nium_entitlement_evidence.scopes.scope-00000001.'.$key);
            config()->set('nium_entitlement_evidence.scopes.scope-00000001.'.$key, $value);
            $this->assertSame(1, $this->runEvidenceCommand($this->evidenceCommandOptions()));
            config()->set('nium_entitlement_evidence.scopes.scope-00000001.'.$key, $old);
        }
        $http->assertNothingSent();
    }

    public function test_endpoint_method_host_and_filters_are_allowlisted_before_network(): void
    {
        [$http, $transport] = $this->fake();
        $scope = $this->scope();
        foreach ([['quotes', [], 'GET'], ['client_details', [], 'POST'],
            ['https://other.example/fees', [], 'GET'], ['supported_corridors', [...$this->filters(), 'destinationCountry' => 'US'], 'GET'],
            ['payout_schema', [], 'GET']] as [$endpoint, $filters, $method]) {
            try {
                $transport->read($scope, $endpoint, $filters, 0, $method);
                $this->fail('Non-allowlisted request was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertStringStartsWith('evidence_', $exception->getMessage());
            }
        }
        foreach (['https://attacker.example', 'https://gateway.nium.com:443', 'https://gateway.nium.com/path',
            'https://user:password@gateway.nium.com', 'https://gateway.nium.com?key=secret'] as $origin) {
            config()->set('nium_entitlement_evidence.scopes.scope-00000001.origin', $origin);
            config()->set('nium_entitlement_evidence.approved_origins.sandbox', [$origin]);
            config()->set('services.nium.base_url', $origin);
            $this->assertSame(1, $this->runEvidenceCommand($this->evidenceCommandOptions()));
        }
        $http->assertNothingSent();
    }

    public function test_live_collection_uses_isolated_gets_and_has_no_database_or_application_http_events(): void
    {
        Http::globalRequestMiddleware(function () { throw new RuntimeException('Global HTTP factory was used.'); });
        Event::listen(RequestSending::class, function () { throw new RuntimeException('Application HTTP event was emitted.'); });
        $options = [];
        [$http] = $this->fake(function (Request $request, array $requestOptions) use (&$options) {
            $options[] = $requestOptions;
            $this->assertSame('GET', $request->method());
            $this->assertTrue($request->hasHeader('x-api-key', self::SECRET));

            return $this->response($request);
        });
        $queries = [];
        DB::listen(function ($query) use (&$queries): void { $queries[] = $query->sql; });
        $this->assertSame(2, $this->runEvidenceCommand($this->evidenceCommandOptions()));
        $http->assertSentCount(7);
        $this->assertSame([], $queries);
        foreach ($options as $requestOptions) {
            $this->assertFalse($requestOptions['allow_redirects']);
            $this->assertFalse($requestOptions['debug']);
            $this->assertFalse($requestOptions['cookies']);
            $this->assertTrue($requestOptions['verify']);
        }
        $this->assertSame('INCOMPLETE', $this->document()['status']);
        $this->assertSame('UNKNOWN', $this->document()['entitlement_decision']);
        $this->assertNoSensitiveOutput();
        $this->assertDigest($this->document());
    }

    public function test_unavailable_isolation_or_non_header_auth_fails_before_network(): void
    {
        [$http] = $this->fake();
        config()->set('services.nium.auth.mode', 'bearer_token');
        $this->assertSame(1, $this->runEvidenceCommand($this->evidenceCommandOptions()));
        config()->set('services.nium.auth.mode', 'header');
        $http->globalRequestMiddleware(fn ($request) => $request);
        $this->assertSame(1, $this->runEvidenceCommand($this->evidenceCommandOptions()));
        $http->assertNothingSent();
        $this->assertDatabaseCount('api_request_logs', 0);
    }

    public function test_success_projection_drops_nested_unknown_values_ids_pii_accounts_and_unverified_text(): void
    {
        $this->fake(function (Request $request) {
            if (parse_url($request->url(), PHP_URL_PATH) === '/api/v1/client/'.self::CLIENT) {
                return Factory::response([...$this->client(), 'clientHashId' => self::CLIENT,
                    'unknown' => ['accountNumber' => 'ACCOUNT-MUST-NOT-SURVIVE', 'email' => 'private@example.test'],
                    'name' => 'PERSON-MUST-NOT-SURVIVE', 'token' => 'TOKEN-MUST-NOT-SURVIVE',
                    'currencies' => [[...$this->client()['currencies'][0], 'nested' => ['secret' => self::SECRET]]]],
                    200, ['Set-Cookie' => 'COOKIE-MUST-NOT-SURVIVE', 'X-Random' => 'HEADER-MUST-NOT-SURVIVE']);
            }

            return $this->response($request);
        });
        $this->assertSame(2, $this->runEvidenceCommand($this->evidenceCommandOptions()));
        $client = $this->document()['results'][0];
        $this->assertSame('MULTI', $client['data']['currency_mode']);
        $this->assertFalse($client['data']['currencies'][0]['can_fx_sell']);
        $this->assertTrue($client['data']['currencies'][0]['can_remit']);
        $this->assertSame('UNKNOWN', $client['unknown_fields']['status']);
        $this->assertNoSensitiveOutput();
        $this->assertDatabaseCount('api_request_logs', 0);
        $this->assertDatabaseCount('nium_api_exchange_evidence', 0);
    }

    public function test_pagination_keeps_all_duplicate_rows_and_distinct_variants(): void
    {
        $this->fake(function (Request $request) {
            if (str_contains($request->url(), '/api/v3/') && str_contains($request->url(), '/supportedCorridors')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $rows = (int) $query['page'] === 0 ? array_fill(0, 100, $this->corridor())
                    : [[...$this->corridor(), 'payoutMethodCategory' => 'proxy', 'routingCodeType' => 'BANK CODE',
                        'supportingDocuments' => [['private' => 'DOCUMENT-MUST-NOT-SURVIVE']]]];

                return Factory::response(['content' => $rows, 'totalPages' => 2, 'totalElements' => 101]);
            }

            return $this->response($request);
        });
        $this->assertSame(2, $this->runEvidenceCommand($this->evidenceCommandOptions()));
        $corridors = $this->document()['results'][1];
        $this->assertTrue($corridors['pagination']['pagination_complete']);
        $this->assertSame(101, $corridors['pagination']['received_rows']);
        $this->assertCount(100, $corridors['pages'][0]['data']['rows']);
        $this->assertSame('wireLocal', $corridors['pages'][0]['data']['rows'][0]['payoutMethodCategory']);
        $this->assertSame('proxy', $corridors['pages'][1]['data']['rows'][0]['payoutMethodCategory']);
        $this->assertSame('UNKNOWN', $corridors['pages'][1]['data']['rows'][0]['unprojected_restrictions']);
        $this->assertSame(['page' => 1, 'size' => 100, 'order' => 'ASC'],
            array_intersect_key($corridors['pages'][1]['manifest']['query'], array_flip(['page', 'size', 'order'])));
        $this->assertNoSensitiveOutput();
    }

    public function test_redirect_error_timeout_and_invalid_json_are_not_retried_or_persisted(): void
    {
        foreach (['redirect', '429', '403', 'timeout', 'json'] as $failure) {
            $attempts = 0;
            $this->fake(function () use ($failure, &$attempts) {
                $attempts++;
                if ($failure === 'timeout') {
                    throw new ConnectionException('private@example.test '.self::SECRET);
                }
                if ($failure === 'json') {
                    return Factory::response('private@example.test malformed json');
                }

                return Factory::response(['token' => 'TOKEN-MUST-NOT-SURVIVE'], $failure === 'redirect' ? 302 : (int) $failure,
                    ['Location' => 'https://attacker.example/private@example.test']);
            });
            $queries = [];
            DB::listen(function ($query) use (&$queries): void { $queries[] = $query->sql; });
            $this->assertSame(2, $this->runEvidenceCommand($this->evidenceCommandOptions()));
            $this->assertSame(7, $attempts); // Exactly one attempt for each approved resource, no redirects or fallback.
            $this->assertSame([], $queries);
            $this->assertSame('INCOMPLETE', $this->document()['results'][0]['status']);
            $this->assertFalse($this->document()['results'][1]['pagination']['pagination_complete']);
            $this->assertNoSensitiveOutput();
        }
        $this->assertDatabaseCount('api_request_logs', 0);
        $this->assertDatabaseCount('nium_api_exchange_evidence', 0);
    }

    public function test_pagination_drift_missing_totals_and_row_count_mismatch_are_incomplete(): void
    {
        foreach (['drift', 'missing', 'count', 'limit', 'foreign_row'] as $failure) {
            $this->fake(function (Request $request) use ($failure) {
                if (str_contains($request->url(), '/api/v3/') && str_contains($request->url(), '/supportedCorridors')) {
                    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                    $row = $failure === 'foreign_row' ? [...$this->corridor(), 'destinationCountry' => 'US'] : $this->corridor();
                    $body = ['content' => [$row], 'totalPages' => $failure === 'limit' ? 101 : 2,
                        'totalElements' => $failure === 'drift' && (int) $query['page'] === 1 ? 3 : 2];
                    if ($failure === 'missing') { unset($body['totalPages']); }
                    if ($failure === 'count') { $body['totalPages'] = 1; }

                    return Factory::response($body);
                }

                return $this->response($request);
            });
            $this->assertSame(2, $this->runEvidenceCommand($this->evidenceCommandOptions()));
            $this->assertSame('INCOMPLETE', $this->document()['results'][1]['status']);
            $this->assertFalse($this->document()['results'][1]['pagination']['pagination_complete']);
        }
    }

    public function test_missing_currency_flags_remain_missing_and_unverified_schemas_remain_unknown(): void
    {
        $this->fake(function (Request $request) {
            if (parse_url($request->url(), PHP_URL_PATH) === '/api/v1/client/'.self::CLIENT) {
                return Factory::response(['currencies' => [['currencyCode' => 'USD']]]);
            }

            return $this->response($request);
        });
        $this->assertSame(2, $this->runEvidenceCommand($this->evidenceCommandOptions()));
        $results = $this->document()['results'];
        $this->assertArrayNotHasKey('can_fx_sell', $results[0]['data']['currencies'][0]);
        $this->assertArrayNotHasKey('can_remit', $results[0]['data']['currencies'][0]);
        $this->assertContains('fxSellAllowed', $results[0]['missing_fields']);
        foreach ($results as $result) {
            if (in_array($result['endpoint'], ['customer_corridors', 'payout_schema', 'fee_plans'], true)) {
                $this->assertSame('UNKNOWN', $result['status']);
            }
        }
    }

    public function test_response_client_or_region_mismatch_is_incomplete_without_exporting_identity(): void
    {
        foreach ([['clientHashId' => self::OTHER_CUSTOMER], ['regulatoryRegion' => 'SG']] as $override) {
            $this->fake(fn (Request $request) => parse_url($request->url(), PHP_URL_PATH) === '/api/v1/client/'.self::CLIENT
                ? Factory::response([...$this->client(), ...$override]) : $this->response($request));
            $this->assertSame(2, $this->runEvidenceCommand($this->evidenceCommandOptions()));
            $this->assertSame('INCOMPLETE', $this->document()['results'][0]['status']);
            $this->assertNoSensitiveOutput();
        }
    }

    public function test_signed_fee_matching_mismatch_and_conditional_rules_cannot_become_transaction_evidence(): void
    {
        [$http] = $this->fake();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void { $queries[] = $query->sql; });
        $signed = DocumentaryFeeSchedule::signedReference();
        $projector = new NiumEntitlementEvidenceProjector;
        $scope = $this->scope();
        config()->set('nium_dynamic_quotes.quote_contract_enabled', true);
        $foundation = new NiumDynamicQuoteFoundation;

        // These are offline, documented v3 shapes, not an authenticated account
        // response. A price match, zero, absence, new margin or tier supplies no
        // customer assignment, fee collection, all-in debit or current amendment.
        $rules = [
            ['type' => 'nontier', 'price' => ['currency' => 'USD', 'type' => 'flat', 'value' => $signed['contracted_local'][0]['fee']]],
            ['type' => 'nontier', 'price' => ['currency' => 'USD', 'type' => 'flat', 'value' => '19']],
            ['type' => 'nontier', 'price' => ['currency' => 'USD', 'type' => 'flat', 'value' => '0']],
            ['type' => 'nontier', 'price' => ['currency' => null, 'type' => 'flat', 'value' => '20']],
            ['type' => 'nontier', 'price' => ['currency' => null, 'type' => 'percentage', 'percentageOf' => 'source_amount', 'value' => '0.30']],
            ['type' => 'tier', 'condition' => ['operands' => [['sourceCurrency' => 'USD'], ['destinationCurrency' => 'HKD']], 'operator' => 'and'],
                'tier' => ['on' => ['aggregatedBy' => 'aggregated_volume', 'currency' => 'USD', 'parameter' => 'source_amount'],
                    'ranges' => [['start' => '0', 'end' => '100', 'price' => ['currency' => 'USD', 'type' => 'flat', 'value' => '20']]]]],
        ];
        foreach ($rules as $rule) {
            $fx = ($rule['price']['type'] ?? null) === 'percentage';
            $body = ['feePlans' => [[
                'isDefault' => true,
                'planName' => 'synthetic-signed-fee-plan',
                'products' => [[
                    'name' => $fx ? 'fx' : 'payout',
                    'fees' => [[
                        'fee' => $fx ? 'fx_markup_lock_15mins' : 'remit_bank_fee',
                        'rules' => [$rule],
                    ]],
                ]],
            ]]];
            $before = serialize($body);
            $result = $projector->project('fee_plans', $body, $scope);

            $this->assertSame('UNKNOWN', $result['status']);
            $this->assertSame('fee_rules_and_binding_unverified', $result['reason']);
            $this->assertSame(['plans' => [['plan_alias' => 'plan-'.substr(hash('sha256', 'synthetic-signed-fee-plan'), 0, 16), 'is_default' => true]],
                'fee_rules_status' => 'UNKNOWN', 'customer_applicability' => 'UNKNOWN'], $result['data']);
            $this->assertSame($before, serialize($body));
            $rejected = $foundation->review(fn () => $result);
            $this->assertSame('UNAVAILABLE', $rejected['status']);
            $this->assertSame(['trusted_payout_evidence_unavailable'], $rejected['reason_codes']);
            $this->assertSame('DENIED', $rejected['dispatch']);
        }
        // Documentary data itself cannot cross the typed confirmation boundary.
        $this->assertSame('UNAVAILABLE', $foundation->review(fn () => $signed)['status']);
        $this->assertFalse(app()->bound(\App\Services\Nium\DynamicConfirmation\DynamicPayoutEvidenceSource::class));
        $this->assertSame([], $queries);
        $http->assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_v2_fee_templates_are_not_silently_interpreted_as_v3_fee_plans(): void
    {
        [$http] = $this->fake();
        // v2 uses numeric feeValue, ANY/null currencies and slabs; it is not the
        // collector's v3 feePlans contract and must not supply a USD fee fallback.
        $v2 = [['default' => true, 'segment' => 'synthetic-v2-plan', 'status' => 'ACTIVE', 'fees' => [[
            'authCurrency' => 'ANY', 'feeCurrency' => null, 'feeName' => 'REMIT_BANK_FEE',
            'feeValue' => 20, 'fixed' => true, 'slabFrom' => 0, 'slabTo' => 999999,
            'thresholdFeeValue' => 99, 'status' => 'ACTIVE', 'transactionCurrency' => 'ANY',
        ]]]];

        $result = (new NiumEntitlementEvidenceProjector)->project('fee_plans', $v2, $this->scope());

        $this->assertSame('INCOMPLETE', $result['status']);
        $this->assertSame(['fee_plan_shape'], $result['invalid_fields']);
        $this->assertSame([], $result['data']);
        $http->assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_schema_definitions_never_emit_examples_defaults_account_enums_or_unknown_fields(): void
    {
        $this->fake(function (Request $request) {
            if (str_contains($request->url(), '/validationSchemas')) {
                return Factory::response(['type' => 'object', 'properties' => [
                    'beneficiaryAccountNumber' => ['type' => 'string', 'minLength' => 6, 'enum' => ['ACCOUNT-MUST-NOT-SURVIVE'],
                        'default' => 'DEFAULT-MUST-NOT-SURVIVE', 'examples' => ['private@example.test']],
                    'beneficiaryName' => ['type' => 'string', 'pattern' => 'PERSON-MUST-NOT-SURVIVE'],
                    'UNKNOWN-PRIVATE-FIELD' => ['type' => 'string', 'const' => self::SECRET],
                ], 'required' => ['beneficiaryAccountNumber']]);
            }

            return $this->response($request);
        });
        $this->assertSame(2, $this->runEvidenceCommand($this->evidenceCommandOptions()));
        $schema = $this->document()['results'][3];
        $this->assertTrue($schema['data']['definitions'][0]['required']);
        $this->assertSame('UNKNOWN', $schema['data']['definitions'][0]['allowed_values_status']);
        $this->assertSame('UNKNOWN', $schema['data']['definitions'][1]['pattern_status']);
        $this->assertNoSensitiveOutput();
        $this->assertStringNotContainsString('UNKNOWN-PRIVATE-FIELD', $this->evidenceCommandOutput);
    }

    public function test_conditional_or_malformed_beneficiary_schema_does_not_invent_required_fields(): void
    {
        foreach ([
            ['type' => 'object', 'properties' => ['beneficiaryName' => ['type' => 'string']],
                'if' => ['properties' => ['customerType' => ['const' => 'CORPORATE']]], 'then' => ['required' => ['beneficiaryName']]],
            ['fields' => [['fieldName' => 'beneficiaryName', 'required' => 'false', 'type' => 'string']]],
            ['type' => 'object', 'properties' => [], 'required' => ['beneficiaryName']],
        ] as $body) {
            $this->fake(fn (Request $request) => str_contains($request->url(), '/validationSchemas')
                ? Factory::response($body) : $this->response($request));
            $this->assertSame(2, $this->runEvidenceCommand($this->evidenceCommandOptions()));
            $schema = $this->document()['results'][3];
            $this->assertContains($schema['status'], ['UNKNOWN', 'INCOMPLETE']);
            $this->assertSame([], $schema['data']);
        }
    }

    public function test_customer_isolation_uses_only_the_explicit_approved_customer_without_cache(): void
    {
        $second = config('nium_entitlement_evidence.scopes.scope-00000001');
        $second['customer_hash_id'] = self::OTHER_CUSTOMER;
        $second['customer_alias'] = 'customer-00000002';
        config()->set('nium_entitlement_evidence.scopes.scope-00000002', $second);
        [$http] = $this->fake();
        $this->assertSame(2, $this->runEvidenceCommand([...$this->evidenceCommandOptions(), '--evidence-scope' => 'scope-00000002']));
        $http->assertNotSent(fn (Request $request) => str_contains($request->url(), self::CUSTOMER));
        $http->assertSent(fn (Request $request) => str_contains($request->url(), self::OTHER_CUSTOMER));
        $this->assertSame('customer-00000002', $this->document()['scope']['customer_alias']);
        $this->assertNoSensitiveOutput();
    }

    public function test_client_and_environment_switch_requires_another_approved_scope_and_fresh_reads(): void
    {
        [$http] = $this->fake(function (Request $request) {
            if (parse_url($request->url(), PHP_URL_PATH) === '/api/v1/client/'.self::OTHER_CUSTOMER) {
                $body = $this->client();
                $body['currencies'][0]['currencyCode'] = 'EUR';

                return Factory::response($body);
            }

            return $this->response($request);
        });
        $this->assertSame(2, $this->runEvidenceCommand($this->evidenceCommandOptions()));
        $this->assertSame('USD', $this->document()['results'][0]['data']['currencies'][0]['currency']);

        $scope = config('nium_entitlement_evidence.scopes.scope-00000001');
        $scope['provider_environment'] = 'production';
        $scope['origin'] = 'https://gateway.nium.com'; // Environment requires its own explicit approval, even on the same origin.
        $scope['client_hash_id'] = self::OTHER_CUSTOMER;
        $scope['client_alias'] = 'client-00000002';
        config()->set('nium_entitlement_evidence.approved_origins.production', [$scope['origin']]);
        config()->set('nium_entitlement_evidence.scopes.scope-00000002', $scope);
        config()->set('services.nium.base_url', $scope['origin']);
        config()->set('services.nium.client_id', self::OTHER_CUSTOMER);
        $this->assertSame(2, $this->runEvidenceCommand([...$this->evidenceCommandOptions(),
            '--evidence-scope' => 'scope-00000002', '--evidence-environment' => 'production']));
        $this->assertSame('EUR', $this->document()['results'][0]['data']['currencies'][0]['currency']);
        $this->assertSame('production', $this->document()['scope']['provider_environment']);
        $http->assertSentCount(14);
        $http->assertSent(fn (Request $request) => str_starts_with($request->url(), $scope['origin'].'/api/v1/client/'.self::OTHER_CUSTOMER));
        $this->assertSame(1, $this->runEvidenceCommand($this->evidenceCommandOptions()));
    }

    public function test_client_only_scope_never_expands_to_customer_or_all_corridors(): void
    {
        config()->set('nium_entitlement_evidence.scopes.scope-00000001.customer_hash_id', null);
        config()->set('nium_entitlement_evidence.scopes.scope-00000001.customer_alias', null);
        [$http] = $this->fake();
        $this->assertSame(2, $this->runEvidenceCommand([...$this->evidenceCommandOptions(), '--evidence-corridor' => []]));
        $http->assertSentCount(3);
        $http->assertNotSent(fn (Request $request) => str_contains($request->url(), '/customer/') || str_contains($request->url(), '/supportedCorridors'));
    }

    public function test_existing_hk_swift_transport_still_uses_its_existing_persistence_path(): void
    {
        Http::fake(['*' => Http::response(['systemReferenceNumber' => 'hk-existing-response'], 200)]);
        $provider = IntegrationProvider::create(['code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        $payload = ['payout' => ['sourceCurrency' => 'USD', 'destinationCurrency' => 'USD', 'payoutMethod' => 'SWIFT', 'sourceAmount' => 1]];
        (new ProviderHttpClient($provider, 'nium'))->post('/api/v1/client/'.self::CLIENT.'/customer/'.self::CUSTOMER.'/wallet/'.self::OTHER_CUSTOMER.'/remittance', $payload);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->data() === $payload);
        $this->assertDatabaseCount('api_request_logs', 1);
        $this->assertSame(['systemReferenceNumber' => 'hk-existing-response'], json_decode(NiumApiExchangeEvidence::sole()->raw_response_body, true));
    }

    private function fake(?\Closure $callback = null): array
    {
        $factory = new Factory;
        $factory->preventStrayRequests();
        $factory->fake($callback ?? fn (Request $request) => $this->response($request));
        $transport = new NiumEntitlementEvidenceTransport;
        // Inject only the local fake factory. Production construction has no injection hooks.
        (new ReflectionProperty($transport, 'http'))->setValue($transport, $factory);
        app()->instance(NiumEntitlementEvidenceTransport::class, $transport);

        return [$factory, $transport];
    }

    private function response(Request $request): mixed
    {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $body = match (true) {
            $path === '/api/v1/client/'.self::CLIENT => $this->client(),
            str_contains($path, '/api/v3/') && str_contains($path, '/supportedCorridors') => ['content' => [$this->corridor()], 'totalPages' => 1, 'totalElements' => 1],
            str_contains($path, '/supportedCorridors') => ['unverifiedCustomerResponse' => ['id' => self::CUSTOMER]],
            str_contains($path, '/validationSchemas') => ['fields' => [['fieldName' => 'beneficiaryAccountNumber', 'required' => true, 'type' => 'string', 'minLength' => 6]]],
            str_contains($path, '/payout/schema') => ['unverifiedSchema' => ['accountNumber' => 'ACCOUNT-MUST-NOT-SURVIVE']],
            str_contains($path, '/fees') => ['feePlans' => [['isDefault' => false, 'planName' => 'PERSON-MUST-NOT-SURVIVE', 'products' => [['secret' => self::SECRET]]]]],
            $path === '/api/v1/remittance/purposeCodes' => [['purposeCode' => 'IR01811', 'description' => 'DESCRIPTION-MUST-NOT-SURVIVE']],
            default => throw new RuntimeException('Unexpected fake request.'),
        };

        return Factory::response($body);
    }

    private function client(): array
    {
        return ['currencyAuthorizationType' => 'MULTI', 'regulatoryRegion' => 'HK', 'currencies' => [
            ['currencyCode' => 'USD', 'fxSellAllowed' => false, 'remittanceAllowed' => true, 'decimalUnit' => 2, 'authorizationOrder' => 0],
        ]];
    }

    private function corridor(): array
    {
        return [...$this->filters(), 'payoutMethodCategory' => 'wireLocal', 'routingCodeType' => 'SWIFT',
            'customerCountry' => ['HK'], 'minimumAmount' => '1', 'maximumAmount' => '10000', 'limitCurrency' => 'SGD'];
    }

    private function filters(): array
    {
        return ['destinationCountry' => 'SG', 'destinationCurrency' => 'SGD', 'payoutMethod' => 'LOCAL',
            'customerType' => 'CORPORATE', 'beneficiaryAccountType' => 'INDIVIDUAL'];
    }

    private function runEvidenceCommand(array $options): int
    {
        $this->evidenceCommandOutput = '';
        $exitCode = Artisan::call('nium:smoke-test', $options);
        // Artisan::output() drains the buffer; retain the full output for every assertion.
        $this->evidenceCommandOutput = Artisan::output();

        return $exitCode;
    }

    private function evidenceCommandOptions(bool $live = true): array
    {
        return ['--entitlement-evidence' => true, '--live' => $live, '--evidence-environment' => 'sandbox',
            '--evidence-scope' => 'scope-00000001', '--evidence-corridor' => ['corridor-00000001']];
    }

    private function scope(): NiumEntitlementEvidenceScope
    {
        return NiumEntitlementEvidenceScope::resolve('scope-00000001', 'sandbox', ['corridor-00000001']);
    }

    private function document(): array
    {
        return json_decode($this->evidenceCommandOutput, true, 64, JSON_THROW_ON_ERROR);
    }

    private function assertNoSensitiveOutput(): void
    {
        foreach ([self::CLIENT, self::CUSTOMER, self::OTHER_CUSTOMER, self::SECRET, 'private@example.test',
            'ACCOUNT-MUST-NOT-SURVIVE', 'PERSON-MUST-NOT-SURVIVE', 'TOKEN-MUST-NOT-SURVIVE',
            'COOKIE-MUST-NOT-SURVIVE', 'HEADER-MUST-NOT-SURVIVE', 'DOCUMENT-MUST-NOT-SURVIVE',
            'DESCRIPTION-MUST-NOT-SURVIVE', 'DEFAULT-MUST-NOT-SURVIVE'] as $value) {
            $this->assertStringNotContainsString($value, $this->evidenceCommandOutput);
        }
    }

    private function assertDigest(array $document): void
    {
        $digest = $document['sanitized_sha256'];
        unset($document['sanitized_sha256']);
        $this->assertSame(hash('sha256', json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $digest);
    }
}
