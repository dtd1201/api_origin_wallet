<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutInstruction;
use App\Models\NiumDynamicPayoutQuote;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutInstructionValidation;
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
    private array $amounts = ['quoted_principal' => ['amount' => '980.01'], 'quoted_destination_amount' => ['amount' => '784.02']];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDynamicFixtures();
        config(['services.nium.base_url' => 'https://gatewaysandbox.nium.com']);
        $this->nium = Mockery::mock(NiumService::class)->makePartial();
        $this->nium->shouldReceive('clientId')->andReturn('fixture-client');
        $this->validation = new DynamicPayoutInstructionValidation($this->nium);
        $this->quote = new NiumDynamicPayoutQuote;
        $this->quote->forceFill(['id' => '11111111-1111-4111-8111-111111111111', 'quote_id' => 'fixture-quote',
            'binding' => ['client' => 'fixture-client', 'customer' => 'fixture-customer', 'wallet' => 'fixture-wallet',
                'base_url' => 'https://gatewaysandbox.nium.com', 'amount_mode' => 'SOURCE_AMOUNT']]);
    }

    private function observe(string $body, ?\Closure $alterRequest = null): void
    {
        $this->nium->shouldReceive('post')->once()->andReturnUsing(function ($path, $payload, $user,
            $transferId, $operation, $reference, $beforeDispatch) use ($body, $alterRequest): Response {
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
            $beforeDispatch(new Request(new PsrRequest('POST', 'https://gatewaysandbox.nium.com'.$path,
                ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR))));
            return new Response(new PsrResponse(200, [], $body));
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
        return [['{"valid":false,"issues":[] }'], ['{"valid":true,"issues":[{"field":"purposeCode"}]}'], ['{"status":"OK"}']];
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
            [...$this->amounts, 'quoted_principal' => ['amount' => '980.0000000000000001']]);
    }

    public function test_validation_network_is_forbidden_inside_a_reservation_transaction(): void
    {
        $this->nium->shouldNotReceive('post');
        $this->expectExceptionMessage('dynamic_instruction_validation_requires_network_outside_transaction');
        DB::transaction(fn () => $this->validation->validate($this->owner, $this->beneficiary, $this->quote,
            new PayoutInstruction('IR005', 'Salary', 'Invoice'), $this->amounts));
    }
}
