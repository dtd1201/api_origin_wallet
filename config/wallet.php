<?php

return [
    'ledger' => [
        'enabled' => env('WALLET_LEDGER_ENABLED', true),
        'require_synced_balance' => env('WALLET_REQUIRE_SYNCED_BALANCE', true),
    ],

    'transfer_controls' => [
        'require_admin_approval' => env('TRANSFER_REQUIRE_ADMIN_APPROVAL', true),
        'approval_threshold_amount' => env('TRANSFER_APPROVAL_THRESHOLD_AMOUNT', 0),
        'allowed_provider_account_statuses' => array_filter(
            array_map('trim', explode(',', env('TRANSFER_ALLOWED_PROVIDER_ACCOUNT_STATUSES', 'active')))
        ),
    ],
];
