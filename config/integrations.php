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
    ],
];
