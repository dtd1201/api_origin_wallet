<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutAmountMode;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutInstruction;
use App\Models\Beneficiary;
use App\Models\NiumDynamicPayoutQuote;
use App\Models\User;
use App\Services\Nium\NiumService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Documented non-settling validation of the exact customer/corridor instruction. Not payout authority or a price receipt. */
final class DynamicPayoutInstructionValidation
{
    public function __construct(private readonly NiumService $nium) {}

    public function validate(User $user, Beneficiary $beneficiary, NiumDynamicPayoutQuote $quote,
        PayoutInstruction $instruction, array $amounts, ?string $operationKey = null): array
    {
        if (DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
            throw new RuntimeException('dynamic_instruction_validation_requires_network_outside_transaction');
        }
        $binding = $quote->binding;
        $environment = DynamicPayoutEnvironment::configured();
        $environment->assertBinding($binding['base_url']);
        $environment->assertContract('validation');
        $path = $this->nium->path('/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/remittance/validate',
            ['client' => $binding['client'], 'customer' => $binding['customer'], 'wallet' => $binding['wallet']]);
        $mode = PayoutAmountMode::from($binding['amount_mode']);
        $amount = $mode === PayoutAmountMode::Source ? $amounts['quoted_principal']['amount'] : $amounts['quoted_destination_amount']['amount'];
        $payout = json_encode(['sourceCurrency' => 'USD', 'quoteId' => $quote->quote_id,
            ...($operationKey === null ? [] : ['tradeOrderID' => $operationKey])], JSON_THROW_ON_ERROR);
        $payout = substr($payout, 0, -1).',"'.$mode->quoteField().'":'.$amount.'}';
        $json = json_encode(['beneficiary' => ['id' => $beneficiary->external_beneficiary_id], ...$instruction->snapshot(),
            ...($operationKey === null ? [] : ['externalId' => $operationKey])], JSON_THROW_ON_ERROR);
        $json = substr($json, 0, -1).',"payout":'.$payout.'}';
        $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (ExactProviderJson::decode(json_encode($payload, JSON_THROW_ON_ERROR)) !== ExactProviderJson::decode($json)) {
            throw new RuntimeException('dynamic_instruction_request_precision_unavailable');
        }
        $started = now();
        $response = $this->nium->post(path: $path, payload: $payload, user: $user,
            operation: 'dynamic_remittance_validate', externalReference: $quote->id,
            beforeDispatch: function (Request $request) use ($binding, $path, $json): void {
                DynamicPayoutEnvironment::configured()->assertBinding($binding['base_url']);
                DynamicPayoutEnvironment::configured()->assertContract('validation');
                if (DB::transactionLevel() !== 0 || $request->method() !== 'POST'
                    || $request->url() !== $binding['base_url'].$path
                    || rtrim((string) config('services.nium.base_url'), '/') !== $binding['base_url']
                    || $this->nium->clientId() !== $binding['client']
                    || ExactProviderJson::decode($request->body()) !== ExactProviderJson::decode($json)) {
                    throw new RuntimeException('dynamic_instruction_validation_binding_changed');
                }
            });
        DynamicPayoutEnvironment::configured()->assertBinding($binding['base_url']);
        if (! $response->successful() || strlen($response->body()) > 65536 || $started->copy()->addSeconds(30)->isPast()) {
            throw new RuntimeException('dynamic_payout_instruction_validation_unavailable');
        }
        $body = ExactProviderJson::decode($response->body());
        if (($body['valid'] ?? null) !== true || ($body['issues'] ?? null) !== []) {
            throw new RuntimeException('dynamic_payout_instruction_rejected');
        }
        foreach (['clientHashId' => 'client', 'customerHashId' => 'customer', 'walletHashId' => 'wallet'] as $field => $key) {
            if (isset($body[$field]) && $body[$field] !== $binding[$key]) {
                throw new RuntimeException('dynamic_instruction_validation_binding_changed');
            }
        }
        return ['version' => 'nium-remittance-validation-v1', 'request_sha256' => hash('sha256', $json),
            'response_sha256' => hash('sha256', $response->body()), 'observed_at' => now()->toISOString(),
            'request_json' => $json,
            'expires_at' => $started->copy()->addSeconds(30)->toISOString(),
            'scope' => ['client' => $binding['client'], 'customer' => $binding['customer'], 'wallet' => $binding['wallet'],
                'beneficiary' => $beneficiary->external_beneficiary_id, 'quote' => $quote->quote_id]];
    }
}
