<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\{ApiRequestLog, LedgerEntry, NiumApiExchangeEvidence, NiumDynamicIntent, NiumDynamicPayoutOutbox, NiumDynamicReservation};
use App\Services\Nium\DynamicConfirmation\{DynamicBeneficiaryDetailsEvidence, DynamicReceivingAccountBinding, DynamicRegisteredAccountEvidence};
use App\Services\Nium\{NiumBeneficiaryService, NiumService};
use App\Services\Wallet\Concurrency\OwnerFirst;
use App\Services\Wallet\Concurrency\OwnerTransactionRollbackOnly;
use GuzzleHttp\Psr7\{Request as PsrRequest, Response as PsrResponse};
use Illuminate\Http\Client\{ConnectionException, Request, Response};
use Illuminate\Support\Facades\{Cache, Crypt, DB};
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesDynamicConfirmationFixtures;
use Tests\Support\DynamicConfirmationDatabaseTestCase;
use Tests\Support\SyntheticDynamicBeneficiarySchema;

/** NiumService is mocked; no HTTP, ledger, reservation or provider payout is permitted. */
final class NiumDynamicBeneficiaryDetailsEvidenceTest extends DynamicConfirmationDatabaseTestCase
{
    use CreatesDynamicConfirmationFixtures;

    private NiumService $nium;
    private array $binding;
    private array $registered;
    private ?string $schemaBody = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDynamicFixtures();
        config(['services.nium.base_url' => 'https://api.spend.nium.com', 'services.nium.client_id' => 'fixture-client',
            'services.nium.auth.mode' => 'header', 'services.nium.auth.header_name' => 'x-api-key',
            'services.nium.auth.header_value' => 'synthetic-details-key',
            'services.nium.beneficiary_validation_schema_endpoint' => '/api/v2/client/{clientHashId}/customer/{customerHashId}/currency/{currencyCode}/validationSchemas']);
        $this->beneficiary->update(['country_code' => 'SG', 'currency' => 'SGD', 'account_number' => '0012345678',
            'bank_name' => 'Synthetic Bank', 'swift_bic' => 'TESTSGSGXXX',
            'raw_data' => ['nium' => ['payoutMethod' => 'LOCAL', 'beneficiaryBankAccountType' => 'Savings']]]);
        $this->registered = app(NiumBeneficiaryService::class)->receivingAccountBinding($this->beneficiary);
        $request = json_encode($this->registered, JSON_THROW_ON_ERROR);
        $response = json_encode(['beneficiaryHashId' => $this->beneficiary->external_beneficiary_id], JSON_THROW_ON_ERROR);
        $url = rtrim((string) config('services.nium.base_url'), '/').'/api/v2/client/[REDACTED]/customer/[REDACTED]/beneficiaries';
        $log = ApiRequestLog::query()->create(['user_id' => $this->owner->id, 'provider_id' => $this->account->provider_id,
            'operation' => 'beneficiary_create', 'client_hash_id' => 'fixture-client', 'request_method' => 'POST',
            'request_url' => $url, 'is_success' => true, 'response_status' => 200]);
        NiumApiExchangeEvidence::query()->create(['api_request_log_id' => $log->id, 'request_method' => 'POST',
            'request_url' => $log->request_url,
            'raw_request_body' => $request, 'raw_response_body' => $response,
            'request_body_sha256' => hash('sha256', $request), 'response_body_sha256' => hash('sha256', $response),
            'capture_version' => 1, 'response_received' => true]);
        $this->binding = ['base_url' => 'https://api.spend.nium.com', 'client' => 'fixture-client',
            'customer' => 'fixture-customer', 'provider_account_id' => $this->account->id,
            'owner' => (string) $this->owner->id, 'beneficiary_id' => $this->beneficiary->id,
            'destination_country' => 'SG', 'destination_currency' => 'SGD', 'payout_method' => 'LOCAL',
            'current_receiving_record_sha256' => DynamicReceivingAccountBinding::fingerprint($this->beneficiary),
            'registration_log_id' => $log->id, 'registration_request_sha256' => hash('sha256', $request),
            'registration_response_sha256' => hash('sha256', $response)];
        $this->nium = Mockery::mock(NiumService::class)->makePartial();
        $this->nium->shouldReceive('clientId')->andReturnUsing(static fn () => (string) config('services.nium.client_id'));
        $this->nium->shouldNotReceive('post');
        $this->app->instance(NiumService::class, $this->nium);
    }

    private function body(array $changes = []): string
    {
        $fields = $this->registered;
        unset($fields['clientLegalEntity']);
        return json_encode(['beneficiaryHashId' => $this->beneficiary->external_beneficiary_id,
            'beneficiaryBankCode' => null, 'routingCodeType2' => null, 'routingCodeValue2' => null,
            ...$fields, ...$changes], JSON_THROW_ON_ERROR);
    }

    private function expectDetails(?string $body = null, int $status = 200, ?\Closure $outgoing = null, ?\Closure $during = null): void
    {
        $this->expectSchema();
        $this->nium->shouldReceive('get')->withArgs(static fn ($path, $query, $user, $operation) => $operation === 'dynamic_beneficiary_details')
            ->once()->andReturnUsing(function ($path, $query, $user, $operation,
            $reference, $beforeDispatch) use ($body, $status, $outgoing, $during): Response {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('dynamic_beneficiary_details', $operation);
            $this->assertSame('/api/v2/client/fixture-client/customer/fixture-customer/beneficiaries/fixture-registered-payee', $path);
            $this->assertSame([], $query);
            $this->assertSame($this->owner->id, $user->id);
            $url = 'https://api.spend.nium.com'.$path;
            $request = $outgoing === null ? new Request(new PsrRequest('GET', $url, ['x-api-key' => 'synthetic-details-key'])) : $outgoing($url);
            if ($request !== null) $beforeDispatch($request);
            if ($during !== null) $during();
            return new Response(new PsrResponse($status, ['Content-Type' => 'application/json'], $body ?? $this->body()));
        });
    }

    private function expectSchema(int $status = 200, ?\Closure $outgoing = null): void
    {
        $this->nium->shouldReceive('get')->withArgs(static fn ($path, $query, $user, $operation) => $operation === 'dynamic_beneficiary_binding_schema')
            ->once()->andReturnUsing(function ($path, $query, $user, $operation, $reference, $beforeDispatch) use ($status, $outgoing): Response {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertSame('/api/v2/client/fixture-client/customer/fixture-customer/currency/'.$this->beneficiary->currency.'/validationSchemas', $path);
                $this->assertSame($this->beneficiary->country_code, $query['destinationCountry']);
                $this->assertSame($this->beneficiary->currency, $query['destinationCurrency']);
                $this->assertSame($this->beneficiary->payout_method, $query['payoutMethod']);
                $this->assertSame($this->owner->id, $user->id);
                $url = 'https://api.spend.nium.com'.$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
                $request = $outgoing === null ? new Request(new PsrRequest('GET', $url, ['x-api-key' => 'synthetic-details-key'])) : $outgoing($url);
                if ($request !== null) $beforeDispatch($request);
                return new Response(new PsrResponse($status, ['Content-Type' => 'application/json'], $this->schemaBody ?? SyntheticDynamicBeneficiarySchema::body()));
            });
    }

    private function inspect(bool $refresh = true): array
    {
        return app(DynamicRegisteredAccountEvidence::class)->inspect($this->beneficiary->fresh(), $this->account->fresh(), $this->binding, $refresh);
    }

    private function assertBlocked(): void
    {
        $result = $this->inspect();
        $this->assertFalse($result['customer_scope_confirmed']);
        $this->assertContains('dynamic_registered_account_customer_scope_unavailable', $result['reason_codes']);
        $this->assertSame(0, NiumDynamicIntent::query()->count());
        $this->assertSame(0, NiumDynamicPayoutOutbox::query()->count());
        $this->assertSame(0, NiumDynamicReservation::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_customer_scoped_get_binds_old_registration_and_reuses_sealed_evidence_without_another_get(): void
    {
        $this->expectDetails();
        $result = $this->inspect();
        $this->assertSame([], $result['reason_codes']);
        $this->binding['beneficiary_customer_binding_sha256'] = $result['customer_binding_sha256'];
        $snapshot = app(DynamicRegisteredAccountEvidence::class)->bindingObservation($this->beneficiary, $this->account, $this->binding);
        $this->assertSame('fixture-customer', $snapshot['scope']['customer']);
        $this->assertSame('GET', $snapshot['scope']['method']);
        $this->assertSame(hash('sha256', $this->body()), $snapshot['response_sha256']);
        $this->assertSame(hash('sha256', SyntheticDynamicBeneficiarySchema::body()), $snapshot['schema_sha256']);
        $this->assertSame(now()->addSeconds(30)->toISOString(), $snapshot['expires_at']);
        $this->assertSame($result, $this->inspect());
        $this->travelTo(now()->addSeconds(30));
        $this->assertFalse($this->inspect(false)['customer_scope_confirmed']);
    }

    public function test_case_only_bank_echo_binds_customer_scoped_details_without_rewriting_registered_evidence(): void
    {
        $body = $this->body(['beneficiaryBankName' => 'SYNTHETIC BANK',
            'customerHashId' => 'fixture-customer', 'clientHashId' => 'fixture-client']);
        $this->expectDetails($body);
        $result = $this->inspect();
        $this->assertTrue($result['payload_matches']);
        $this->assertTrue($result['customer_scope_confirmed']);
        $this->assertSame([], $result['reason_codes']);
        $this->binding['beneficiary_customer_binding_sha256'] = $result['customer_binding_sha256'];
        $snapshot = app(DynamicRegisteredAccountEvidence::class)->bindingObservation($this->beneficiary, $this->account, $this->binding);
        $this->assertSame('fixture-customer', $snapshot['scope']['customer']);
        $this->assertSame('fixture-client', $snapshot['scope']['client']);
        $this->assertSame($body, $snapshot['response_json']);
        $this->assertSame(hash('sha256', $body), $snapshot['response_sha256']);
        $this->assertSame('Synthetic Bank', $this->beneficiary->fresh()->bank_name);
        $exchange = NiumApiExchangeEvidence::query()->sole();
        $this->assertArrayNotHasKey('customerHashId', json_decode($exchange->raw_response_body, true, 64, JSON_THROW_ON_ERROR));
        $this->assertSame('Synthetic Bank', json_decode($exchange->raw_request_body, true, 64, JSON_THROW_ON_ERROR)['beneficiaryBankName']);
        $this->assertSame($result, $this->inspect()); // Revalidation of sealed evidence must use the same rule.
        $this->assertSame(0, NiumDynamicIntent::query()->count());
        $this->assertSame(0, NiumDynamicPayoutOutbox::query()->count());
        $this->assertSame(0, NiumDynamicReservation::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public static function twoSlotResponses(): array
    {
        return [
            'full second routing' => [[], true],
            'explicit null second type' => [['routingCodeType2' => null], false],
            'explicit null second value' => [['routingCodeValue2' => null], false],
            'conflicting second value' => [['routingCodeValue2' => '999'], false],
        ];
    }

    #[DataProvider('twoSlotResponses')]
    public function test_details_fixture_preserves_registered_second_routing_but_never_hides_provider_conflicts(array $changes, bool $valid): void
    {
        $this->beneficiary->update(['swift_bic' => null, 'raw_data' => ['nium' => ['payoutMethod' => 'LOCAL',
            'beneficiaryBankAccountType' => 'Savings', 'routingInfo' => [
                ['type' => 'BANK CODE', 'value' => '0001'], ['type' => 'BRANCH CODE', 'value' => '001'],
            ]]]]);
        $this->registered = app(NiumBeneficiaryService::class)->receivingAccountBinding($this->beneficiary);
        $request = json_encode($this->registered, JSON_THROW_ON_ERROR);
        NiumApiExchangeEvidence::query()->sole()->update(['raw_request_body' => $request, 'request_body_sha256' => hash('sha256', $request)]);
        $this->binding['registration_request_sha256'] = hash('sha256', $request);
        $this->binding['current_receiving_record_sha256'] = DynamicReceivingAccountBinding::fingerprint($this->beneficiary);
        $this->schemaBody = SyntheticDynamicBeneficiarySchema::body([
            'routingCodeType1' => ['type' => 'string', 'const' => 'BANK CODE'],
            'routingCodeValue1' => ['type' => 'string', 'pattern' => '^[0-9]{4}$'],
            'routingCodeType2' => ['type' => 'string', 'const' => 'BRANCH CODE'],
            'routingCodeValue2' => ['type' => 'string', 'pattern' => '^[0-9]{3}$'],
        ], ['routingCodeType1', 'routingCodeValue1', 'routingCodeType2', 'routingCodeValue2']);
        $this->expectDetails($this->body($changes));
        if (! $valid) {
            $this->assertBlocked();
            return;
        }
        $result = $this->inspect();
        $this->assertSame([], $result['reason_codes']);
        $this->binding['beneficiary_customer_binding_sha256'] = $result['customer_binding_sha256'];
        $snapshot = app(DynamicRegisteredAccountEvidence::class)->bindingObservation($this->beneficiary, $this->account, $this->binding);
        $response = json_decode($snapshot['response_json'], true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame('BRANCH CODE', $response['routingCodeType2']);
        $this->assertSame('001', $response['routingCodeValue2']);
    }

    public static function contradictoryRegistrationEchoes(): array { return [[null], ['foreign-customer']]; }

    #[DataProvider('contradictoryRegistrationEchoes')]
    public function test_details_get_never_overrules_an_explicit_null_or_wrong_registration_customer(?string $customer): void
    {
        $exchange = NiumApiExchangeEvidence::query()->sole();
        $response = json_encode(['beneficiaryHashId' => $this->beneficiary->external_beneficiary_id,
            'customerHashId' => $customer], JSON_THROW_ON_ERROR);
        $exchange->update(['raw_response_body' => $response, 'response_body_sha256' => hash('sha256', $response)]);
        $this->binding['registration_response_sha256'] = hash('sha256', $response);
        $this->nium->shouldNotReceive('get');
        $this->assertBlocked();
    }

    public function test_concurrent_render_without_fresh_evidence_does_not_start_another_get(): void
    {
        $this->expectDetails();
        $this->inspect();
        $snapshot = app(DynamicBeneficiaryDetailsEvidence::class)->snapshot($this->beneficiary, $this->account, $this->binding, $this->registered);
        $key = 'nium:dynamic:beneficiary:v2:'.hash('sha256', json_encode($snapshot['scope'], JSON_THROW_ON_ERROR));
        $this->travelTo(now()->addSeconds(31));
        $lock = Cache::store('database')->lock($key.':refresh', 35);
        $this->assertTrue($lock->get());
        try {
            $this->assertBlocked(); // Only the lock holder may refresh. No stale fallback.
        } finally {
            $lock->release();
        }
    }

    public function test_credential_rotation_invalidates_a_quote_pinned_to_the_old_authenticated_scope(): void
    {
        $this->expectDetails();
        $original = $this->inspect();
        $this->binding['beneficiary_customer_binding_sha256'] = $original['customer_binding_sha256'];
        config(['services.nium.auth.header_value' => 'rotated-synthetic-key']);
        $this->assertFalse($this->inspect(false)['customer_scope_confirmed']);
    }

    public static function rejectedStatuses(): array { return [[401], [403], [404], [500], [206], [302]]; }

    #[DataProvider('rejectedStatuses')]
    public function test_only_http_200_can_attest_customer_binding(int $status): void
    {
        $this->expectDetails(status: $status);
        $this->assertBlocked();
        $this->assertBlocked(); // Negative cache prevents another failed GET.
    }

    public function test_timeout_stays_blocked_and_is_not_automatically_retried(): void
    {
        $this->expectSchema();
        $this->nium->shouldReceive('get')->withArgs(static fn ($path, $query, $user, $operation) => $operation === 'dynamic_beneficiary_details')
            ->once()->andThrow(new ConnectionException('synthetic timeout'));
        $this->assertBlocked();
        $this->assertBlocked();
    }

    #[DataProvider('rejectedStatuses')]
    public function test_schema_http_failure_is_not_replaced_with_local_format_rules(int $status): void
    {
        $this->expectSchema($status);
        $this->nium->shouldNotReceive('get')->withArgs(static fn ($path, $query, $user, $operation) => $operation === 'dynamic_beneficiary_details');
        $this->assertBlocked();
        $this->assertBlocked();
    }

    public function test_schema_timeout_stays_blocked_without_a_details_get(): void
    {
        $this->nium->shouldReceive('get')->withArgs(static fn ($path, $query, $user, $operation) => $operation === 'dynamic_beneficiary_binding_schema')
            ->once()->andThrow(new ConnectionException('synthetic schema timeout'));
        $this->nium->shouldNotReceive('get')->withArgs(static fn ($path, $query, $user, $operation) => $operation === 'dynamic_beneficiary_details');
        $this->assertBlocked();
        $this->assertBlocked();
    }

    public function test_schema_requires_authenticated_customer_scoped_transport_too(): void
    {
        $this->expectSchema(outgoing: static fn ($url) => new Request(new PsrRequest('GET', str_replace('fixture-customer', 'foreign', $url),
            ['x-api-key' => 'synthetic-details-key'])));
        $this->nium->shouldNotReceive('get')->withArgs(static fn ($path, $query, $user, $operation) => $operation === 'dynamic_beneficiary_details');
        $this->assertBlocked();
    }

    public static function rejectedSchemas(): array
    {
        return [
            'schema format contradicts account' => [SyntheticDynamicBeneficiarySchema::body([
                'beneficiaryAccountNumber' => ['type' => 'string', 'pattern' => '^[A-Z]+$']])],
            'schema format contradicts routing' => [SyntheticDynamicBeneficiarySchema::body([
                'routingCodeValue1' => ['type' => 'string', 'pattern' => '^[0-9]{6}$']])],
            'required bank field absent' => [SyntheticDynamicBeneficiarySchema::body([
                'beneficiaryBankCode' => ['type' => 'string']], ['beneficiaryBankCode'])],
            'account type condition requires identification' => [SyntheticDynamicBeneficiarySchema::body([
                'beneficiaryIdentificationValue' => ['type' => 'string']], [], [
                    ['if' => ['properties' => ['beneficiaryBankAccountType' => ['const' => 'Savings']], 'required' => ['beneficiaryBankAccountType']],
                        'then' => ['required' => ['beneficiaryIdentificationValue']]]])],
            'country conflict' => [SyntheticDynamicBeneficiarySchema::body([
                'destinationCountry' => ['type' => 'string', 'const' => 'GB']])],
            'beneficiary type conflict' => [SyntheticDynamicBeneficiarySchema::body([
                'beneficiaryAccountType' => ['type' => 'string', 'const' => 'Corporate']])],
            'unsupported composition' => ['{"type":"object","anyOf":[{"type":"object"}]}'],
            'unknown conditional evidence' => [SyntheticDynamicBeneficiarySchema::body([], [], [
                ['if' => ['properties' => ['unverifiedProviderState' => ['const' => 'required']]],
                    'then' => ['required' => ['beneficiaryBankCode']]]])],
            'unsupported pattern' => [SyntheticDynamicBeneficiarySchema::body([
                'beneficiaryAccountNumber' => ['type' => 'string', 'pattern' => '\\p{Number}+']])],
            'no account contract' => ['{"type":"object","properties":{"beneficiaryName":{"type":"string"}}}'],
            'ambiguous schemas' => ['[{"type":"object"},{"type":"object"}]'],
            'malformed schema' => ['{'],
            'duplicate field' => ['{"type":"object","type":"string"}'],
        ];
    }

    #[DataProvider('rejectedSchemas')]
    public function test_missing_conflicting_or_unsupported_schema_never_creates_binding(string $schema): void
    {
        $this->schemaBody = $schema;
        $this->expectSchema();
        $this->nium->shouldNotReceive('get')->withArgs(static fn ($path, $query, $user, $operation) => $operation === 'dynamic_beneficiary_details');
        $this->assertBlocked();
    }

    public function test_bank_account_type_condition_uses_actual_registration_facts(): void
    {
        $this->schemaBody = SyntheticDynamicBeneficiarySchema::body([
            'beneficiaryBankName' => ['type' => 'string'], 'beneficiaryIdentificationValue' => ['type' => 'string']], [], [
                ['if' => ['properties' => ['beneficiaryBankAccountType' => ['const' => 'Savings']], 'required' => ['beneficiaryBankAccountType']],
                    'then' => ['required' => ['beneficiaryBankName']], 'else' => ['required' => ['beneficiaryIdentificationValue']]]]);
        $this->expectDetails();
        $this->assertSame([], $this->inspect()['reason_codes']);
    }

    public function test_changed_schema_cannot_renew_a_quote_pinned_to_earlier_rules(): void
    {
        $this->expectDetails();
        $original = $this->inspect();
        $this->binding['beneficiary_customer_binding_sha256'] = $original['customer_binding_sha256'];
        $this->travelTo(now()->addSeconds(31));
        $this->schemaBody = SyntheticDynamicBeneficiarySchema::body([
            'beneficiaryAccountNumber' => ['type' => 'string', 'pattern' => '^[0-9]{10}$']]);
        $this->expectDetails(); // Still valid values, but the original schema proof changed.
        $this->assertBlocked();
    }

    public function test_cached_schema_bytes_cannot_be_modified_without_invalidating_provenance(): void
    {
        $this->expectDetails();
        $this->inspect();
        $snapshot = app(DynamicBeneficiaryDetailsEvidence::class)->snapshot($this->beneficiary, $this->account, $this->binding, $this->registered);
        $key = 'nium:dynamic:beneficiary:v2:'.hash('sha256', json_encode($snapshot['scope'], JSON_THROW_ON_ERROR));
        $snapshot['schema_json'] = '{"type":"object"}';
        Cache::store('database')->put($key, Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR)), now()->addMinute());
        $this->assertBlocked();
    }

    public static function conflictingBodies(): array
    {
        return [[['beneficiaryHashId' => 'foreign']], [['destinationCountry' => 'HK']], [['destinationCurrency' => 'USD']],
            [['payoutMethod' => 'SWIFT']], [['beneficiaryName' => 'Other Payee']], [['beneficiaryAccountNumber' => '0099999999']],
            [['routingCodeValue1' => 'OTHERSGXXX1']], [['beneficiaryAccountNumber' => '****5678']],
            [['routingCodeValue1' => null]], [['beneficiaryBankAccountType' => null]],
            [['beneficiaryBankName' => 'Other Bank']], [['beneficiaryBankName' => 'SYNTHETIC  BANK']],
            [['beneficiaryBankName' => ' SYNTHETIC BANK']], [['beneficiaryBankName' => 'SYNTHETIC BANK ']],
            [['beneficiaryBankName' => '[REDACTED]']], [['accountNumberMasked' => true]],
            [['customerHashId' => 'foreign-customer']], [['customerHashId' => 'FIXTURE-CUSTOMER']],
            [['clientHashId' => 'FIXTURE-CLIENT']], [['routingCodeValue1' => 'testsgsgxxx']]];
    }

    #[DataProvider('conflictingBodies')]
    public function test_provider_conflicts_and_masking_never_create_authority(array $changes): void
    {
        $this->expectDetails($this->body(['beneficiaryBankName' => 'SYNTHETIC BANK', ...$changes]));
        $this->assertBlocked();
    }

    public function test_malformed_response_stays_blocked(): void
    {
        $this->expectDetails('{');
        $this->assertBlocked();
    }

    public static function wrongRequests(): array { return [['origin'], ['customer'], ['method'], ['credential'], ['missing_callback']]; }

    #[DataProvider('wrongRequests')]
    public function test_response_without_authenticated_exact_request_provenance_is_refused(string $mutation): void
    {
        $this->expectDetails(outgoing: static function ($url) use ($mutation): ?Request {
            if ($mutation === 'missing_callback') return null;
            if ($mutation === 'origin') $url = str_replace('api.spend.nium.com', 'gateway.nium.com', $url);
            if ($mutation === 'customer') $url = str_replace('fixture-customer', 'foreign', $url);
            return new Request(new PsrRequest($mutation === 'method' ? 'POST' : 'GET', $url,
                ['x-api-key' => $mutation === 'credential' ? 'foreign-key' : 'synthetic-details-key']));
        });
        $this->assertBlocked();
    }

    public static function mappingChanges(): array { return [['customer'], ['beneficiary'], ['account']]; }

    #[DataProvider('mappingChanges')]
    public function test_mapping_change_during_get_invalidates_observation(string $change): void
    {
        $this->expectDetails(during: function () use ($change): void {
            if ($change === 'customer') $this->account->update(['external_customer_id' => 'foreign']);
            if ($change === 'beneficiary') $this->beneficiary->update(['external_beneficiary_id' => 'foreign']);
            if ($change === 'account') $this->beneficiary->update(['account_number' => '0099999999']);
        });
        $this->assertBlocked();
    }

    public function test_changed_cached_response_cannot_reuse_its_original_provenance_hash(): void
    {
        $this->expectDetails();
        $this->inspect();
        $snapshot = app(DynamicBeneficiaryDetailsEvidence::class)->snapshot($this->beneficiary, $this->account, $this->binding, $this->registered);
        $key = 'nium:dynamic:beneficiary:v2:'.hash('sha256', json_encode($snapshot['scope'], JSON_THROW_ON_ERROR));
        $snapshot['response_json'] = $this->body(['beneficiaryAccountNumber' => '0099999999']);
        Cache::store('database')->put($key, Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR)), now()->addMinute());
        $this->assertBlocked();
    }

    public function test_expired_evidence_can_only_be_renewed_by_another_authenticated_get(): void
    {
        $this->expectDetails();
        $original = $this->inspect();
        $this->binding['beneficiary_customer_binding_sha256'] = $original['customer_binding_sha256'];
        $this->travelTo(now()->addSeconds(31));
        $this->assertFalse($this->inspect(false)['customer_scope_confirmed']);
        $this->expectDetails();
        $this->assertSame($original, $this->inspect());
    }

    public function test_no_http_is_allowed_inside_admission_transaction(): void
    {
        $this->nium->shouldNotReceive('get');
        $originalName = $this->owner->fresh()->full_name;
        try {
            OwnerFirst::users([$this->owner->id], function (): void {
                $this->assertSame(1, DB::transactionLevel());
                $this->assertTrue(DB::connection()->getPdo()->inTransaction());
                $this->owner->update(['full_name' => 'Synthetic owner change requiring rollback']);
                $result = $this->inspect();
                $this->assertTrue($result['payload_matches']);
                $this->assertFalse($result['customer_scope_confirmed']);
                $this->assertSame(['dynamic_registered_account_customer_scope_unavailable'], $result['reason_codes']);
                // inspect() catches the I/O guard's exception, but the root is
                // rollback-only. Let OwnerFirst unwind it before further work.
            });
            $this->fail('An attempted evidence refresh inside admission must prevent root commit.');
        } catch (OwnerTransactionRollbackOnly $exception) {
            $this->assertSame('A failed nested operation requires complete root rollback.', $exception->getMessage());
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertSame($originalName, $this->owner->fresh()->full_name);
        // Check recorded calls too: inspect() must not hide a mock GET failure.
        // Zero calls covers both dynamic_beneficiary_details and binding_schema.
        $this->nium->shouldNotHaveReceived('get');
    }
}
