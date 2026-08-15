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

    public function assign(UserProviderAccount $account, string $currencyCode, string $accountCategory, string $bankName): NiumVirtualAccount
    {
        $account = UserProviderAccount::query()->with('user')->findOrFail($account->getKey());
        $eligible = $this->accountStateService->assertEligible($account->user);
        if ((int) $eligible->getKey() !== (int) $account->getKey()
            || (int) $eligible->provider_id !== (int) $account->provider_id
            || (int) $eligible->user_id !== (int) $account->user_id) {
            throw new RuntimeException('Supplied Nium provider account is not the exact authoritative eligible account.');
        }
        $currencyCode = strtoupper(trim($currencyCode));
        $accountCategory = strtoupper(trim($accountCategory));
        $bankName = trim($bankName);

        if (preg_match('/^[A-Z]{3}$/', $currencyCode) !== 1
            || ! in_array($accountCategory, ['SELF_FUNDING_ACCOUNT', 'COLLECTION_ACCOUNT'], true)
            || $bankName === '' || strlen($bankName) > 64
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.-]*$/', $bankName) !== 1) {
            throw new RuntimeException('Invalid Nium Assign Payment ID V1 currency code, account category, or bank name.');
        }
        $payload = [
            'bankName' => $bankName,
            'currencyCode' => $currencyCode,
            'accountCategory' => $accountCategory,
        ];
        $response = $this->niumService->post(
            path: $this->niumService->path((string) config('services.nium.assign_payment_id_endpoint'), [
                'client' => $this->niumService->clientId(),
                'customer' => (string) $account->external_customer_id,
                'wallet' => (string) $account->external_account_id,
            ]),
            payload: $payload,
            user: $account->user,
            operation: 'assign_payment_id',
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
                'currency' => strtoupper((string) ($data['currencyCode'] ?? $currencyCode)),
                'account_category' => $accountCategory,
                'account_type' => null,
                'status' => 'assigned',
                'assigned_at' => now(),
            ],
        ));
    }
}
