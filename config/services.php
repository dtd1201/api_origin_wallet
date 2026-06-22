<?php

return [
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],

    'public_provider_rates' => [
        'cache_ttl_seconds' => env('PUBLIC_PROVIDER_RATES_CACHE_TTL_SECONDS', 15),
        'market_cache_ttl_seconds' => env('PUBLIC_PROVIDER_MARKET_RATE_CACHE_TTL_SECONDS', 3600),
        'market_base_url' => env('PUBLIC_PROVIDER_MARKET_RATE_BASE_URL', 'https://open.er-api.com/v6/latest'),
    ],

    'provider_catalog' => [
        'cache_ttl_seconds' => env('PROVIDER_CATALOG_CACHE_TTL_SECONDS', 60),
    ],

    'identity_verification' => [
        'provider' => env('IDENTITY_VERIFICATION_PROVIDER', 'origin_capture'),
        'session_ttl_minutes' => env('IDENTITY_VERIFICATION_SESSION_TTL_MINUTES', 60),
        'evidence_disk' => env('IDENTITY_VERIFICATION_EVIDENCE_DISK', 'kyc_private'),
    ],

    'business_registry' => [
        'timeout' => env('BUSINESS_REGISTRY_TIMEOUT', 12),
        'eu_vies' => [
            'endpoint' => env('EU_VIES_ENDPOINT', 'https://ec.europa.eu/taxation_customs/vies/services/checkVatService'),
            'countries' => [
                'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'EL',
                'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK',
                'SI', 'ES', 'SE',
            ],
        ],
        'au' => [
            'guid' => env('ABN_LOOKUP_GUID'),
            'json_url' => env('ABN_LOOKUP_JSON_URL', 'https://abr.business.gov.au/json'),
        ],
        'sg' => [
            'datastore_url' => env('DATA_GOV_SG_DATASTORE_URL', 'https://data.gov.sg/api/action/datastore_search'),
            'dataset_ids' => array_filter([
                'A' => env('ACRA_DATASET_A_ID'),
                'B' => env('ACRA_DATASET_B_ID'),
                'C' => env('ACRA_DATASET_C_ID'),
                'D' => env('ACRA_DATASET_D_ID'),
                'E' => env('ACRA_DATASET_E_ID'),
                'F' => env('ACRA_DATASET_F_ID'),
                'G' => env('ACRA_DATASET_G_ID'),
                'H' => env('ACRA_DATASET_H_ID'),
                'I' => env('ACRA_DATASET_I_ID'),
                'J' => env('ACRA_DATASET_J_ID'),
                'K' => env('ACRA_DATASET_K_ID'),
                'L' => env('ACRA_DATASET_L_ID'),
                'M' => env('ACRA_DATASET_M_ID'),
                'N' => env('ACRA_DATASET_N_ID'),
                'O' => env('ACRA_DATASET_O_ID'),
                'P' => env('ACRA_DATASET_P_ID'),
                'Q' => env('ACRA_DATASET_Q_ID'),
                'R' => env('ACRA_DATASET_R_ID'),
                'S' => env('ACRA_DATASET_S_ID'),
                'T' => env('ACRA_DATASET_T_ID'),
                'U' => env('ACRA_DATASET_U_ID'),
                'V' => env('ACRA_DATASET_V_ID'),
                'W' => env('ACRA_DATASET_W_ID'),
                'X' => env('ACRA_DATASET_X_ID'),
                'Y' => env('ACRA_DATASET_Y_ID'),
                'Z' => env('ACRA_DATASET_Z_ID'),
                'others' => env('ACRA_DATASET_OTHERS_ID'),
            ]),
        ],
    ],

    'nium' => [
        'base_url' => env('NIUM_BASE_URL'),
        'timeout' => env('NIUM_TIMEOUT', 30),
        'client_id' => env('NIUM_CLIENT_ID'),
        'auth' => [
            'mode' => env('NIUM_AUTH_MODE', 'header'),
            'header_name' => env('NIUM_AUTH_HEADER_NAME', 'x-api-key'),
            'header_value' => env('NIUM_API_KEY'),
        ],
        'health_endpoint' => env('NIUM_HEALTH_ENDPOINT', '/api/v1/client/{clientHashId}'),
        'customer_endpoint' => env('NIUM_CUSTOMER_ENDPOINT'),
        'wallet_balance_endpoint' => env('NIUM_WALLET_BALANCE_ENDPOINT', '/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}'),
        'wallet_transactions_endpoint' => env('NIUM_WALLET_TRANSACTIONS_ENDPOINT', '/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/transactions'),
        'transaction_sync_days' => env('NIUM_TRANSACTION_SYNC_DAYS', 30),
        'quote_endpoint' => env('NIUM_QUOTE_ENDPOINT', '/api/v1/client/{clientHashId}/quotes'),
        'beneficiary_endpoint' => env('NIUM_BENEFICIARY_ENDPOINT', '/api/v2/client/{clientHashId}/customer/{customerHashId}/beneficiaries'),
        'beneficiary_update_endpoint' => env('NIUM_BENEFICIARY_UPDATE_ENDPOINT', '/api/v2/client/{clientHashId}/customer/{customerHashId}/beneficiaries/{beneficiaryHashId}'),
        'beneficiary_delete_endpoint' => env('NIUM_BENEFICIARY_DELETE_ENDPOINT', '/api/v1/client/{clientHashId}/customer/{customerHashId}/beneficiaries/{beneficiaryHashId}'),
        'account_verification_endpoint' => env('NIUM_ACCOUNT_VERIFICATION_ENDPOINT', '/api/v1/client/{clientHashId}/customer/{customerHashId}/accountVerification'),
        'transfer_endpoint' => env('NIUM_TRANSFER_ENDPOINT', '/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/remittance'),
        'transfer_status_endpoint' => env('NIUM_TRANSFER_STATUS_ENDPOINT', '/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/remittance/{systemReferenceNumber}/audit'),
        'webhook_secret' => env('NIUM_WEBHOOK_SECRET'),
        'webhook_signature_header' => env('NIUM_WEBHOOK_SIGNATURE_HEADER'),
        'webhook_signature_algorithm' => env('NIUM_WEBHOOK_SIGNATURE_ALGORITHM', 'sha256'),
    ],

    'managed_exchange_rates' => [
        'refresh_interval_seconds' => env('MANAGED_EXCHANGE_RATE_REFRESH_INTERVAL_SECONDS', 300),
    ],

    'bank_rate_sources' => [
        'enabled' => env('BANK_RATE_SYNC_ENABLED', true),
        'timeout' => env('BANK_RATE_SYNC_TIMEOUT', 20),
        'audiences' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('BANK_RATE_SYNC_AUDIENCES', 'public,authenticated'))
        ))),
        'sources' => [
            'vcb' => [
                'enabled' => env('BANK_RATE_VCB_ENABLED', true),
                'code' => 'vcb',
                'name' => 'Vietcombank',
                'url' => env('BANK_RATE_VCB_URL', 'https://portal.vietcombank.com.vn/Usercontrols/TVPortal.TyGia/pXML.aspx'),
                'accept' => 'application/xml,text/xml,*/*',
                'parser' => 'vietcombank_xml',
                'display_order' => 10,
            ],
            'techcombank' => [
                'enabled' => env('BANK_RATE_TECHCOMBANK_ENABLED', true),
                'code' => 'techcombank',
                'name' => 'Techcombank',
                'url' => env('BANK_RATE_TECHCOMBANK_URL', 'https://techcombank.com/content/techcombank/web/vn/en/cong-cu-tien-ich/ty-gia/_jcr_content.exchange-rates.integration.json'),
                'accept' => 'application/json,*/*',
                'parser' => 'techcombank_json',
                'display_order' => 40,
            ],
        ],
    ],
];
