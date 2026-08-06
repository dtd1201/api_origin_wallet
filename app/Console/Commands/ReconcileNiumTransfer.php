<?php

namespace App\Console\Commands;

use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Services\Integrations\ProviderTransferManager;
use Illuminate\Console\Command;

class ReconcileNiumTransfer extends Command
{
    protected $signature = 'nium:reconcile-transfer {transfer : Internal transfer ID}';

    protected $description = 'Reconcile one Nium transfer using the authoritative remittance audit endpoint';

    public function handle(ProviderTransferManager $manager): int
    {
        $transfer = Transfer::query()->findOrFail((int) $this->argument('transfer'));
        $provider = IntegrationProvider::query()->findOrFail($transfer->provider_id);

        if (strtolower((string) $provider->code) !== 'nium') {
            $this->error('The selected transfer is not a Nium transfer.');

            return self::FAILURE;
        }

        if (! filled($transfer->external_transfer_id)) {
            $this->error('No authoritative Nium reference is available; human review is required and the POST must not be retried.');

            return self::FAILURE;
        }

        $updated = $manager->syncTransferStatus($provider, $transfer->load(['user', 'beneficiary', 'sourceBankAccount']));
        $this->info("Transfer {$updated->id} reconciled to {$updated->status}.");

        return self::SUCCESS;
    }
}
