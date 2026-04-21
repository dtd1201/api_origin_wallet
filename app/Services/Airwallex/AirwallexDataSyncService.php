<?php

namespace App\Services\Airwallex;

use App\Models\Balance;
use App\Models\BankAccount;
use App\Models\IntegrationProvider;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Integrations\Contracts\DataSyncProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AirwallexDataSyncService implements DataSyncProvider
{
    public function __construct(
        private readonly AirwallexService $airwallexService,
    ) {
    }

    public function syncAccounts(IntegrationProvider $provider, User $user): array
    {
        $response = $this->airwallexService->get(
            path: (string) config('services.airwallex.global_accounts_endpoint'),
            user: $user,
        );

        $items = $this->items($response);
        $count = 0;

        DB::transaction(function () use ($items, $provider, $user, &$count): void {
            foreach ($items as $item) {
                $externalAccountId = $this->externalAccountId($item);

                if (! filled($externalAccountId)) {
                    continue;
                }

                BankAccount::updateOrCreate(
                    [
                        'provider_id' => $provider->id,
                        'external_account_id' => $externalAccountId,
                    ],
                    [
                        'user_id' => $user->id,
                        'account_type' => $item['account_type'] ?? 'global',
                        'currency' => $this->value($item, [
                            'currency',
                            'account_currency',
                            'account_details.account_currency',
                        ]) ?? 'USD',
                        'country_code' => $this->value($item, [
                            'country_code',
                            'bank_country_code',
                            'account_details.bank_country_code',
                        ]),
                        'bank_name' => $this->value($item, [
                            'bank_name',
                            'bank_details.bank_name',
                        ]),
                        'bank_code' => $this->value($item, [
                            'bank_code',
                            'bank_details.bank_code',
                            'account_routing_value1',
                            'bank_details.account_routing_value1',
                        ]),
                        'branch_code' => $this->value($item, [
                            'branch_code',
                            'bank_details.branch_code',
                        ]),
                        'account_name' => $this->value($item, [
                            'account_name',
                            'account_details.account_name',
                            'nick_name',
                            'nickname',
                        ]) ?? $user->full_name,
                        'account_number' => $this->value($item, [
                            'account_number',
                            'account_details.account_number',
                        ]),
                        'iban' => $this->value($item, [
                            'iban',
                            'account_details.iban',
                        ]),
                        'swift_bic' => $this->value($item, [
                            'swift_code',
                            'swift_bic',
                            'bank_details.swift_code',
                        ]),
                        'routing_number' => $this->value($item, [
                            'routing_number',
                            'account_routing_value2',
                            'bank_details.account_routing_value2',
                        ]),
                        'status' => $this->normalizeStatus($this->value($item, ['status']) ?? 'active'),
                        'is_default' => (bool) ($item['is_default'] ?? false),
                        'raw_data' => $item,
                    ]
                );

                $count++;
            }
        });

        return ['synced_accounts' => $count];
    }

    public function syncBalances(IntegrationProvider $provider, User $user): array
    {
        $response = $this->airwallexService->get(
            path: (string) config('services.airwallex.balances_endpoint'),
            user: $user,
        );

        $items = $this->items($response);
        $count = 0;

        DB::transaction(function () use ($items, $provider, $user, &$count): void {
            foreach ($items as $item) {
                $currency = $this->value($item, ['currency']) ?? null;

                if (! filled($currency)) {
                    continue;
                }

                $externalAccountId = $this->externalAccountId($item);
                $bankAccount = filled($externalAccountId)
                    ? BankAccount::query()
                        ->where('provider_id', $provider->id)
                        ->where('external_account_id', $externalAccountId)
                        ->first()
                    : null;

                Balance::updateOrCreate(
                    [
                        'provider_id' => $provider->id,
                        'external_account_id' => $externalAccountId,
                        'currency' => $currency,
                    ],
                    [
                        'user_id' => $user->id,
                        'bank_account_id' => $bankAccount?->id,
                        'available_balance' => $this->numeric($item, [
                            'available_amount',
                            'available_balance',
                            'available',
                        ]),
                        'ledger_balance' => $this->numeric($item, [
                            'total_amount',
                            'current_balance',
                            'ledger_balance',
                            'balance',
                        ]),
                        'reserved_balance' => $this->numeric($item, [
                            'reserved_amount',
                            'reserved_balance',
                        ]),
                        'as_of' => $this->value($item, [
                            'updated_at',
                            'created_at',
                            'as_of',
                        ]) ?? now(),
                        'raw_data' => $item,
                    ]
                );

                $count++;
            }
        });

        return ['synced_balances' => $count];
    }

    public function syncTransactions(IntegrationProvider $provider, User $user): array
    {
        $path = (string) config('services.airwallex.transactions_endpoint');
        $response = str_contains($path, 'search')
            ? $this->airwallexService->post(
                path: $path,
                payload: ['page_num' => 0, 'page_size' => 100],
                user: $user,
            )
            : $this->airwallexService->get(
                path: $path,
                query: ['page_num' => 0, 'page_size' => 100],
                user: $user,
            );

        $items = $this->items($response);
        $count = 0;

        DB::transaction(function () use ($items, $provider, $user, &$count): void {
            foreach ($items as $item) {
                $externalTransactionId = $this->value($item, [
                    'id',
                    'payment_event_id',
                    'transaction_id',
                ]);

                if (! filled($externalTransactionId)) {
                    continue;
                }

                $externalAccountId = $this->externalAccountId($item);
                $bankAccount = filled($externalAccountId)
                    ? BankAccount::query()
                        ->where('provider_id', $provider->id)
                        ->where('external_account_id', $externalAccountId)
                        ->first()
                    : null;

                $externalTransferId = $this->value($item, [
                    'transfer_id',
                    'payment_id',
                ]);

                $transfer = filled($externalTransferId)
                    ? Transfer::query()
                        ->where('provider_id', $provider->id)
                        ->where(function ($query) use ($externalTransferId): void {
                            $query->where('external_transfer_id', $externalTransferId)
                                ->orWhere('external_payment_id', $externalTransferId);
                        })
                        ->first()
                    : null;

                Transaction::updateOrCreate(
                    [
                        'provider_id' => $provider->id,
                        'external_transaction_id' => $externalTransactionId,
                    ],
                    [
                        'user_id' => $user->id,
                        'bank_account_id' => $bankAccount?->id,
                        'transfer_id' => $transfer?->id,
                        'transaction_type' => $this->value($item, ['transaction_type', 'type']),
                        'direction' => $this->normalizeDirection($this->value($item, ['direction', 'flow_type'])),
                        'currency' => $this->value($item, ['currency']) ?? 'USD',
                        'amount' => $this->numeric($item, ['amount', 'gross_amount', 'net_amount']),
                        'fee_amount' => $this->numeric($item, ['fee_amount']),
                        'description' => $this->value($item, ['description', 'short_reference']),
                        'reference_text' => $this->value($item, ['reference', 'reference_text']),
                        'status' => $this->normalizeStatus($this->value($item, ['status'])),
                        'booked_at' => $this->value($item, ['created_at', 'booked_at']),
                        'value_date' => $this->value($item, ['value_date']),
                        'raw_data' => $item,
                    ]
                );

                $count++;
            }
        });

        return ['synced_transactions' => $count];
    }

    private function items(Response $response): array
    {
        if (! $response->successful()) {
            $responseData = $response->json() ?? ['raw' => $response->body()];

            throw new RuntimeException($responseData['message'] ?? 'Airwallex sync request failed.');
        }

        $payload = $response->json() ?? [];
        $items = Arr::get($payload, 'items')
            ?? Arr::get($payload, 'data.items')
            ?? Arr::get($payload, 'data')
            ?? Arr::get($payload, 'records')
            ?? $payload;

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    private function externalAccountId(array $item): ?string
    {
        return $this->value($item, [
            'global_account_id',
            'account_id',
            'id',
        ]);
    }

    private function value(array $item, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = Arr::get($item, $key);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function numeric(array $item, array $keys): float
    {
        $value = $this->value($item, $keys);

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function normalizeStatus(?string $status): ?string
    {
        if (! filled($status)) {
            return null;
        }

        return match (strtoupper((string) $status)) {
            'ACTIVE', 'ENABLED' => 'active',
            'PENDING', 'PROCESSING', 'IN_PROGRESS', 'SCHEDULED', 'IN_APPROVAL' => 'pending',
            'SUCCEEDED', 'COMPLETED', 'PAID', 'SENT' => 'completed',
            'FAILED', 'ERROR', 'RETURNED', 'REJECTED' => 'failed',
            'CANCELLED', 'VOIDED' => 'cancelled',
            default => strtolower((string) $status),
        };
    }

    private function normalizeDirection(?string $direction): ?string
    {
        if (! filled($direction)) {
            return null;
        }

        return match (strtoupper((string) $direction)) {
            'IN', 'CREDIT', 'INBOUND' => 'credit',
            'OUT', 'DEBIT', 'OUTBOUND' => 'debit',
            default => strtolower((string) $direction),
        };
    }
}
