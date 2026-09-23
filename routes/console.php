<?php

use App\Models\IntegrationProvider;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\BankRates\BankRateSyncService;
use App\Services\Nium\NiumDataSyncService;
use App\Services\Nium\NiumFileService;
use App\Services\Nium\NiumQuoteService;
use App\Services\Nium\NiumSafeValueProjector;
use App\Services\Nium\NiumService;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command(
    'nium:smoke-test
    {userId? : User ID used for customer, wallet, sync, or quote checks}
    {--live : Perform the authenticated Get Client request}
    {--client-capabilities : Print only the safe Get Client capability projection}
    {--compliance-callback : Also validate the separate transaction compliance callback configuration}
    {--full : Validate the complete Nium production-readiness endpoint configuration}
    {--sync : Run account, balance, and transaction sync for the user}
    {--quote : Create a test quote for the user}
    {--source-currency=USD : Quote sell currency}
    {--target-currency=EUR : Quote buy currency}
    {--amount=100 : Quote amount in source currency}',
    function (): int {
        $baseUrl = (string) config('services.nium.base_url', '');
        $apiKey = (string) config('services.nium.auth.header_value', '');
        $clientId = (string) config('services.nium.client_id', '');
        $authHeaderName = (string) config('services.nium.auth.header_name', '');
        $webhookHeaderName = (string) config('services.nium.webhook.static_header_name', '');
        $webhookHeaderValue = (string) config('services.nium.webhook.static_header_value', '');
        $appUrl = rtrim((string) config('app.url', ''), '/');

        $this->line('Nium webhook URL: '.$appUrl.'/api/webhooks/providers/nium');

        $configurationErrors = [];
        $baseUrlParts = parse_url($baseUrl);

        if ($baseUrl === '') {
            $configurationErrors[] = 'NIUM_BASE_URL is required.';
        } elseif (
            ! is_array($baseUrlParts)
            || strtolower((string) ($baseUrlParts['scheme'] ?? '')) !== 'https'
            || blank($baseUrlParts['host'] ?? null)
            || isset($baseUrlParts['user'])
            || isset($baseUrlParts['pass'])
            || isset($baseUrlParts['query'])
            || isset($baseUrlParts['fragment'])
        ) {
            $configurationErrors[] = 'NIUM_BASE_URL must be a safe HTTPS origin without credentials, query, or fragment.';
        }

        $isProduction = app()->environment('production')
            || strtolower((string) config('app.env')) === 'production';
        $baseUrlHost = strtolower((string) ($baseUrlParts['host'] ?? ''));

        if ($isProduction && str_contains($baseUrlHost, 'sandbox')) {
            $configurationErrors[] = 'Production must not use a Nium sandbox base URL.';
        }

        if (strtolower((string) config('services.nium.auth.mode', '')) !== 'header') {
            $configurationErrors[] = 'NIUM_AUTH_MODE must be header.';
        }

        if (strtolower(trim($authHeaderName)) !== 'x-api-key') {
            $configurationErrors[] = 'NIUM_AUTH_HEADER_NAME must be x-api-key.';
        }

        if ($apiKey === '') {
            $configurationErrors[] = 'NIUM_API_KEY is required.';
        }

        if ($clientId === '') {
            $configurationErrors[] = 'NIUM_CLIENT_ID is required.';
        }

        if ($this->option('full')) {
            $regulatoryRegion = strtoupper(trim((string) config('services.nium.regulatory_region', '')));

            if ($regulatoryRegion === '') {
                $configurationErrors[] = 'NIUM_REGULATORY_REGION is required for full production readiness.';
            }

            $customerRfiMethod = strtoupper(trim((string) config('services.nium.customer_rfi_response_method', '')));
            $transactionRfiMethod = strtoupper(trim((string) config('services.nium.transaction_rfi_response_method', '')));

            if ($customerRfiMethod !== 'POST') {
                $configurationErrors[] = 'NIUM_CUSTOMER_RFI_RESPONSE_METHOD must be POST.';
            }

            if ($transactionRfiMethod !== 'POST') {
                $configurationErrors[] = 'NIUM_TRANSACTION_RFI_RESPONSE_METHOD must be POST.';
            }
        }

        if (strtolower(trim($webhookHeaderName)) !== 'x-partner-key') {
            $configurationErrors[] = 'NIUM_WEBHOOK_STATIC_HEADER_NAME must be x-partner-key.';
        }

        if ($webhookHeaderValue === '') {
            $configurationErrors[] = 'NIUM_WEBHOOK_STATIC_HEADER_VALUE is required.';
        }

        $endpointRequirements = [
            'NIUM_HEALTH_ENDPOINT' => ['health_endpoint', ['clientHashId']],
            'NIUM_CUSTOMER_CREATE_ENDPOINT' => ['customer_create_endpoint', ['clientHashId']],
            'NIUM_CUSTOMER_GET_ENDPOINT' => ['customer_get_endpoint', ['clientHashId', 'customerHashId']],
            'NIUM_CUSTOMER_LIST_ENDPOINT' => ['customer_list_endpoint', ['clientHashId']],
        ];

        if ($this->option('full')) {
            $endpointRequirements += [
                'NIUM_FILE_CREATE_ENDPOINT' => ['file_create_endpoint', ['clientHashId']],
                'NIUM_FILE_DETAILS_ENDPOINT' => ['file_details_endpoint', ['clientHashId', 'fileId']],
                'NIUM_CUSTOMER_SUBMIT_KYC_ENDPOINT' => ['customer_submit_kyc_endpoint', ['clientHashId', 'customerHashId']],
                'NIUM_WALLET_BALANCE_ENDPOINT' => ['wallet_balance_endpoint', ['clientHashId', 'customerHashId', 'walletHashId']],
                'NIUM_WALLET_TRANSACTIONS_ENDPOINT' => ['wallet_transactions_endpoint', ['clientHashId', 'customerHashId', 'walletHashId']],
                'NIUM_ASSIGN_PAYMENT_ID_ENDPOINT' => ['assign_payment_id_endpoint', ['clientHashId', 'customerHashId', 'walletHashId']],
                'NIUM_PAYOUT_FX_LOCK_ENDPOINT' => ['payout_fx_lock_endpoint', ['clientHashId', 'customerHashId', 'walletHashId']],
                'NIUM_QUOTE_ENDPOINT' => ['quote_endpoint', ['clientHashId']],
                'NIUM_QUOTE_FETCH_ENDPOINT' => ['quote_fetch_endpoint', ['clientHashId', 'quoteId']],
                'NIUM_CONVERSION_ENDPOINT' => ['conversion_endpoint', ['clientHashId', 'customerHashId', 'walletHashId']],
                'NIUM_BENEFICIARY_ENDPOINT' => ['beneficiary_endpoint', ['clientHashId', 'customerHashId']],
                'NIUM_PURPOSE_CODES_ENDPOINT' => ['purpose_codes_endpoint', []],
                'NIUM_SUPPORTED_CORRIDORS_ENDPOINT' => ['supported_corridors_endpoint', ['clientHashId']],
                'NIUM_BENEFICIARY_VALIDATION_SCHEMA_ENDPOINT' => ['beneficiary_validation_schema_endpoint', ['clientHashId', 'customerHashId', 'currencyCode']],
                'NIUM_TRANSFER_ENDPOINT' => ['transfer_endpoint', ['clientHashId', 'customerHashId', 'walletHashId']],
                'NIUM_TRANSFER_STATUS_ENDPOINT' => ['transfer_status_endpoint', ['clientHashId', 'customerHashId', 'walletHashId', 'systemReferenceNumber']],
                'NIUM_CUSTOMER_RFI_FETCH_ENDPOINT' => ['customer_rfi_fetch_endpoint', ['clientHashId']],
                'NIUM_CUSTOMER_RFI_RESPONSE_ENDPOINT' => ['customer_rfi_response_endpoint', ['clientHashId']],
                'NIUM_TRANSACTION_RFI_FETCH_ENDPOINT' => ['transaction_rfi_fetch_endpoint', ['clientHashId', 'customerHashId', 'walletHashId']],
                'NIUM_TRANSACTION_RFI_RESPONSE_ENDPOINT' => ['transaction_rfi_response_endpoint', ['clientHashId', 'customerHashId', 'walletHashId', 'authCode']],
            ];
        }

        foreach ($endpointRequirements as $environmentName => [$configKey, $requiredPlaceholders]) {
            $endpoint = trim((string) config('services.nium.'.$configKey, ''));
            preg_match_all('/\{([^}]+)\}/', $endpoint, $matches);
            $placeholders = array_values(array_unique($matches[1] ?? []));
            sort($placeholders);
            sort($requiredPlaceholders);
            $withoutPlaceholders = preg_replace('/\{[^}]+\}/', '', $endpoint) ?? $endpoint;

            if (
                $endpoint === ''
                || ! str_starts_with($endpoint, '/')
                || str_starts_with($endpoint, '//')
                || preg_match('/[\x00-\x20]/', $endpoint) === 1
                || preg_match('#^https?://#i', $endpoint) === 1
                || str_contains($withoutPlaceholders, '{')
                || str_contains($withoutPlaceholders, '}')
                || $placeholders !== $requiredPlaceholders
            ) {
                $placeholderText = $requiredPlaceholders === []
                    ? 'no placeholders'
                    : 'exactly: '.implode(', ', $requiredPlaceholders);

                $configurationErrors[] = $environmentName.' must be a safe relative path using '.$placeholderText.'.';
            }
        }

        if ($configurationErrors !== []) {
            $this->error('Nium onboarding configuration validation failed.');

            foreach ($configurationErrors as $configurationError) {
                $this->line('- '.$configurationError);
            }

            return Command::FAILURE;
        }

        if ($this->option('client-capabilities') && ! $this->option('live')) {
            $this->error('--client-capabilities requires the explicit --live flag.');

            return Command::FAILURE;
        }

        if ($this->option('client-capabilities') && ($this->option('sync') || $this->option('quote'))) {
            $this->error('--client-capabilities cannot be combined with --sync or --quote.');

            return Command::FAILURE;
        }

        $provider = IntegrationProvider::query()->where('code', 'nium')->first();

        if ($provider === null) {
            $this->error('Nium integration provider is not registered.');

            return Command::FAILURE;
        }

        if (! $provider->isAvailableForOnboarding()) {
            $this->error('Nium onboarding capability is unavailable or the provider configuration is unsafe.');

            return Command::FAILURE;
        }

        if ($this->option('compliance-callback') || $this->option('full')) {
            $complianceHeaderName = (string) config('services.nium.compliance_callback.static_header_name', '');
            $complianceHeaderValue = (string) config('services.nium.compliance_callback.static_header_value', '');

            $this->line('Nium compliance callback URL: '.$appUrl.'/api/callbacks/nium/transaction-compliance');

            if (strtolower(trim($complianceHeaderName)) !== 'x-partner-key' || $complianceHeaderValue === '') {
                $this->error('Nium transaction compliance callback authentication is not configured.');

                return Command::FAILURE;
            }

            $this->info('Nium transaction compliance callback configuration is valid.');
        }

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

        if (! $this->option('live')) {
            if ($this->option('sync') || $this->option('quote')) {
                $this->error('--sync and --quote require the explicit --live flag.');

                return Command::FAILURE;
            }

            $this->info(
                $this->option('full')
                    ? 'Nium full production-readiness configuration validation passed. No outbound request was made.'
                    : 'Nium onboarding configuration validation passed. No outbound request was made.'
            );

            return Command::SUCCESS;
        }

        try {
            $this->info('Checking Nium connectivity...');

            $response = app(NiumService::class)->get(
                path: app(NiumService::class)->path(
                    (string) config('services.nium.health_endpoint'),
                    ['client' => $clientId],
                ),
                user: $user,
            );

            $this->line('Connectivity check status: '.$response->status());

            if (! $response->successful()) {
                $this->error('Nium connectivity check failed.');

                if (! $this->option('client-capabilities')) {
                    $this->line(json_encode(
                        app(SensitiveDataSanitizer::class)->sanitize($response->json() ?? ['raw' => $response->body()]),
                        JSON_PRETTY_PRINT
                    ));
                }

                return Command::FAILURE;
            }

            $this->info('Connectivity OK.');

            if ($this->option('client-capabilities')) {
                $body = $response->json();
                $projection = app(NiumSafeValueProjector::class)->clientCapabilityProjection(
                    is_array($body) ? $body : [],
                );

                $this->line('Client capability projection:');
                $this->line(json_encode($projection, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                $this->info('Nium client capability diagnostic completed.');

                return Command::SUCCESS;
            }

            if ($this->option('sync')) {
                $syncService = app(NiumDataSyncService::class);

                $this->info('Running account sync...');
                $this->line(json_encode($syncService->syncAccounts($provider, $user), JSON_PRETTY_PRINT));

                $this->info('Running balance sync...');
                $this->line(json_encode($syncService->syncBalances($provider, $user), JSON_PRETTY_PRINT));

                $this->info('Running transaction sync...');
                $this->line(json_encode($syncService->syncTransactions($provider, $user), JSON_PRETTY_PRINT));
            }

            if ($this->option('quote')) {
                $quote = app(NiumQuoteService::class)->createQuote($provider, $user, [
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
        } catch (Throwable $exception) {
            $this->error((string) app(SensitiveDataSanitizer::class)->sanitize($exception->getMessage()));

            return Command::FAILURE;
        }

        $this->info('Nium live smoke test completed.');

        return Command::SUCCESS;
    }
)->purpose('Validate Nium Customer Onboarding V5 configuration and optionally perform an explicit live readiness check.');

Artisan::command(
    'nium:file:test
    {kycDocumentId : Existing local KYC document ID}',
    function (): int {
        if (app()->environment('production') || strtolower((string) config('app.env')) === 'production') {
            $this->error('Nium file sandbox testing is disabled in production.');

            return Command::FAILURE;
        }

        $document = KycDocument::query()
            ->with('kycProfile.user')
            ->find($this->argument('kycDocumentId'));

        if ($document === null) {
            $this->error('The requested KYC document was not found.');

            return Command::FAILURE;
        }

        try {
            $result = app(NiumFileService::class)->createFile(
                $document,
                $document->kycProfile?->user,
            );
        } catch (Throwable) {
            $this->error('Nium file sandbox upload failed.');

            return Command::FAILURE;
        }

        $this->line('KYC document ID: '.$document->id);
        $this->line('Nium file ID: '.$result['id']);
        $this->line('State: '.($result['state'] ?? 'UNKNOWN'));

        return Command::SUCCESS;
    }
)->purpose('Upload one existing KYC document to the configured Nium File API outside production.');

Artisan::command(
    'nium:file:refresh
    {kycDocumentId : Existing local KYC document ID with a Nium file ID}',
    function (): int {
        if (app()->environment('production') || strtolower((string) config('app.env')) === 'production') {
            $this->error('Nium file sandbox refresh is disabled in production.');

            return Command::FAILURE;
        }

        $document = KycDocument::query()
            ->with('kycProfile.user')
            ->find($this->argument('kycDocumentId'));

        if ($document === null) {
            $this->error('The requested KYC document was not found.');

            return Command::FAILURE;
        }

        try {
            $result = app(NiumFileService::class)->refreshDocumentState(
                $document,
                $document->kycProfile?->user,
            );
        } catch (Throwable) {
            $this->error('Nium file sandbox refresh failed.');

            return Command::FAILURE;
        }

        $this->line('KYC document ID: '.$document->id);
        $this->line('Nium file ID: '.$result['id']);
        $this->line('State: '.($result['state'] ?? 'UNKNOWN'));

        return Command::SUCCESS;
    }
)->purpose('Refresh one uploaded KYC document from the configured Nium File API outside production.');

Artisan::command(
    'bank-rates:sync
    {--source=* : Limit sync to source keys such as vcb or techcombank}',
    function (): int {
        if (! (bool) config('services.bank_rate_sources.enabled', true)) {
            $this->warn('Bank rate sync is disabled by BANK_RATE_SYNC_ENABLED.');

            return Command::SUCCESS;
        }

        try {
            $sources = $this->option('source');
            $summary = app(BankRateSyncService::class)->sync(is_array($sources) && $sources !== [] ? $sources : null);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        foreach ($summary['sources'] as $source) {
            if (isset($source['error'])) {
                $this->error("{$source['name']} failed: {$source['error']}");

                continue;
            }

            $this->info("{$source['name']}: {$source['rate_count']} rates, {$source['upserted']} rows upserted.");
        }

        $this->line("Total upserted: {$summary['upserted']}");

        return $summary['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
)->purpose('Fetch official Vietnam bank rates and upsert managed exchange rates.');

if ((bool) config('services.bank_rate_sources.enabled', true)) {
    Schedule::command('bank-rates:sync')
        ->everyFiveMinutes()
        ->withoutOverlapping();
}

if ((bool) config('services.nium.transfer_reconciliation_enabled', false)) {
    Schedule::command('nium:reconcile-transfers')
        ->everyFiveMinutes()
        ->withoutOverlapping(10);
}

if ((bool) config('services.nium.wallet_data_sync_enabled', false)) {
    Schedule::command('nium:sync-wallet-data')
        ->everyTenMinutes()
        ->withoutOverlapping(15);
}
