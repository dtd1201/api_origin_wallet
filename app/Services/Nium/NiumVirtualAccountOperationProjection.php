<?php

namespace App\Services\Nium;

use App\Models\IntegrationProvider;
use App\Models\NiumVirtualAccountOperation as Operation;
use App\Models\NiumVirtualAccountRequest;
use App\Models\User;
use App\Models\UserProviderAccount;

/** Local projections only: never enroll, reconcile, dispatch or infer assignment evidence. */
final class NiumVirtualAccountOperationProjection
{
    public const PAGE_SIZE = 50;

    /**
     * GET provider-accounts/{provider} discovery contract:
     * - Owned operations for this provider, regardless of current account eligibility.
     *   The schema permits one account row per user/provider. Operation history lives
     *   on that retained row; enrolled requests retain their frozen target snapshots.
     *   Multiple coexisting replacement/history account rows are unsupported.
     * - Fixed pages of 50, ordered by immutable operation row ID descending.
     * - Pass virtual_account_operations_pagination.next_before_id as
     *   operations_before_id, retaining other filters.
     * - Optional provider_account_id + operation_id identifies a specific caller key.
     *   Ownership always comes from the account relationship, never the caller key.
     * - operations_unresolved_only=1 excludes enrolled terminals and legacy ASSIGNED;
     *   legacy FAILED is included because it does not prove provider rejection.
     *
     * Pagination IDs are boundaries only, never caller-facing operation identities.
     * Start a fresh traversal to observe newer inserts or changes to status filters.
     */
    public function discover(User $user, IntegrationProvider $provider, array $filters): array
    {
        $query = Operation::query()
            ->whereIn('user_provider_account_id', UserProviderAccount::query()
                ->where('user_id', $user->id)->where('provider_id', $provider->id)->select('id'))
            ->when(isset($filters['provider_account_id']), fn ($q) => $q->where('user_provider_account_id', $filters['provider_account_id']))
            ->when(isset($filters['operation_id']), fn ($q) => $q->where('operation_id', $filters['operation_id']))
            ->when(isset($filters['operations_before_id']), fn ($q) => $q->where('id', '<', $filters['operations_before_id']));

        if ($filters['operations_unresolved_only'] ?? false) {
            $query->where(function ($q): void {
                $q->whereNotIn('status', [Operation::ASSIGNED, Operation::FAILED])
                    ->orWhere(fn ($legacy) => $legacy->where('status', Operation::FAILED)
                        ->whereNotIn('id', NiumVirtualAccountRequest::query()->select('operation_id')));
            });
        }

        $rows = $query->orderByDesc('id')->limit(self::PAGE_SIZE + 1)
            ->get(['id', 'operation_id', 'user_provider_account_id', 'status', 'currency',
                'provider_payment_id', 'nium_virtual_account_id', 'updated_at']);
        $hasMore = $rows->count() > self::PAGE_SIZE;
        $page = $rows->take(self::PAGE_SIZE);

        return [
            'virtual_account_operations' => $page->map(fn (Operation $operation): array => $this->operation($operation))->values()->all(),
            'virtual_account_operations_pagination' => [
                'per_page' => self::PAGE_SIZE,
                'has_more' => $hasMore,
                'next_before_id' => $hasMore ? (int) $page->last()->id : null,
            ],
        ];
    }

    /** The persisted operation is authoritative, including legacy nonterminal states. */
    public function operation(Operation $operation): array
    {
        return [
            'operation_id' => $operation->operation_id,
            'provider_account_id' => (int) $operation->user_provider_account_id,
            'status' => $operation->status,
            'currency' => $operation->currency,
            'provider_payment_id' => $operation->provider_payment_id,
            'virtual_account_id' => $operation->nium_virtual_account_id === null ? null : (int) $operation->nium_virtual_account_id,
            'updated_at' => $operation->updated_at?->toISOString(),
        ];
    }
}
