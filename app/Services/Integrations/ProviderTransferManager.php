<?php

namespace App\Services\Integrations;

use App\Services\Wallet\Concurrency\OwnerFirst;
use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Services\Integrations\Contracts\PreparedTransferStatusProvider;
use App\Services\Nium\NiumPayoutPreDispatchException;
use App\Services\Nium\NiumRemittanceDispatchHeldException;
use App\Services\Nium\NiumSubmissionPreflightException;
use App\Services\Transfers\TransferEligibilityService;
use App\Services\Wallet\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProviderTransferManager
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly LedgerService $ledgerService,
        private readonly TransferEligibilityService $eligibilityService,
    ) {}

    public function submitTransfer(IntegrationProvider $provider, Transfer $transfer): Transfer
    {
        OwnerFirst::outside();
        if (\App\Services\Nium\NiumTransferReviewService::applies($transfer)) {
            $reviews = app(\App\Services\Nium\NiumTransferReviewService::class);
            $reviews->assertAccepted($transfer->loadMissing(['user', 'beneficiary', 'fxQuote']));
            $reviews->ensureFreshBalance($transfer);
        }
        $this->eligibilityService->ensureTransferCanBeSubmitted(
            $transfer->loadMissing(['provider', 'user', 'beneficiary', 'sourceBankAccount'])
        );
        $hold = $this->ledgerService->reserveTransfer($transfer);
        // Only the INSERTing call owns cleanup; idempotent readers do not.
        $ownedHold = $hold?->wasRecentlyCreated === true ? $hold : null;

        try {
            return $this->registry
                ->resolveTransferProvider($provider)
                ->submitTransfer($provider, $transfer->fresh(['user', 'beneficiary', 'sourceBankAccount']));
        } catch (Throwable $exception) {
            $cleanupFailureCode = null;
            try {
                $current = $transfer->fresh();
                // Only this known internal code is safe to include; provider codes are not logged.
                if ($current?->failure_code === 'remittance_dispatch_held') {
                    $cleanupFailureCode = 'remittance_dispatch_held';
                }
                $preflight = $exception instanceof NiumSubmissionPreflightException;
                $transportNoWire = ($exception instanceof NiumRemittanceDispatchHeldException
                    || $exception instanceof NiumPayoutPreDispatchException)
                    && filled($exception->noWireOperationKey);
                if ($ownedHold !== null && ($preflight || $transportNoWire)) {
                    $this->ledgerService->releaseOwnedTransferHoldBeforeWire(
                        $current,
                        $ownedHold,
                        $transportNoWire ? $exception->noWireOperationKey : null,
                    );
                }
                // Unknown exceptions and provider responses after a POST do not
                // prove no-wire. Keep funds held for authoritative reconciliation.
            } catch (Throwable $cleanupException) {
                try {
                    Log::error('Transfer reservation cleanup failed.', [
                        'transfer_id' => $transfer->id,
                        'phase' => 'reservation_cleanup',
                        'cleanup_exception_class' => $cleanupException::class,
                        'primary_exception_class' => $exception::class,
                        'failure_code' => $cleanupFailureCode,
                    ]);
                } catch (Throwable) {
                    // Logging failure must not replace the original submission exception.
                }
            }

            throw $exception;
        }
    }

    public function syncTransferStatus(IntegrationProvider $provider, Transfer $transfer): Transfer
    {
        OwnerFirst::outside();
        $transferProvider = $this->registry->resolveTransferProvider($provider);

        if ($transferProvider instanceof PreparedTransferStatusProvider) {
            // Provider I/O must remain outside the database transaction.
            $prepared = $transferProvider->prepareTransferStatusSync($provider, $transfer);

            return OwnerFirst::transfer($transfer, function () use ($provider, $transfer, $transferProvider, $prepared): Transfer {
                $locked = Transfer::query()
                    ->whereKey($transfer->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $updated = $transferProvider->applyPreparedTransferStatus(
                    $provider,
                    $locked,
                    $prepared,
                );

                $this->ledgerService->applyTransferTerminalStatus($updated);

                return $updated->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
            });
        }

        $transfer = $transferProvider->syncTransferStatus($provider, $transfer);

        $this->ledgerService->applyTransferTerminalStatus($transfer);

        return $transfer->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
    }
}
