<?php

namespace App\Services\Nium;

use App\Models\AuditLog;
use App\Models\IntegrationProvider;
use App\Models\UserProviderAccount;
use Illuminate\Cache\Lock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class NiumCustomerReconciliationService
{
    public function __construct(
        private readonly NiumCustomerOnboardingService $onboarding,
        private readonly NiumRfiWorkflowService $rfiWorkflow,
        private readonly NiumCorporateRfiService $corporateRfi,
    ) {}

    public function reconcile(?int $limit = null): array
    {
        $provider = IntegrationProvider::query()
            ->whereRaw('LOWER(code) = ?', ['nium'])
            ->where('status', 'active')
            ->first();

        $summary = ['selected' => 0, 'reconciled' => 0, 'skipped' => 0, 'failed' => 0];
        if ($provider === null) {
            return $summary;
        }

        $batchSize = max(1, min($limit ?? (int) config('services.nium.customer_reconciliation_batch_size', 50), 200));
        $accounts = $this->eligibleQuery($provider->id)
            ->orderByRaw('COALESCE(reconciliation_requested_at, reconciled_at, created_at) ASC')
            ->orderBy('id')
            ->limit($batchSize)
            ->get();
        $summary['selected'] = $accounts->count();

        foreach ($accounts as $account) {
            $failureStage = 'retrieve_customer';
            $lock = Cache::lock($this->lockKey($account), $this->lockTtlSeconds());
            if (! $lock->get()) {
                $summary['skipped']++;
                Log::info('Nium customer reconciliation skipped because the account lock is held.', ['provider_account_id' => $account->id]);
                continue;
            }

            try {
                $fresh = UserProviderAccount::query()->find($account->id);
                if (! $fresh instanceof UserProviderAccount || ! $this->isEligible($fresh)) {
                    $summary['skipped']++;
                    continue;
                }

                $fresh->forceFill(['reconciliation_requested_at' => now()])->save();
                $updated = $this->onboarding->retrieveCustomer($fresh, requestId: 'scheduled-customer-reconciliation');
                $failureStage = 'fetch_corporate_rfi';
                $rfiEvidence = $this->rfiWorkflow->requiresAuthoritativeRfiFetch($updated)
                    ? $this->corporateRfi->fetch($updated)
                    : null;
                $failureStage = 'reconcile_customer_evidence';
                $this->rfiWorkflow->reconcileCustomerEvidence($updated, $rfiEvidence);
                $updated = $updated->fresh();
                $failureStage = 'clear_failure_metadata';
                $this->clearFailureMetadata($updated);
                $summary['reconciled']++;
                Log::info('Nium customer reconciliation completed.', [
                    'provider_account_id' => $updated->id,
                    'account_status' => $updated->status,
                    'provider_status' => $updated->provider_status,
                    'provider_sub_status' => $updated->provider_sub_status,
                ]);
            } catch (NiumProviderIdConflictException) {
                $summary['failed']++;
                Log::warning('Nium customer reconciliation quarantined an identifier conflict.', [
                    'provider_account_id' => $account->id,
                    'error_category' => 'verified_identifier_conflict',
                ]);
            } catch (Throwable $exception) {
                $summary['failed']++;
                $this->recordFailure($account->id);
                Log::warning('Nium customer reconciliation failed.', [
                    'provider_account_id' => $account->id,
                    'error_category' => 'authoritative_get_customer_failed',
                    'failure_stage' => $failureStage,
                    'diagnostic_category' => $this->diagnosticCategory($exception),
                ]);
            } finally {
                $this->release($lock);
            }
        }

        return $summary;
    }

    public function eligibleQuery(int $providerId): Builder
    {
        $cutoff = now()->subSeconds($this->pollIntervalSeconds());

        return UserProviderAccount::query()
            ->where('provider_id', $providerId)
            ->whereIn('status', ['pending', 'submitted', 'under_review'])
            ->where(function (Builder $query): void {
                $query->whereNull('provider_status')->orWhereNotIn('provider_status', ['rejected', 'failed', 'blocked', 'closed', 'terminated', 'suspended']);
            })
            ->whereNotNull('external_customer_id')->where('external_customer_id', '<>', '')
            ->whereNotNull('customer_id_verified_at')
            ->whereNull('security_conflict_at')
            ->where(function (Builder $query): void {
                $query->whereNull('security_conflict_reason')->orWhere('security_conflict_reason', '');
            })
            ->where(function (Builder $query): void {
                $query->whereNull('reconciliation_status')->orWhereIn('reconciliation_status', ['pending', 'reconciled', 'failed']);
            })
            ->where(function (Builder $query): void {
                $query->whereNull('reconciliation_error')->orWhereIn('reconciliation_error', [
                    'reconciliation_failed', 'scheduled_customer_get_failed',
                ]);
            })
            ->where(function (Builder $query) use ($cutoff): void {
                $query->whereNull('reconciliation_requested_at')->orWhere('reconciliation_requested_at', '<=', $cutoff);
            })
            ->where(function (Builder $query): void {
                $query->whereNull('metadata->nium_customer_reconciliation->next_attempt_at')
                    ->orWhere('metadata->nium_customer_reconciliation->next_attempt_at', '<=', now()->toISOString());
            });
    }

    public function isEligible(UserProviderAccount $account): bool
    {
        $metadata = (array) $account->metadata;
        $nextAttemptAt = Arr::get($metadata, 'nium_customer_reconciliation.next_attempt_at');
        $unsafeCreateState = Arr::get($metadata, 'customer_v5_submission_state');

        return in_array($account->status, ['pending', 'submitted', 'under_review'], true)
            && ! in_array(strtolower((string) $account->provider_status), ['rejected', 'failed', 'blocked', 'closed', 'terminated', 'suspended'], true)
            && filled($account->external_customer_id)
            && $account->customer_id_verified_at !== null
            && $account->security_conflict_at === null
            && blank($account->security_conflict_reason)
            && in_array($account->reconciliation_status, [null, 'pending', 'reconciled', 'failed'], true)
            && in_array($account->reconciliation_error, [null, 'reconciliation_failed', 'scheduled_customer_get_failed'], true)
            && ! in_array($unsafeCreateState, [
                NiumProviderAccountStateService::CUSTOMER_CREATE_SUBMITTING,
                NiumProviderAccountStateService::CUSTOMER_CREATE_UNKNOWN,
            ], true)
            && ($account->reconciliation_requested_at === null
                || $account->reconciliation_requested_at->lte(now()->subSeconds($this->pollIntervalSeconds())))
            && (! is_string($nextAttemptAt) || $nextAttemptAt === '' || now()->gte($nextAttemptAt));
    }

    private function recordFailure(int $accountId): void
    {
        DB::transaction(function () use ($accountId): void {
            $account = UserProviderAccount::query()->lockForUpdate()->find($accountId);
            if (! $account instanceof UserProviderAccount) {
                return;
            }
            $metadata = (array) $account->metadata;
            $failures = min(10, max(0, (int) Arr::get($metadata, 'nium_customer_reconciliation.failure_count')) + 1);
            $delay = min(
                (int) config('services.nium.customer_reconciliation_max_backoff_seconds', 3600),
                $this->pollIntervalSeconds() * (2 ** ($failures - 1)),
            );
            Arr::set($metadata, 'nium_customer_reconciliation', [
                'failure_count' => $failures,
                'last_error_category' => 'authoritative_get_customer_failed',
                'last_failed_at' => now()->toISOString(),
                'next_attempt_at' => now()->addSeconds($delay)->toISOString(),
            ]);
            $account->forceFill([
                'reconciliation_error' => 'scheduled_customer_get_failed',
                'reconciliation_requested_at' => now(),
                'metadata' => $metadata,
            ])->save();
            AuditLog::query()->create([
                'user_id' => $account->user_id,
                'action' => 'provider_account.nium_scheduled_reconciliation_failed',
                'entity_type' => 'user_provider_account',
                'entity_id' => (string) $account->id,
                'new_data' => [
                    'provider_account_id' => $account->id,
                    'error_category' => 'authoritative_get_customer_failed',
                    'failure_count' => $failures,
                    'backoff_seconds' => $delay,
                ],
            ]);
        }, 3);
    }

    private function clearFailureMetadata(UserProviderAccount $account): void
    {
        DB::transaction(function () use ($account): void {
            $locked = UserProviderAccount::query()->lockForUpdate()->find($account->id);
            if (! $locked instanceof UserProviderAccount) {
                return;
            }
            $metadata = (array) $locked->metadata;
            if (! Arr::has($metadata, 'nium_customer_reconciliation')) {
                return;
            }
            Arr::forget($metadata, 'nium_customer_reconciliation');
            $locked->forceFill(['metadata' => $metadata])->save();
        }, 3);
    }

    private function diagnosticCategory(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof NiumProviderRequestException => 'provider_request_failure',
            $exception instanceof ConnectionException => 'provider_connection_failure',
            $exception instanceof QueryException => 'database_failure',
            $exception instanceof \RuntimeException => 'validation_or_state_failure',
            default => 'unexpected_failure',
        };
    }

    private function lockKey(UserProviderAccount $account): string
    {
        return 'provider:nium:customer-reconciliation:'.$account->id;
    }

    private function lockTtlSeconds(): int
    {
        return max(60, (int) config('services.nium.customer_reconciliation_lock_ttl_seconds', 180));
    }

    private function pollIntervalSeconds(): int
    {
        return max(60, (int) config('services.nium.customer_reconciliation_poll_interval_seconds', 900));
    }

    private function release(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable) {
            Log::warning('Nium customer reconciliation lock release failed; expiry will recover it.');
        }
    }
}
