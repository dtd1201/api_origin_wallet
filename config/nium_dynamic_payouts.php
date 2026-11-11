<?php

return [
    // Independent of live HK SWIFT and its configuration. No flag creates financial evidence.
    'reconciliation_enabled' => (bool) env('NIUM_DYNAMIC_RECONCILIATION_ENABLED', false),
    'live_automatic_dispatch_authorized' => (bool) env('NIUM_DYNAMIC_LIVE_DISPATCH_AUTHORIZED', false),
    'operator_authorization_reference' => env('NIUM_DYNAMIC_OPERATOR_AUTHORIZATION_REFERENCE'),
    'max_reconciliation_attempts' => 8,
    'reconciliation_delays_seconds' => [60, 120, 300, 600, 1800, 3600, 7200, 14400],
    'max_transaction_pages' => 20,
    'claim_timeout_seconds' => 120,
    // Read-only recovery after wallet accounting matched. No new dispatch permission.
    'post_match_reconciliation_delays_seconds' => [3600, 21600, 86400, 259200],
    'post_match_reconciliation_window_seconds' => 604800,
];
