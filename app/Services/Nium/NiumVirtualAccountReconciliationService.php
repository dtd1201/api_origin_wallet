<?php

namespace App\Services\Nium;

use App\Models\NiumVirtualAccount;
use App\Models\NiumVirtualAccountOperation;
use Illuminate\Support\Facades\DB;
use Throwable;

final class NiumVirtualAccountReconciliationService
{
    private const PAGE_SIZE = 20;

    private const MAX_PAGES = 5;

    public function __construct(private readonly NiumService $niumService) {}

    public function captureBaseline(NiumVirtualAccountOperation $operation): NiumVirtualAccountOperation
    {
        try {
            $result = $this->fetch($operation);
        } catch (Throwable) {
            $operation->update([
                'status' => NiumVirtualAccountOperation::PREFLIGHT_FAILED,
                'baseline_complete' => false,
                'resolution_code' => 'baseline_lookup_failed',
            ]);

            return $operation->fresh();
        }

        $operation->update([
            'status' => $result['complete']
                ? NiumVirtualAccountOperation::CLAIMED
                : NiumVirtualAccountOperation::PREFLIGHT_FAILED,
            'baseline_payment_ids' => array_values(array_unique(array_filter(array_map(
                fn (array $item): ?string => $this->paymentId($item),
                $result['items'],
            )))),
            'baseline_complete' => $result['complete'],
            'resolution_code' => $result['complete'] ? null : 'baseline_lookup_incomplete',
        ]);

        return $operation->fresh();
    }

    public function reconcile(NiumVirtualAccountOperation $operation): NiumVirtualAccountOperation
    {
        $operation = $operation->fresh();

        if ($operation->status === NiumVirtualAccountOperation::ASSIGNED) {
            return $operation;
        }

        try {
            $result = $this->fetch($operation);
        } catch (Throwable) {
            $operation->update(['resolution_code' => 'reconciliation_lookup_failed']);

            return $operation->fresh();
        }

        if (! $operation->baseline_complete || ! $result['complete']) {
            $operation->update([
                'status' => NiumVirtualAccountOperation::MANUAL_REVIEW,
                'resolution_code' => 'incomplete_provider_listing',
            ]);

            return $operation->fresh();
        }

        $baseline = array_fill_keys((array) $operation->baseline_payment_ids, true);
        $candidates = collect($result['items'])
            ->filter(fn (array $item): bool => $this->matchesDimensions($operation, $item))
            ->map(fn (array $item): ?string => $this->paymentId($item))
            ->filter()
            ->unique()
            ->reject(fn (string $paymentId): bool => isset($baseline[$paymentId]))
            ->values();

        if ($candidates->count() === 1) {
            $paymentId = $candidates->sole();
            $ownedByAnotherVirtualAccount = NiumVirtualAccount::query()
                ->where('user_provider_account_id', $operation->user_provider_account_id)
                ->where('provider_payment_id', $paymentId)
                ->whereKeyNot($operation->nium_virtual_account_id)
                ->exists();
            $overlappingOperationIds = NiumVirtualAccountOperation::query()
                ->where('user_provider_account_id', $operation->user_provider_account_id)
                ->whereKeyNot($operation->id)
                ->where('currency', $operation->currency)
                ->where('account_category', $operation->account_category)
                ->where('account_type', $operation->account_type)
                ->whereNotNull('submitted_at')
                ->whereIn('status', [
                    NiumVirtualAccountOperation::SUBMITTING,
                    NiumVirtualAccountOperation::UNKNOWN,
                    NiumVirtualAccountOperation::PENDING,
                    NiumVirtualAccountOperation::MANUAL_REVIEW,
                ])
                ->pluck('id');

            if ($ownedByAnotherVirtualAccount || $overlappingOperationIds->isNotEmpty()) {
                NiumVirtualAccountOperation::query()
                    ->whereIn('id', $overlappingOperationIds->push($operation->id)->all())
                    ->update([
                        'status' => NiumVirtualAccountOperation::MANUAL_REVIEW,
                        'resolution_code' => $ownedByAnotherVirtualAccount
                            ? 'payment_id_already_owned'
                            : 'overlapping_assignment_operations',
                        'updated_at' => now(),
                    ]);

                return $operation->fresh();
            }

            return $this->assign($operation, $paymentId, 'reconciled_unique_new_payment_id');
        }

        $operation->update([
            'status' => $candidates->count() > 1
                ? NiumVirtualAccountOperation::MANUAL_REVIEW
                : $operation->status,
            'resolution_code' => $candidates->count() > 1
                ? 'ambiguous_new_payment_ids'
                : 'no_new_payment_id_observed',
        ]);

        return $operation->fresh();
    }

