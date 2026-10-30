<?php

namespace App\Console\Commands;

use App\Models\Transfer;
use App\Models\User;
use App\Services\Nium\NiumPayoutPilotPolicy;
use Illuminate\Console\Command;

final class AuthorizeNiumPayoutPilot extends Command
{
    protected $signature = 'nium:authorize-payout-pilot
        {transfer : Existing locked GREENTECH to Andy draft ID}
        {authorizer : Active independent authorized user ID}
        {authorization_reference : Durable reference to this explicit one-time business authorization}
        {--accept-uncapped-charges : Accept unknown additional NIUM charges without a verified ceiling or fee reserve}';

    protected $description = 'Stage the exact one-time USD 5 authorization with explicit uncapped-charge risk; no provider calls or payment approval';

    public function handle(NiumPayoutPilotPolicy $policy): int
    {
        if (! $this->option('accept-uncapped-charges')) {
            $this->error('Explicit acceptance of unknown, uncapped additional NIUM charges is required.');

            return self::FAILURE;
        }
        $grant = $policy->authorizeOneTime(Transfer::query()->findOrFail($this->argument('transfer')),
            User::query()->findOrFail($this->argument('authorizer')), $this->argument('authorization_reference'), true);
        $this->info('Staged pilot authorization: '.$grant->id);
        $this->warn('Only USD 5 principal will be reserved at dispatch. Additional NIUM charges are unknown and uncapped; no fee reserve is held.');
        $this->info('Activation still requires the disabled-by-default policy and this exact authorization ID, followed by maker review and independent Admin approval.');

        return self::SUCCESS;
    }
}
