<?php

return [
    'providers' => [
        'currenxie' => [
            'onboarding' => \App\Services\Currenxie\CurrenxieOnboardingService::class,
            'webhook' => \App\Services\Currenxie\CurrenxieWebhookService::class,
            'data_sync' => \App\Services\Currenxie\CurrenxieDataSyncService::class,
            'quote' => \App\Services\Currenxie\CurrenxieQuoteService::class,
            'transfer' => \App\Services\Currenxie\CurrenxieTransferService::class,
            'beneficiary' => \App\Services\Currenxie\CurrenxieBeneficiaryService::class,
            'payload_mapper' => \App\Services\Currenxie\CurrenxiePayloadMapper::class,
        ],
        'wise' => [
            'onboarding' => \App\Services\Wise\WiseOnboardingService::class,
            // Keep API-driven capabilities disabled until Wise credentials are provided.
            // Available skeleton services:
            // 'webhook' => \App\Services\Wise\WiseWebhookService::class,
            // 'data_sync' => \App\Services\Wise\WiseDataSyncService::class,
            // 'quote' => \App\Services\Wise\WiseQuoteService::class,
            // 'transfer' => \App\Services\Wise\WiseTransferService::class,
            // 'beneficiary' => \App\Services\Wise\WiseBeneficiaryService::class,
        ],
        'airwallex' => [
            'onboarding' => \App\Services\Airwallex\AirwallexOnboardingService::class,
            // Keep API-driven capabilities disabled until Airwallex credentials are provided.
            // Available skeleton services:
            // 'webhook' => \App\Services\Airwallex\AirwallexWebhookService::class,
            // 'data_sync' => \App\Services\Airwallex\AirwallexDataSyncService::class,
            // 'quote' => \App\Services\Airwallex\AirwallexQuoteService::class,
            // 'transfer' => \App\Services\Airwallex\AirwallexTransferService::class,
            // 'beneficiary' => \App\Services\Airwallex\AirwallexBeneficiaryService::class,
        ],
    ],
];