    public function assign(
        NiumVirtualAccountOperation $operation,
        string $paymentId,
        string $resolutionCode,
    ): NiumVirtualAccountOperation {
        return DB::transaction(function () use ($operation, $paymentId, $resolutionCode): NiumVirtualAccountOperation {
            $locked = NiumVirtualAccountOperation::query()->lockForUpdate()->findOrFail($operation->id);
            $exact = NiumVirtualAccount::query()
                ->where('user_provider_account_id', $locked->user_provider_account_id)
                ->where('provider_payment_id', $paymentId)
                ->first();

            if ($exact === null) {
                $candidate = NiumVirtualAccount::query()->lockForUpdate()->find($locked->nium_virtual_account_id);
                if ($candidate !== null && $candidate->status === 'pending' && blank($candidate->provider_payment_id)) {
                    $candidate->update([
                        'provider_payment_id' => $paymentId,
                        'virtual_account_reference' => $paymentId,
                        'status' => 'assigned',
                        'assigned_at' => now(),
                    ]);
                    $exact = $candidate->fresh();
                } else {
                    $exact = NiumVirtualAccount::query()->create([
                        'user_provider_account_id' => $locked->user_provider_account_id,
                        'provider_payment_id' => $paymentId,
                        'virtual_account_reference' => $paymentId,
                        'currency' => $locked->currency,
                        'account_category' => $locked->account_category,
                        'account_type' => $locked->account_type,
                        'status' => 'assigned',
                        'assigned_at' => now(),
                    ]);
                }
            } elseif ($exact->id !== $locked->nium_virtual_account_id) {
                $locked->update([
                    'status' => NiumVirtualAccountOperation::MANUAL_REVIEW,
                    'resolution_code' => 'payment_id_already_owned',
                ]);

                return $locked->fresh('virtualAccount');
            }

            $locked->update([
                'nium_virtual_account_id' => $exact->id,
                'provider_payment_id' => $paymentId,
                'status' => NiumVirtualAccountOperation::ASSIGNED,
                'resolution_code' => $resolutionCode,
                'resolved_at' => now(),
            ]);

            return $locked->fresh('virtualAccount');
        });
    }

    /** @return array{items: list<array>, complete: bool} */
    private function fetch(NiumVirtualAccountOperation $operation): array
    {
        $account = $operation->providerAccount()->with('user')->firstOrFail();
        $items = [];
        $complete = false;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->niumService->get(
                $this->niumService->path((string) config('services.nium.payment_ids_endpoint'), [
                    'client' => $this->niumService->clientId(),
                    'customer' => $account->external_customer_id,
                    'wallet' => $account->external_account_id,
                ]),
                [
                    'currencyCode' => $operation->currency,
                    'status' => 'Active',
                    'page' => $page,
                    'size' => self::PAGE_SIZE,
                    'order' => 'DESC',
                    'property' => 'createdAt',
                ],
                $account->user,
                'reconcile_virtual_account_assignment',
                $operation->operation_id,
            );

            if (! $response->successful()) {
                throw new \RuntimeException('Failed fetching Nium payment IDs for reconciliation.');
            }

            $data = (array) ($response->json() ?? []);
            $pageItems = $this->items($data);
            array_push($items, ...$pageItems);

            $totalPages = data_get($data, 'totalPages', data_get($data, 'page.totalPages'));
            $last = data_get($data, 'last', data_get($data, 'page.last'));

            if (is_numeric($totalPages)) {
                $complete = $page + 1 >= (int) $totalPages;
            } elseif (is_bool($last)) {
                $complete = $last;
            } else {
                $complete = count($pageItems) < self::PAGE_SIZE;
            }

            if ($complete) {
                break;
            }
        }

        return ['items' => $items, 'complete' => $complete];
    }

    /** @return list<array> */
    private function items(array $data): array
    {
        $items = $data['content'] ?? $data['paymentIds'] ?? data_get($data, 'data.content') ?? $data['data'] ?? [];

        return is_array($items)
            ? array_values(array_filter($items, static fn ($item): bool => is_array($item)))
            : [];
    }

    private function paymentId(array $item): ?string
    {
        $paymentId = $item['uniquePaymentId'] ?? $item['paymentId'] ?? $item['virtualAccountNumber'] ?? null;

        return is_string($paymentId) && $paymentId !== '' ? $paymentId : null;
    }

    private function matchesDimensions(NiumVirtualAccountOperation $operation, array $item): bool
    {
        foreach ([
            'currencyCode' => $operation->currency,
            'accountCategory' => $operation->account_category,
            'accountType' => $operation->account_type,
        ] as $key => $expected) {
            if (filled($item[$key] ?? null) && strtoupper((string) $item[$key]) !== $expected) {
                return false;
            }
        }

        return true;
    }
}
