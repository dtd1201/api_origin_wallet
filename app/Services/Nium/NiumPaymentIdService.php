<?php

namespace App\Services\Nium;

use App\Models\NiumVirtualAccount;
use App\Models\UserProviderAccount;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class NiumPaymentIdService
{
    public function __construct(
        private readonly NiumService $niumService,
        private readonly NiumProviderAccountStateService $accountStateService,
    ) {}

    public function assign(UserProviderAccount $account, string $currency, string $accountCategory, string $accountType, ?string $bankName = null): NiumVirtualAccount
    {
        $account->loadMissing('user');
        $this->accountStateService->assertEligible($account->user);
        $currency = strtoupper($currency);
        $accountCategory = strtoupper($accountCategory);
        $accountType = strtoupper($accountType);

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1
            || ! in_array($accountCategory, ['SELF_FUNDING_ACCOUNT', 'COLLECTION_ACCOUNT', 'SELF_FUNDING_AND_COLLECTION_ACCOUNT'], true)
            || ! in_array($accountType, ['LOCAL', 'WIRES', 'LOCAL_AND_WIRES'], true)) {
            throw new RuntimeException('Invalid Nium Assign Payment ID currency, account category, or account type.');
        }
        $payload = array_filter([
            'currency' => $currency,
            'accountCategory' => $accountCategory,
            'accountType' => $accountType,
            'bankName' => $bankName,
        ], static fn ($value) => $value !== null && $value !== '');
        $response = $this->niumService->post(
            path: $this->niumService->path((string) config('services.nium.assign_payment_id_endpoint'), [
                'client' => $this->niumService->clientId(),
                'customer' => $this->niumService->customerId($account->user),
                'wallet' => $this->niumService->walletId($account->user),
            ]),
            payload: $payload,
            user: $account->user,
            operation: 'assign_payment_id',
            externalReference: 'account-'.$account->id,
        );
        $data = $response->json() ?? [];
        $paymentId = $data['uniquePaymentId'] ?? null;

        if (! $response->successful() || ! is_string($paymentId) || $paymentId === '') {
            throw new RuntimeException('Nium Assign Payment ID failed.');
        }

        return DB::transaction(fn () => NiumVirtualAccount::query()->updateOrCreate(
            ['user_provider_account_id' => $account->id, 'provider_payment_id' => $paymentId],
            [
                'virtual_account_reference' => $paymentId,
                'currency' => strtoupper((string) ($data['currencyCode'] ?? $currency)),
                'account_category' => $data['accountCategory'] ?? $payload['accountCategory'],
                'account_type' => $data['accountType'] ?? $payload['accountType'],
                'status' => 'assigned',
                'assigned_at' => now(),
            ],
        ));
    }
}
