<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutAmountMode;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutInstruction;
use App\Domain\Nium\DynamicMultiCurrency\Financial\Decimal;
use App\Models\Balance;
use App\Models\Beneficiary;
use App\Models\NiumDynamicPayoutQuote;
use App\Models\User;
use App\Models\UserProviderAccount;
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
        $this->assertScope($user, $beneficiary, $quote);
        $binding = $quote->binding;
        $environment = DynamicPayoutEnvironment::configured();
        $path = $this->nium->path('/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/remittance/validate',
            ['client' => $binding['client'], 'customer' => $binding['customer'], 'wallet' => $binding['wallet']]);
        $environment->assertInstructionValidationEndpoint($path, $binding['client'], $binding['customer'], $binding['wallet']);
        $mode = PayoutAmountMode::from($binding['amount_mode']);
        $money = $amounts[$mode === PayoutAmountMode::Source ? 'quoted_principal' : 'quoted_destination_amount'];
        if (($money['currency'] ?? null) !== ($mode === PayoutAmountMode::Source ? 'USD' : $binding['destination_currency'])) {
            throw new RuntimeException('dynamic_instruction_validation_binding_changed');
        }
        $amount = $money['amount'];
        if (! is_string($amount)) {
            throw new RuntimeException('dynamic_instruction_request_precision_unavailable');
        }
        $decimal = new Decimal($amount);
        if ($decimal->isZero() || ! $decimal->fitsScale(2)) {
            throw new RuntimeException('dynamic_instruction_request_precision_unavailable');
        }
        $amount = $decimal->value;
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
        $dispatched = false;
        $response = $this->nium->post(path: $path, payload: $payload, user: $user,
            operation: 'dynamic_remittance_validate', externalReference: $quote->id,
            beforeDispatch: function (Request $request) use ($user, $beneficiary, $quote, $binding, $path, $json, &$dispatched): void {
                $this->assertScope($user, $beneficiary, $quote);
                DynamicPayoutEnvironment::configured()->assertInstructionValidationEndpoint(
                    $path, $binding['client'], $binding['customer'], $binding['wallet']);
                if ($dispatched || $request->method() !== 'POST'
                    || $request->url() !== $binding['base_url'].$path
                    || ! hash_equals($json, $request->body())) {
                    throw new RuntimeException('dynamic_instruction_validation_binding_changed');
                }
                $dispatched = true;
            });
        $this->assertScope($user, $beneficiary, $quote);
        if (! $dispatched || $quote->binding !== $binding) {
            throw new RuntimeException('dynamic_instruction_validation_binding_changed');
        }
        if ($response->status() !== 200 || strlen($response->body()) > 65536 || $started->copy()->addSeconds(30)->isPast()) {
            throw new RuntimeException('dynamic_payout_instruction_validation_unavailable');
        }
        $body = ExactProviderJson::decode($response->body());
        $shape = json_decode($response->body(), false, 64, JSON_THROW_ON_ERROR);
        if (($body['valid'] ?? null) !== true || ($body['issues'] ?? null) !== []
            || ! is_array($shape->issues ?? null)) {
            throw new RuntimeException('dynamic_payout_instruction_rejected');
        }
        foreach (['clientHashId' => 'client', 'customerHashId' => 'customer', 'walletHashId' => 'wallet'] as $field => $key) {
            if (array_key_exists($field, $body) && $body[$field] !== $binding[$key]) {
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

    private function assertScope(User $user, Beneficiary $beneficiary, NiumDynamicPayoutQuote $quote): void
    {
        if (DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
            throw new RuntimeException('dynamic_instruction_validation_requires_network_outside_transaction');
        }
        $b = $quote->binding;
        DynamicPayoutEnvironment::configured()->assertBinding($b['base_url']);
        $account = UserProviderAccount::query()->find($b['provider_account_id'] ?? null);
        $wallet = Balance::query()->find($b['balance_id'] ?? null);
        $current = Beneficiary::query()->find($beneficiary->id);
        if ($account === null || $wallet === null || $current === null
            || $quote->expires_at === null || $quote->expires_at <= now()
            || (string) $quote->user_id !== (string) $user->id
            || (string) ($b['owner'] ?? '') !== (string) $user->id
            || (string) $quote->beneficiary_id !== (string) $current->id
            || (string) $quote->provider_account_id !== (string) $account->id
            || (string) $quote->balance_id !== (string) $wallet->id
            || (string) ($b['beneficiary_id'] ?? '') !== (string) $current->id
            || (string) $current->user_id !== (string) $user->id
            || (string) $account->user_id !== (string) $user->id || (string) $wallet->user_id !== (string) $user->id
            || (string) $quote->provider_id !== (string) $account->provider_id
            || (string) $current->provider_id !== (string) $account->provider_id
            || (string) $wallet->provider_id !== (string) $account->provider_id
            || $account->provider?->code !== 'nium' || $account->provider?->status !== 'active'
            || $this->nium->clientId() !== $b['client'] || $account->external_customer_id !== $b['customer']
            || $account->external_account_id !== $b['wallet'] || $wallet->external_account_id !== $b['wallet']
            || $wallet->currency !== 'USD' || $current->external_beneficiary_id !== $beneficiary->external_beneficiary_id
            || $current->country_code !== ($b['destination_country'] ?? null)
            || $current->currency !== ($b['destination_currency'] ?? null)
            || $current->payout_method !== ($b['payout_method'] ?? null)
            || DynamicReceivingAccountBinding::fingerprint($current) !== ($b['current_receiving_record_sha256'] ?? null)) {
            throw new RuntimeException('dynamic_instruction_validation_binding_changed');
        }
    }
}
