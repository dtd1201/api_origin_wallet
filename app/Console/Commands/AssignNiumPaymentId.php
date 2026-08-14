<?php

namespace App\Console\Commands;

use App\Models\UserProviderAccount;
use App\Services\Nium\NiumPaymentIdService;
use Illuminate\Console\Command;

class AssignNiumPaymentId extends Command
{
    protected $signature = 'nium:assign-payment-id {account} {currencyCode} {--account-category=} {--bank-name=}';

    protected $description = 'Assign a Nium virtual account payment ID to an eligible customer wallet';

    public function handle(NiumPaymentIdService $service): int
    {
        $account = UserProviderAccount::query()->findOrFail((int) $this->argument('account'));
        if ((int) $account->getKey() === 7) {
            $this->error('Account 7 requires the dedicated human-approved Nium payment ID one-shot path.');

            return self::FAILURE;
        }
        if (! filled($this->option('account-category')) || ! filled($this->option('bank-name'))) {
            $this->error('Assign Payment ID V1 requires explicit --account-category and --bank-name values.');

            return self::FAILURE;
        }
        $virtualAccount = $service->assign(
            $account,
            (string) $this->argument('currencyCode'),
            (string) $this->option('account-category'),
            (string) $this->option('bank-name'),
        );
        $this->info("Assigned Nium payment ID {$virtualAccount->provider_payment_id} for {$virtualAccount->currency}.");

        return self::SUCCESS;
    }
}
