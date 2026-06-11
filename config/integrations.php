<?php

use App\Services\Airwallex\AirwallexBeneficiaryService;
use App\Services\Airwallex\AirwallexDataSyncService;
use App\Services\Airwallex\AirwallexOnboardingService;
use App\Services\Airwallex\AirwallexQuoteService;
use App\Services\Airwallex\AirwallexTransferService;
use App\Services\Airwallex\AirwallexWebhookService;
use App\Services\Currenxie\CurrenxieBeneficiaryService;
use App\Services\Currenxie\CurrenxieDataSyncService;
use App\Services\Currenxie\CurrenxieOnboardingService;
use App\Services\Currenxie\CurrenxiePayloadMapper;
use App\Services\Currenxie\CurrenxieQuoteService;
use App\Services\Currenxie\CurrenxieTransferService;
use App\Services\Currenxie\CurrenxieWebhookService;
use App\Services\Nium\NiumBeneficiaryService;
use App\Services\Nium\NiumDataSyncService;
use App\Services\Nium\NiumQuoteService;
use App\Services\Nium\NiumTransferService;
use App\Services\Nium\NiumWebhookService;
use App\Services\PingPong\PingPongBeneficiaryService;
use App\Services\PingPong\PingPongTransferService;
use App\Services\Tazapay\TazapayBeneficiaryService;
use App\Services\Tazapay\TazapayDataSyncService;
use App\Services\Tazapay\TazapayQuoteService;
use App\Services\Tazapay\TazapayTransferService;
use App\Services\Tazapay\TazapayWebhookService;
use App\Services\Unlimit\UnlimitBeneficiaryService;
use App\Services\Unlimit\UnlimitOnboardingService;
use App\Services\Unlimit\UnlimitTransferService;
use App\Services\Unlimit\UnlimitWebhookService;
use App\Services\Wise\WiseBeneficiaryService;
use App\Services\Wise\WiseDataSyncService;
use App\Services\Wise\WiseOnboardingService;
use App\Services\Wise\WiseQuoteService;
use App\Services\Wise\WiseTransferService;
use App\Services\Wise\WiseWebhookService;

return [
    'providers' => [
        'currenxie' => [
            'onboarding' => CurrenxieOnboardingService::class,
            'webhook' => CurrenxieWebhookService::class,
            'data_sync' => CurrenxieDataSyncService::class,
            'quote' => CurrenxieQuoteService::class,
            'transfer' => CurrenxieTransferService::class,
            'beneficiary' => CurrenxieBeneficiaryService::class,
            'payload_mapper' => CurrenxiePayloadMapper::class,
        ],
        'wise' => [
            'onboarding' => WiseOnboardingService::class,
            'webhook' => WiseWebhookService::class,
            'data_sync' => WiseDataSyncService::class,
            'quote' => WiseQuoteService::class,
            'transfer' => WiseTransferService::class,
            'beneficiary' => WiseBeneficiaryService::class,
        ],
        'airwallex' => [
            'onboarding' => AirwallexOnboardingService::class,
            'webhook' => AirwallexWebhookService::class,
            'data_sync' => AirwallexDataSyncService::class,
            'quote' => AirwallexQuoteService::class,
            'transfer' => AirwallexTransferService::class,
            'beneficiary' => AirwallexBeneficiaryService::class,
        ],
        'pingpong' => [
            'beneficiary' => PingPongBeneficiaryService::class,
            'transfer' => PingPongTransferService::class,
        ],
        'tazapay' => [
            'beneficiary' => TazapayBeneficiaryService::class,
            'quote' => TazapayQuoteService::class,
            'transfer' => TazapayTransferService::class,
            'data_sync' => TazapayDataSyncService::class,
            'webhook' => TazapayWebhookService::class,
        ],
        'unlimit' => [
            'onboarding' => UnlimitOnboardingService::class,
            'beneficiary' => UnlimitBeneficiaryService::class,
            'transfer' => UnlimitTransferService::class,
            'webhook' => UnlimitWebhookService::class,
        ],
        'nium' => [
            'beneficiary' => NiumBeneficiaryService::class,
            'quote' => NiumQuoteService::class,
            'transfer' => NiumTransferService::class,
            'data_sync' => NiumDataSyncService::class,
            'webhook' => NiumWebhookService::class,
        ],
    ],
];
