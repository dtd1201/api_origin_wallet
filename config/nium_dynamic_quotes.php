<?php

return [
    // Customer routes fail closed without authoritative evidence. Neither flag permits dispatch.
    'quote_contract_enabled' => (bool) env('NIUM_DYNAMIC_QUOTE_CONTRACT_ENABLED', false),
    'confirmation_contract_enabled' => (bool) env('NIUM_DYNAMIC_CONFIRMATION_CONTRACT_ENABLED', false),
];
