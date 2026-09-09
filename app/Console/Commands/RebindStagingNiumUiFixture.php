<?php

namespace App\Console\Commands;

use App\Services\Nium\NiumStagingUiFixtureRebindService;
use Illuminate\Console\Command;
use Throwable;

final class RebindStagingNiumUiFixture extends Command
{
    protected $signature = 'nium:rebind-staging-ui-fixture
        {--approve= : Exact human approval marker}
        {--operator= : Required operator and ticket context}';

    protected $description = 'Rebind staging Nium Account 7 to the approved sandbox UI-flow fixture';

    public function handle(NiumStagingUiFixtureRebindService $service): int
    {
        try {
            $account = $service->rebind(
                (string) $this->option('approve'),
                (string) $this->option('operator'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Staging Nium UI fixture rebound successfully for provider Account {$account->id}.");

        return self::SUCCESS;
    }
}
