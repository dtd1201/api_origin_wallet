<?php

use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Airwallex\AirwallexDataSyncService;
use App\Services\Airwallex\AirwallexQuoteService;
use App\Services\Airwallex\AirwallexService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command(
    'airwallex:smoke-test
    {userId? : User ID used for on-behalf-of sync or quote checks}
    {--sync : Run account, balance, and transaction sync for the user}
    {--quote : Create a test quote for the user}
    {--source-currency=USD : Quote sell currency}
    {--target-currency=EUR : Quote buy currency}
    {--amount=100 : Quote amount in source currency}',
    function (): int {
        $baseUrl = (string) config('services.airwallex.base_url', '');
        $clientId = (string) config('services.airwallex.auth.client_id', '');
        $apiKey = (string) config('services.airwallex.x_api_key', '');

        if ($baseUrl === '' || $clientId === '' || $apiKey === '') {
            $this->error('Airwallex is not fully configured. Please set AIRWALLEX_BASE_URL, AIRWALLEX_CLIENT_ID, and AIRWALLEX_API_KEY.');

            return Command::FAILURE;
        }

        $provider = IntegrationProvider::query()->firstOrCreate(
            ['code' => 'airwallex'],
            ['name' => 'Airwallex', 'status' => 'active'],
        );

        $userId = $this->argument('userId');
        $user = $userId !== null ? User::query()->with('providerAccounts')->find($userId) : null;

        if ($userId !== null && $user === null) {
            $this->error("User [{$userId}] was not found.");

            return Command::FAILURE;
        }

        if (($this->option('sync') || $this->option('quote')) && $user === null) {
            $this->error('A userId is required when using --sync or --quote.');

            return Command::FAILURE;
        }

        try {
            $this->info('Checking Airwallex connectivity...');

            $response = app(AirwallexService::class)->get(
                path: (string) config('services.airwallex.global_accounts_endpoint'),
                user: $user,
            );

            $this->line('Connectivity check status: '.$response->status());

            if (! $response->successful()) {
                $this->error('Airwallex connectivity check failed.');
                $this->line(json_encode($response->json() ?? ['raw' => $response->body()], JSON_PRETTY_PRINT));

                return Command::FAILURE;
            }

            $payload = $response->json() ?? [];
            $items = $payload['items'] ?? $payload['data']['items'] ?? $payload['data'] ?? [];
            $count = is_array($items) ? count($items) : 0;
            $this->info("Connectivity OK. Global accounts payload items: {$count}");

            if ($this->option('sync')) {
                $syncService = app(AirwallexDataSyncService::class);

                $this->info('Running account sync...');
                $this->line(json_encode($syncService->syncAccounts($provider, $user), JSON_PRETTY_PRINT));

                $this->info('Running balance sync...');
                $this->line(json_encode($syncService->syncBalances($provider, $user), JSON_PRETTY_PRINT));

                $this->info('Running transaction sync...');
                $this->line(json_encode($syncService->syncTransactions($provider, $user), JSON_PRETTY_PRINT));
            }

            if ($this->option('quote')) {
                $quote = app(AirwallexQuoteService::class)->createQuote($provider, $user, [
                    'source_currency' => (string) $this->option('source-currency'),
                    'target_currency' => (string) $this->option('target-currency'),
                    'source_amount' => (float) $this->option('amount'),
                ]);

                $this->info('Quote created successfully.');
                $this->line(json_encode([
                    'id' => $quote->id,
                    'quote_ref' => $quote->quote_ref,
                    'source_currency' => $quote->source_currency,
                    'target_currency' => $quote->target_currency,
                    'source_amount' => $quote->source_amount,
                    'target_amount' => $quote->target_amount,
                    'expires_at' => $quote->expires_at?->toISOString(),
                ], JSON_PRETTY_PRINT));
            }
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $this->info('Airwallex smoke test completed.');

        return Command::SUCCESS;
    }
)->purpose('Verify Airwallex credentials and optionally run sync or quote checks.');
