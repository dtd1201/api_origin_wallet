<?php

use App\Services\Nium\NiumBeneficiaryService;
use App\Services\Nium\NiumDataSyncService;
use App\Services\Nium\NiumQuoteService;
use App\Services\Nium\NiumTransferService;
use App\Services\Nium\NiumWebhookService;

return [
    'providers' => [
        'nium' => [
            'beneficiary' => NiumBeneficiaryService::class,
            'quote' => NiumQuoteService::class,
            'transfer' => NiumTransferService::class,
            'data_sync' => NiumDataSyncService::class,
            'webhook' => NiumWebhookService::class,
        ],
    ],
];
