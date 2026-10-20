<?php

namespace App\Console\Commands;

use App\Models\UserProviderAccount;
use App\Services\Nium\NiumPaymentIdService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AssignNiumPaymentId extends Command
{
    protected $signature = 'nium:assign-payment-id {account} {currency} {--account-category=SELF_FUNDING_ACCOUNT} {--account-type=LOCAL} {--bank-name=} {--operation-id=}';

    protected $description = 'Assign a Nium virtual account payment ID to an eligible customer wallet';

    public function handle(NiumPaymentIdService $service): int
    {
        $account = UserProviderAccount::query()->findOrFail((int) $this->argument('account'));
        $operationId = filled($this->option('operation-id'))
            ? (string) $this->option('operation-id')
            : 'artisan-'.Str::uuid()->toString();
        $this->line("Operation ID: {$operationId}");
        $virtualAccount = $service->assign(
            $account,
            (string) $this->argument('currency'),
            (string) $this->option('account-category'),
            (string) $this->option('account-type'),
            filled($this->option('bank-name')) ? (string) $this->option('bank-name') : null,
            $operationId,
        );
        $this->info("Assigned Nium payment ID {$virtualAccount->provider_payment_id} for {$virtualAccount->currency}.");

        return self::SUCCESS;
    }
}
