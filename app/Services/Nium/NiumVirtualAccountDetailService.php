<?php

namespace App\Services\Nium;

use App\Models\NiumVirtualAccount;
use App\Models\NiumVirtualAccountDetail;
use App\Models\UserProviderAccount;
use RuntimeException;

class NiumVirtualAccountDetailService
{
    public function __construct(
        private readonly NiumService $niumService,
    ) {}

    public function sync(NiumVirtualAccount $virtualAccount): ?NiumVirtualAccountDetail
    {
        $providerAccount = UserProviderAccount::find($virtualAccount->user_provider_account_id);

        if (! $providerAccount) {
            throw new RuntimeException('Provider account not found.');
        }

        $providerAccount->loadMissing('user');

        $endpoint = $this->niumService->path(
            config('services.nium.payment_ids_endpoint'),
            [
                'client' => $this->niumService->clientId(),
                'customer' => $providerAccount->external_customer_id,
                'wallet' => $providerAccount->external_account_id,
            ]
        );

        $response = $this->niumService->get(
            $endpoint,
            [
                'uniquePaymentId' => $virtualAccount->provider_payment_id,
                'currencyCode' => $virtualAccount->currency,
                'status' => 'Active',
                'page' => 0,
                'size' => 20,
                'order' => 'DESC',
                'property' => 'createdAt',
            ],
            $providerAccount->user,
            'fetch_virtual_account_details',
            'virtual-account-'.$virtualAccount->id,
        );

        if (! $response->successful()) {
            throw new RuntimeException('Failed fetching Nium virtual account details.');
        }

        $data = (array) ($response->json() ?? []);
        $items = $data['content'] ?? $data['paymentIds'] ?? data_get($data, 'data.content') ?? $data['data'] ?? [];
        $items = is_array($items) ? $items : [];
        $requestedPaymentId = (string) $virtualAccount->provider_payment_id;
        $item = collect($items)->first(function ($candidate) use ($requestedPaymentId): bool {
            if (! is_array($candidate)) {
                return false;
            }

            $candidatePaymentId = $candidate['uniquePaymentId']
                ?? $candidate['paymentId']
                ?? $candidate['virtualAccountNumber']
                ?? null;

            return is_string($candidatePaymentId)
                && hash_equals($requestedPaymentId, $candidatePaymentId);
        });

        if (! $item) {
            return null;
        }

        return NiumVirtualAccountDetail::updateOrCreate(
            [
                'nium_virtual_account_id' => $virtualAccount->id,
            ],
            [
                'account_name' => $item['accountName'] ?? null,
                'bank_name' => $item['fullBankName'] ?? $item['bankName'] ?? null,
                'bank_address' => $item['bankAddress'] ?? null,
                'routing_code_type' => $item['routingCodeType1'] ?? null,
                'routing_code_value' => $item['routingCodeValue1'] ?? null,
            ]
        );
    }
}
