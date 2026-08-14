<?php

namespace App\Services\Nium;

use App\Models\Balance;
use App\Models\IntegrationProvider;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Integrations\Contracts\DataSyncProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NiumDataSyncService implements DataSyncProvider
{
    public function __construct(
        private readonly NiumService $niumService,
        private readonly NiumCustomerOnboardingService $customerOnboardingService,
        private readonly NiumBeneficiaryAccountResolver $accountResolver,
    ) {}

    public function syncAccounts(IntegrationProvider $provider, User $user): array
    {
        $providerAccount = $this->customerOnboardingService->syncUser($provider, $user);

        return [
            'synced_accounts' => 1,
            'provider_account_status' => $providerAccount->status,
            'provider_status' => $providerAccount->provider_status,
            'provider_sub_status' => $providerAccount->provider_sub_status,
        ];
    }

    public function syncBalances(IntegrationProvider $provider, User $user): array
    {
        $endpoint = (string) config('services.nium.wallet_balance_endpoint', '');

        if ($endpoint === '') {
            throw new RuntimeException('NIUM_WALLET_BALANCE_ENDPOINT is not configured.');
        }

        $response = $this->niumService->get(
            path: $this->niumService->path($endpoint, [
                'client' => $this->niumService->clientId(),
                'customer' => $this->niumService->customerId($user),
                'wallet' => $this->niumService->walletId($user),
            ]),
            user: $user,
        );

        $data = $this->successfulJson($response, 'Nium balance sync failed.');
        $items = $this->balanceItems($data);
        $count = 0;

        DB::transaction(function () use ($items, $provider, $user, &$count): void {
            foreach ($items as $item) {
                $currency = strtoupper((string) $this->value($item, [
                    'currency',
                    'currencyCode',
                    'currency_code',
                    'curSymbol',
                ]));

                if ($currency === '') {
                    continue;
                }

                $externalAccountId = (string) (
                    $this->value($item, ['walletHashId', 'wallet_hash_id', 'walletId', 'wallet_id'])
                    ?: $this->niumService->walletId($user)
                );
                $available = $this->numericValue($item, [
                    'availableBalance',
                    'available_balance',
                    'available',
                    'availableAmount',
                    'balance',
                    'amount',
                ]);
                $ledger = $this->numericValue($item, [
                    'ledgerBalance',
                    'ledger_balance',
                    'currentBalance',
                    'current_balance',
                    'balance',
                    'amount',
                ], $available);
                $reserved = $this->numericValue($item, [
                    'reservedBalance',
                    'reserved_balance',
                    'withHoldingBalance',
                    'with_holding_balance',
                    'holdBalance',
                    'hold_balance',
                    'blockedBalance',
                    'blocked_balance',
                ]);

                $existing = Balance::query()
                    ->where('provider_id', $provider->id)
                    ->where('external_account_id', $externalAccountId)
                    ->where('currency', $currency)
                    ->lockForUpdate()
                    ->first();

                Balance::query()->updateOrCreate(
                    [
                        'provider_id' => $provider->id,
                        'external_account_id' => $externalAccountId,
                        'currency' => $currency,
                    ],
                    [
                        'user_id' => $user->id,
                        'available_balance' => $available,
                        'ledger_balance' => $ledger,
                        'reserved_balance' => $reserved ?? ($existing?->reserved_balance ?? 0),
                        'as_of' => $this->value($item, ['asOf', 'as_of', 'updatedAt', 'updated_at']) ?? now(),
                        'raw_data' => array_filter([
                            'wallet_id' => $externalAccountId,
                            'currency' => $currency,
                            'provider_status' => $this->value($item, ['status']),
                        ], static fn ($value) => $value !== null && $value !== ''),
                    ],
                );

                $count++;
            }
        });

        return ['synced_balances' => $count];
    }

    public function syncTransactions(IntegrationProvider $provider, User $user): array
    {
        return $this->syncTransactionsFor($provider, $user);
    }

    public function syncTransactionsFor(IntegrationProvider $provider, User $user, array $filters = []): array
    {
        $endpoint = (string) config('services.nium.wallet_transactions_endpoint', '');

        if ($endpoint === '') {
            throw new RuntimeException('NIUM_WALLET_TRANSACTIONS_ENDPOINT is not configured.');
        }

        $targetedFilters = array_filter(Arr::only($filters, [
            'authCode', 'systemReferenceNumber', 'externalId', 'complianceStatus',
        ]), static fn ($value) => $value !== null && $value !== '');
        $isTargeted = $targetedFilters !== [];
        $account = $this->accountResolver->resolve($user, $provider->id);
        $startAt = $account->transactions_last_synced_at?->copy()->subDay()
            ?? now()->subDays((int) config('services.nium.transaction_sync_days', 30));
        $pageSize = min(max((int) config('services.nium.transaction_sync_page_size', 20), 1), 20);
        $maxPages = min(max((int) config('services.nium.transaction_sync_max_pages', 20), 1), 100);
        $items = [];

        for ($page = 0; $page < $maxPages; $page++) {
            $response = $this->niumService->get(
                path: $this->niumService->path($endpoint, [
                    'client' => $this->niumService->clientId(),
                    'customer' => (string) $account->external_customer_id,
                    'wallet' => (string) $account->external_account_id,
                ]),
                query: array_filter([
                    'startDate' => $isTargeted ? null : $startAt->toDateString(),
                    'endDate' => $isTargeted ? null : now()->toDateString(),
                    'page' => $page,
                    'size' => $pageSize,
                    'order' => 'DESC',
                    'authCode' => $targetedFilters['authCode'] ?? null,
                    'systemReferenceNumber' => $targetedFilters['systemReferenceNumber'] ?? null,
                    'externalId' => $targetedFilters['externalId'] ?? null,
                    'complianceStatus' => $targetedFilters['complianceStatus'] ?? null,
                ], static fn ($value) => $value !== null && $value !== ''),
                user: $user,
            );

            $data = $this->successfulJson($response, 'Nium transaction sync failed.');
            $pageItems = $this->items($data, ['transactions', 'data.transactions', 'wallet.transactions', 'content', 'data']);
            $items = [...$items, ...$pageItems];

            $totalPages = (int) (Arr::get($data, 'totalPages') ?? Arr::get($data, 'data.totalPages') ?? 0);
            if ($pageItems === [] || count($pageItems) < $pageSize || ($totalPages > 0 && $page + 1 >= $totalPages)) {
                break;
            }
        }
        $count = 0;

        DB::transaction(function () use ($items, $provider, $user, &$count): void {
            foreach ($items as $item) {
                $externalTransactionId = $this->value($item, [
                    'transactionId',
                    'transaction_id',
                    'authCode',
                    'systemReferenceNumber',
                    'system_reference_number',
                    'id',
                ]);

                if (! filled($externalTransactionId)) {
                    continue;
                }

                $transferReference = $this->value($item, [
                    'systemReferenceNumber',
                    'system_reference_number',
                    'paymentId',
                    'payment_id',
                    'clientReference',
                    'client_reference',
                ]);
                $transfer = $this->findTransfer($provider, $transferReference);
                $currency = strtoupper((string) ($this->value($item, ['currency', 'currencyCode']) ?: $transfer?->source_currency ?: 'USD'));
                $amount = $this->numericValue($item, ['amount', 'transactionAmount', 'sourceAmount'], 0);
                $complianceStatus = strtoupper((string) $this->value($item, ['complianceStatus']));
                $requiresReview = in_array($complianceStatus, ['RFI_REQUESTED', 'ACTION_REQUIRED'], true);

                Transaction::query()->updateOrCreate(
                    [
                        'provider_id' => $provider->id,
                        'external_transaction_id' => (string) $externalTransactionId,
                    ],
                    [
                        'user_id' => $user->id,
                        'bank_account_id' => null,
                        'transfer_id' => $transfer?->id,
                        'transaction_type' => $this->value($item, ['type', 'transactionType']),
                        'direction' => $this->direction($item, $amount),
                        'currency' => $currency,
                        'amount' => abs((float) $amount),
                        'fee_amount' => $this->numericValue($item, ['fee', 'feeAmount', 'markupAmount'], 0),
                        'description' => $this->value($item, ['description', 'remarks', 'narration']),
                        'reference_text' => $this->value($item, ['reference', 'clientReference', 'customerComments']),
                        'status' => $this->normalizeTransactionStatus($this->value($item, ['status'])),
                        'compliance_review_required' => $requiresReview,
                        'compliance_status' => $complianceStatus !== '' ? $complianceStatus : null,
                        'compliance_reviewed_at' => $requiresReview ? null : now(),
                        'booked_at' => $this->value($item, ['dateTime', 'createdAt', 'transactionDate']) ?? now(),
                        'value_date' => $this->value($item, ['valueDate', 'date']) ?? now(),
                        'raw_data' => array_filter([
                            'external_transaction_id' => (string) $externalTransactionId,
                            'provider_request_id' => $this->value($item, ['requestId', 'request_id']),
                            'provider_status' => $this->value($item, ['status']),
                            'system_reference_number' => $this->value($item, ['systemReferenceNumber', 'system_reference_number']),
                            'auth_code' => $this->value($item, ['authCode']),
                            'external_id' => $this->value($item, ['externalId']),
                            'compliance_status' => $complianceStatus !== '' ? $complianceStatus : null,
                            'rfi_details' => $this->safeRfiDetails((array) ($item['rfiDetails'] ?? [])),
                        ], static fn ($value) => $value !== null && $value !== ''),
                    ],
                );

                $count++;

                if ($transfer !== null && $complianceStatus !== '') {
                    $transfer->update([
                        'compliance_review_required' => $requiresReview,
                        'compliance_status' => $complianceStatus,
                        'compliance_reviewed_at' => $requiresReview ? null : now(),
                    ]);
                }
            }
        });

        if (! $isTargeted) {
            $account->update(['transactions_last_synced_at' => now()]);
        }

        return ['synced_transactions' => $count];
    }

    private function safeRfiDetails(array $details): array
    {
        return array_values(array_map(static function (array $detail): array {
            $requiredData = array_values(array_map(static fn (array $item): array => array_filter([
                'label' => $item['label'] ?? $item['name'] ?? null,
                'type' => $item['type'] ?? null,
                'mandatory' => $item['mandatory'] ?? null,
            ], static fn ($value) => $value !== null && $value !== ''), array_values(array_filter((array) ($detail['requiredData'] ?? []), 'is_array'))));

            return array_filter([
                'rfiHashId' => $detail['rfiHashId'] ?? null,
                'rfiStatus' => $detail['rfiStatus'] ?? null,
                'rfiCategory' => $detail['rfiCategory'] ?? null,
                'type' => $detail['type'] ?? null,
                'transactionEntityType' => $detail['transactionEntityType'] ?? null,
                'documentType' => $detail['documentType'] ?? null,
                'mandatory' => $detail['mandatory'] ?? null,
                'requiredData' => $requiredData,
            ], static fn ($value) => $value !== null && $value !== [] && $value !== '');
        }, array_values(array_filter($details, 'is_array'))));
    }

    private function successfulJson(Response $response, string $message): array
    {
        $data = $response->json() ?? ['raw' => $response->body()];

        if (! $response->successful()) {
            throw new RuntimeException((string) ($data['message'] ?? $data['error'] ?? $message));
        }

        return is_array($data) ? $data : [];
    }

    private function balanceItems(array $data): array
    {
        $items = $this->items($data, [
            'balances',
            'data.balances',
            'wallet.balances',
            'data.wallet.balances',
            'currencies',
            'data',
        ]);

        if ($items !== []) {
            return $items;
        }

        return $this->value($data, ['currency', 'currencyCode', 'curSymbol']) !== null ? [$data] : [];
    }

    private function items(array $data, array $paths): array
    {
        if (array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }

        foreach ($paths as $path) {
            $value = Arr::get($data, $path);

            if (is_array($value) && $value !== []) {
                if (array_is_list($value)) {
                    return array_values(array_filter($value, 'is_array'));
                }

                return collect($value)
                    ->map(function ($item, $key): array {
                        $item = is_array($item) ? $item : ['amount' => $item];

                        return is_string($key) ? ['currency' => $key, ...$item] : $item;
                    })
                    ->values()
                    ->all();
            }
        }

        return [];
    }

    private function value(array $item, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = Arr::get($item, $path);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function numericValue(array $item, array $paths, mixed $default = null): mixed
    {
        $value = $this->value($item, $paths);

        return is_numeric($value) ? $value : $default;
    }

    private function findTransfer(IntegrationProvider $provider, mixed $reference): ?Transfer
    {
        if (! filled($reference)) {
            return null;
        }

        return Transfer::query()
            ->where('provider_id', $provider->id)
            ->where(function ($query) use ($reference): void {
                $query->where('external_transfer_id', $reference)
                    ->orWhere('external_payment_id', $reference)
                    ->orWhere('transfer_no', $reference)
                    ->orWhere('client_reference', $reference);
            })
            ->first();
    }

    private function direction(array $item, mixed $amount): string
    {
        $direction = strtolower((string) $this->value($item, ['direction', 'debitCreditIndicator']));

        if (in_array($direction, ['credit', 'debit'], true)) {
            return $direction;
        }

        return (float) $amount < 0 ? 'debit' : 'credit';
    }

    private function normalizeTransactionStatus(mixed $status): string
    {
        return match (strtolower((string) $status)) {
            'completed', 'success', 'paid', 'posted' => 'completed',
            'failed', 'rejected', 'returned', 'error' => 'failed',
            'cancelled', 'canceled', 'voided' => 'cancelled',
            default => 'pending',
        };
    }
}
