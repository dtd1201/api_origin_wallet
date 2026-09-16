<?php

namespace App\Services\Integrations;

use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Services\Integrations\Contracts\PreparedTransferStatusProvider;
use App\Services\Transfers\TransferEligibilityService;
use App\Services\Wallet\LedgerService;
use Illuminate\Support\Facades\DB;
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
        $this->eligibilityService->ensureTransferCanBeSubmitted(
            $transfer->loadMissing(['provider', 'user', 'beneficiary', 'sourceBankAccount'])
        );
        $this->ledgerService->reserveTransfer($transfer);

        try {
            return $this->registry
                ->resolveTransferProvider($provider)
                ->submitTransfer($provider, $transfer->fresh(['user', 'beneficiary', 'sourceBankAccount']));
        } catch (Throwable $exception) {
            try {
                $this->ledgerService->releaseTransferHold($transfer->fresh(), 'Transfer hold released after provider submission failure.');
            } catch (Throwable) {
                // Preserve the provider failure as the primary error returned to the caller.
            }

            throw $exception;
        }
    }

    public function syncTransferStatus(IntegrationProvider $provider, Transfer $transfer): Transfer
    {
        $transferProvider = $this->registry->resolveTransferProvider($provider);

        if ($transferProvider instanceof PreparedTransferStatusProvider) {
            // Provider I/O must remain outside the database transaction.
            $prepared = $transferProvider->prepareTransferStatusSync($provider, $transfer);

            return DB::transaction(function () use ($provider, $transfer, $transferProvider, $prepared): Transfer {
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
