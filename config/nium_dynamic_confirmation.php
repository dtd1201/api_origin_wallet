<?php

return [
    'enabled' => (bool) env('NIUM_DYNAMIC_DURABLE_CONFIRMATION_ENABLED', false),
    // Off by default; activation also requires current authoritative evidence and the live operator gate.
    'automatic_dispatch_enabled' => (bool) env('NIUM_DYNAMIC_AUTOMATIC_DISPATCH_ENABLED', false),
];
