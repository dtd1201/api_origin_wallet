<?php

namespace App\Services\Nium;

use App\Models\NiumVirtualAccount;
use App\Models\UserProviderAccount;
use RuntimeException;

final class NiumVirtualAccountDetailsService
{
    private const FIELDS = [
        'uniquePaymentId', 'status', 'accountCategory', 'currencyCode', 'accountName', 'accountType',
        'bankName', 'fullBankName', 'bankAddress', 'routingCodeType1', 'routingCodeValue1',
        'routingCodeType2', 'routingCodeValue2', 'uniquePayerId', 'uniquePayerType',
    ];

    public function __construct(
        private readonly NiumService $niumService,
        private readonly NiumProviderAccountStateService $accountStateService,
    ) {}

    public function get(NiumVirtualAccount $virtualAccount, bool $separateHumanApproval = false): array
    {
        if (! $separateHumanApproval) {
            throw new RuntimeException('HOLD_NIUM_VAN_DETAILS_HUMAN_APPROVAL_REQUIRED');
        }

        $virtualAccount = NiumVirtualAccount::query()->findOrFail($virtualAccount->getKey());
        $account = UserProviderAccount::query()->with('user', 'provider')->findOrFail($virtualAccount->user_provider_account_id);
        if ($account->provider?->code !== 'nium' || ! filled($account->external_customer_id)
            || ! filled($account->external_account_id)) {
            throw new RuntimeException('HOLD_NIUM_VAN_ACCOUNT_BINDING_MISMATCH');
        }
        $eligible = $this->accountStateService->assertEligible($account->user);

        if ((int) $eligible->getKey() !== (int) $account->getKey()
            || (int) $eligible->provider_id !== (int) $account->provider_id
            || (int) $eligible->user_id !== (int) $account->user_id) {
            throw new RuntimeException('HOLD_NIUM_VAN_ACCOUNT_BINDING_MISMATCH');
        }

        $response = $this->niumService->get(
            $this->niumService->path((string) config('services.nium.virtual_account_details_endpoint'), [
                'client' => $this->niumService->clientId(),
                'customer' => (string) $account->external_customer_id,
                'wallet' => (string) $account->external_account_id,
            ]),
            ['uniquePaymentId' => $virtualAccount->provider_payment_id, 'status' => 'Active', 'page' => 0, 'size' => 20],
            $account->user,
        );
        $data = $response->json();
        if (! $response->successful() || ! is_array($data)) {
            throw new RuntimeException('HOLD_NIUM_VAN_DETAILS_UNAVAILABLE');
        }

        $items = $data['content'] ?? $data['paymentIds'] ?? $data['data'] ?? $data;
        $matches = array_values(array_filter(is_array($items) ? $items : [], fn ($item): bool => is_array($item) && ($item['uniquePaymentId'] ?? null) === $virtualAccount->provider_payment_id
        ));
        if (count($matches) !== 1) {
            throw new RuntimeException('HOLD_NIUM_VAN_MATCH_NOT_UNAMBIGUOUS');
        }

        $match = $matches[0];
        if (($match['status'] ?? null) !== 'Active') {
            throw new RuntimeException('HOLD_NIUM_VAN_NOT_ACTIVE');
        }
        if (strtoupper((string) ($match['currencyCode'] ?? '')) !== strtoupper((string) $virtualAccount->currency)) {
            throw new RuntimeException('HOLD_NIUM_VAN_CURRENCY_MISMATCH');
        }
        if (strtoupper((string) ($match['accountCategory'] ?? '')) !== strtoupper((string) $virtualAccount->account_category)) {
            throw new RuntimeException('HOLD_NIUM_VAN_ACCOUNT_CATEGORY_MISMATCH');
        }
        if (! filled($match['bankName'] ?? null) || ! filled($match['fullBankName'] ?? null)
            || ! filled($match['bankAddress'] ?? null) || ! filled($match['routingCodeType1'] ?? null)
            || ! filled($match['routingCodeValue1'] ?? null)) {
            throw new RuntimeException('HOLD_NIUM_VAN_BANK_ROUTING_REVIEW_REQUIRED');
        }

        return array_intersect_key($match, array_flip(self::FIELDS));
    }
}
