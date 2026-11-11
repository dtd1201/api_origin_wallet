<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Nium\NiumRejectedPayoutQuoteRecovery;
use Illuminate\Console\Command;
use Throwable;

final class RecoverRejectedNiumSgdQuote extends Command
{
    protected $signature = 'nium:recover-rejected-sgd-quote
        {operator : Active independent admin user ID responsible for evidence review}
        {--clearance= : Private JSON clearance file referencing verified provider evidence}
        {--provider-evidence= : Private independently reviewed authoritative provider artifact}
        {--apply : Record the local recovery; otherwise validate without changing the transfer}';

    protected $description = 'Validate/recover only incident Transfer 5 audit 4763; no provider calls, quote, approval or remittance';

    public function handle(NiumRejectedPayoutQuoteRecovery $recovery): int
    {
        try {
            $path = $this->option('clearance');
            if (! is_string($path) || is_link($path) || ! is_file($path)
                || (fileperms($path) & 0007) !== 0 || filesize($path) > 16384) {
                throw new \RuntimeException;
            }
            $clearance = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $artifact = $this->option('provider-evidence');
            if (! is_string($artifact) || is_link($artifact) || ! is_file($artifact)
                || (fileperms($artifact) & 0007) !== 0 || filesize($artifact) > 16777216) {
                throw new \RuntimeException;
            }
            $transfer = $recovery->recover(User::query()->findOrFail($this->argument('operator')), $clearance, file_get_contents($artifact),
                (bool) $this->option('apply'));
            $recorded = isset($transfer->raw_data['sgd_local_workflow']['rejected_quote_recoveries'][NiumRejectedPayoutQuoteRecovery::REQUEST]);
            $this->info($recorded ? 'PASS local recovery recorded; no provider operation performed.'
                : 'PASS evidence validation only; transfer unchanged.');

            return self::SUCCESS;
        } catch (Throwable) {
            // Never echo decrypted captures, provider identity, paths or SQL exceptions.
            $this->error('FAIL recovery refused; keep admission closed and review incident evidence.');

            return self::FAILURE;
        }
    }
}
