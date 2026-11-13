<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutInstruction;
use App\Models\NiumDynamicPayoutQuote;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutInstructionValidation;
use App\Services\Nium\DynamicConfirmation\DynamicReceivingAccountBinding;
use App\Services\Nium\NiumService;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesDynamicConfirmationFixtures;
use Tests\Support\DynamicConfirmationDatabaseTestCase;

/** Mocked authenticated HTTP boundary; fixtures are not provider authorization. */
final class NiumDynamicInstructionValidationTest extends DynamicConfirmationDatabaseTestCase
{
    use CreatesDynamicConfirmationFixtures;

    private NiumService $nium;
    private NiumDynamicPayoutQuote $quote;
    private DynamicPayoutInstructionValidation $validation;
    private array $amounts = ['quoted_principal' => ['currency' => 'USD', 'amount' => '980.01'],
        'quoted_destination_amount' => ['currency' => 'GBP', 'amount' => '784.02']];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDynamicFixtures();
        $this->wallet->update(['currency' => 'USD']);
        config(['services.nium.base_url' => 'https://gatewaysandbox.nium.com']);
        $this->nium = Mockery::mock(NiumService::class)->makePartial();
        $this->nium->shouldReceive('clientId')->andReturn('fixture-client');
        $this->validation = new DynamicPayoutInstructionValidation($this->nium);
        $this->quote = new NiumDynamicPayoutQuote;
        $this->quote->forceFill(['id' => '11111111-1111-4111-8111-111111111111', 'quote_id' => 'fixture-quote',
            'user_id' => $this->owner->id, 'provider_id' => $this->account->provider_id,
            'provider_account_id' => $this->account->id, 'balance_id' => $this->wallet->id,
            'beneficiary_id' => $this->beneficiary->id, 'expires_at' => now()->addMinutes(5),
            'binding' => ['client' => 'fixture-client', 'customer' => 'fixture-customer', 'wallet' => 'fixture-wallet',
                'owner' => (string) $this->owner->id, 'provider_account_id' => $this->account->id,
                'balance_id' => $this->wallet->id, 'beneficiary_id' => $this->beneficiary->id,
                'destination_country' => 'GB', 'destination_currency' => 'GBP', 'payout_method' => 'LOCAL',
                'current_receiving_record_sha256' => DynamicReceivingAccountBinding::fingerprint($this->beneficiary),
                'base_url' => 'https://gatewaysandbox.nium.com', 'amount_mode' => 'SOURCE_AMOUNT']]);
    }

    private function observe(string $body, ?\Closure $alterRequest = null, ?\Closure $dispatch = null,
        ?\Closure $during = null, int $status = 200): void
    {
        $this->nium->shouldReceive('post')->once()->andReturnUsing(function ($path, $payload, $user,
            $transferId, $operation, $reference, $beforeDispatch) use ($body, $alterRequest, $dispatch, $during, $status): Response {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('/api/v1/client/fixture-client/customer/fixture-customer/wallet/fixture-wallet/remittance/validate', $path);
            $this->assertSame('dynamic_remittance_validate', $operation);
            $this->assertSame($this->owner->id, $user->id);
            $this->assertSame(['id' => 'fixture-registered-payee'], $payload['beneficiary']);
            $this->assertSame('IR005', $payload['purposeCode']);
            $this->assertSame('Salary', $payload['sourceOfFunds']);
            $this->assertSame('Invoice', $payload['customerComments']);
            $this->assertSame('fixture-quote', $payload['payout']['quoteId']);
            $field = $this->quote->binding['amount_mode'] === 'SOURCE_AMOUNT' ? 'sourceAmount' : 'destinationAmount';
            $this->assertSame($field === 'sourceAmount' ? 980.01 : 784.02, $payload['payout'][$field]);
            $this->assertArrayNotHasKey($field === 'sourceAmount' ? 'destinationAmount' : 'sourceAmount', $payload['payout']);
            $this->assertArrayNotHasKey('additionalFees', $payload);
            $this->assertArrayNotHasKey('additionalFees', $payload['payout']);
            $alterRequest?->__invoke($payload);
            $request = new Request(new PsrRequest('POST', rtrim((string) config('services.nium.base_url'), '/').$path,
                ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR)));
            if ($dispatch === null) $beforeDispatch($request);
            else $dispatch($request, $beforeDispatch);
            $during?->__invoke();
            return new Response(new PsrResponse($status, [], $body));
        });
        $this->nium->shouldNotReceive('postDynamicPayout');
    }

    public static function modes(): array { return [['SOURCE_AMOUNT'], ['DESTINATION_AMOUNT']]; }

    #[DataProvider('modes')]
    public function test_validates_only_the_exact_selected_amount_and_instruction_without_a_payout(string $mode): void
    {
        $this->quote->binding = [...$this->quote->binding, 'amount_mode' => $mode];
        $this->observe('{"valid":true,"issues":[]}');
        $result = $this->validation->validate($this->owner, $this->beneficiary, $this->quote,
            new PayoutInstruction('IR005', 'Salary', 'Invoice'), $this->amounts);
        $this->assertSame('fixture-customer', $result['scope']['customer']);
        $this->assertSame('fixture-quote', $result['scope']['quote']);
        $this->assertSame(now()->addSeconds(30)->toISOString(), $result['expires_at']);
    }

    public static function refusals(): array
    {
        return [['{"valid":false,"issues":[] }'], ['{"valid":true,"issues":[{"field":"purposeCode"}]}'], ['{"status":"OK"}'],
            ['{"valid":true}'], ['{"valid":"true","issues":[]}'], ['{"valid":true,"issues":{}}']];
    }

    #[DataProvider('refusals')]
    public function test_false_incomplete_or_inapplicable_validation_cannot_authorize_an_instruction(string $body): void
    {
        $this->observe($body);
        $this->expectExceptionMessage('dynamic_payout_instruction_rejected');
        $this->validation->validate($this->owner, $this->beneficiary, $this->quote,
            new PayoutInstruction('IR005', 'Salary', 'Invoice'), $this->amounts);
    }

    public function test_changed_serialized_instruction_is_refused_before_dispatch(): void
    {
        $this->observe('{"valid":true,"issues":[]}', static function (array &$payload): void { $payload['purposeCode'] = 'OTHER'; });
        $this->expectExceptionMessage('dynamic_instruction_validation_binding_changed');
        $this->validation->validate($this->owner, $this->beneficiary, $this->quote,
            new PayoutInstruction('IR005', 'Salary', 'Invoice'), $this->amounts);
    }

    public function test_unrepresentable_amount_is_refused_before_http_instead_of_rounding(): void
    {
        $this->nium->shouldNotReceive('post');
        $this->expectExceptionMessage('dynamic_instruction_request_precision_unavailable');
        $this->validation->validate($this->owner, $this->beneficiary, $this->quote,
            new PayoutInstruction('IR005', 'Salary', 'Invoice'),
            [...$this->amounts, 'quoted_principal' => ['currency' => 'USD', 'amount' => '980.0000000000000001']]);
    }

    public function test_validation_network_is_forbidden_inside_a_reservation_transaction(): void
    {
        $this->nium->shouldNotReceive('post');
        $this->expectExceptionMessage('dynamic_instruction_validation_requires_network_outside_transaction');
        DB::transaction(fn () => $this->validation->validate($this->owner, $this->beneficiary, $this->quote,
            new PayoutInstruction('IR005', 'Salary', 'Invoice'), $this->amounts));
    }

    #[DataProvider('modes')]
    public function test_documented_validation_endpoint_is_available_on_spend_without_payout_authority(string $mode): void
    {
        config(['services.nium.base_url' => 'https://api.spend.nium.com']);
        $this->quote->binding = [...$this->quote->binding, 'base_url' => 'https://api.spend.nium.com', 'amount_mode' => $mode];
        $this->observe('{"valid":true,"issues":[]}');
        $result = $this->validation->validate($this->owner, $this->beneficiary, $this->quote,
            new PayoutInstruction('IR005', 'Salary', 'Invoice'), $this->amounts);
        $this->assertSame(hash('sha256', $result['request_json']), $result['request_sha256']);
        $this->assertSame(0, \App\Models\NiumDynamicIntent::query()->count());
        $this->assertSame(0, \App\Models\NiumDynamicPayoutOutbox::query()->count());
        $this->assertSame('0.00000000', $this->wallet->fresh()->reserved_balance);
    }

    public static function callbackFailures(): array
    {
        return [['missing'], ['repeated'], ['method'], ['origin'], ['customer'], ['payout_path'], ['body_bytes']];
    }

    #[DataProvider('callbackFailures')]
    public function test_response_cannot_replace_exact_once_validation_transport_proof(string $mutation): void
    {
        $this->observe('{"valid":true,"issues":[]}', dispatch: static function (Request $request, \Closure $callback) use ($mutation): void {
            if ($mutation === 'missing') return;
            if ($mutation === 'repeated') {
                $callback($request);
                $callback($request);
                return;
            }
            $url = $request->url();
            if ($mutation === 'origin') $url = str_replace('gatewaysandbox.nium.com', 'gateway.nium.com', $url);
            if ($mutation === 'customer') $url = str_replace('fixture-customer', 'foreign-customer', $url);
            if ($mutation === 'payout_path') $url = substr($url, 0, -strlen('/validate'));
            $callback(new Request(new PsrRequest($mutation === 'method' ? 'GET' : 'POST', $url,
                ['Content-Type' => 'application/json'], $request->body().($mutation === 'body_bytes' ? ' ' : ''))));
        });
        $this->expectExceptionMessage('dynamic_instruction_validation_binding_changed');
        $this->validation->validate($this->owner, $this->beneficiary, $this->quote,
            new PayoutInstruction('IR005', 'Salary', 'Invoice'), $this->amounts);
    }

    public static function responseScopeFailures(): array
    {
        return [['clientHashId', null], ['customerHashId', null], ['walletHashId', null],
            ['clientHashId', 'foreign-client'], ['customerHashId', 'foreign-customer'], ['walletHashId', 'foreign-wallet']];
    }

    #[DataProvider('responseScopeFailures')]
    public function test_foreign_or_null_scope_echo_is_rejected(string $field, ?string $value): void
    {
        $this->observe(json_encode(['valid' => true, 'issues' => [], $field => $value], JSON_THROW_ON_ERROR));
        $this->expectExceptionMessage('dynamic_instruction_validation_binding_changed');
        $this->validation->validate($this->owner, $this->beneficiary, $this->quote,
            new PayoutInstruction('IR005', 'Salary', 'Invoice'), $this->amounts);
    }

    public static function scopeChanges(): array
    {
        return [['customer'], ['wallet'], ['beneficiary'], ['expiry']];
    }

    #[DataProvider('scopeChanges')]
    public function test_scope_or_expiry_change_during_http_invalidates_success(string $mutation): void
    {
        $this->observe('{"valid":true,"issues":[]}', during: function () use ($mutation): void {
            if ($mutation === 'customer') $this->account->update(['external_customer_id' => 'foreign-customer']);
            if ($mutation === 'wallet') $this->wallet->update(['external_account_id' => 'foreign-wallet']);
            if ($mutation === 'beneficiary') $this->beneficiary->update(['account_number' => 'CHANGED']);
            if ($mutation === 'expiry') $this->travelTo(now()->addMinutes(5));
        });
        $this->expectExceptionMessage('dynamic_instruction_validation_binding_changed');
        $this->validation->validate($this->owner, $this->beneficiary, $this->quote,
            new PayoutInstruction('IR005', 'Salary', 'Invoice'), $this->amounts);
    }

    public function test_validation_timeout_does_not_return_instruction_evidence(): void
    {
        $this->observe('{"valid":true,"issues":[]}', during: fn () => $this->travelTo(now()->addSeconds(31)));
        $this->expectExceptionMessage('dynamic_payout_instruction_validation_unavailable');
        $this->validation->validate($this->owner, $this->beneficiary, $this->quote,
            new PayoutInstruction('IR005', 'Salary', 'Invoice'), $this->amounts);
    }

    public static function nonSuccessStatuses(): array { return [[201], [302], [400], [500]]; }

    #[DataProvider('nonSuccessStatuses')]
    public function test_only_documented_http200_with_explicit_valid_can_supply_validation_evidence(int $status): void
    {
        $this->observe('{"valid":true,"issues":[]}', status: $status);
        $this->expectExceptionMessage('dynamic_payout_instruction_validation_unavailable');
        $this->validation->validate($this->owner, $this->beneficiary, $this->quote,
            new PayoutInstruction('IR005', 'Salary', 'Invoice'), $this->amounts);
    }

    public function test_wrong_amount_currency_is_rejected_before_any_post(): void
    {
        $this->nium->shouldNotReceive('post');
        $this->expectExceptionMessage('dynamic_instruction_validation_binding_changed');
        $this->validation->validate($this->owner, $this->beneficiary, $this->quote,
            new PayoutInstruction('IR005', 'Salary', 'Invoice'),
            [...$this->amounts, 'quoted_principal' => ['currency' => 'EUR', 'amount' => '980.01']]);
    }

    public static function wrongEnvironments(): array
    {
        return [['https://unknown.nium.com', 'dynamic_provider_environment_unverified'],
            ['https://gateway.nium.com', 'dynamic_provider_scope_unverified'],
            ['http://api.spend.nium.com', 'dynamic_provider_environment_unverified']];
    }

    #[DataProvider('wrongEnvironments')]
    public function test_wrong_environment_cannot_reach_validation_transport(string $origin, string $reason): void
    {
        config(['services.nium.base_url' => $origin]);
        $this->nium->shouldNotReceive('post');
        $this->expectExceptionMessage($reason);
        $this->validation->validate($this->owner, $this->beneficiary, $this->quote,
            new PayoutInstruction('IR005', 'Salary', 'Invoice'), $this->amounts);
    }
}
