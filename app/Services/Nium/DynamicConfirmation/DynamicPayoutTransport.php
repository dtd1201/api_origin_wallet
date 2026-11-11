<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use App\Models\NiumDynamicIntent;
use App\Models\NiumDynamicPayoutOutbox;

interface DynamicPayoutTransport
{
    /** Return only status and provider reference; implementations must invoke the wire claim immediately before sending. */
    public function submit(NiumDynamicIntent $intent, NiumDynamicPayoutOutbox $outbox, DynamicPayoutWireClaim $claim): array;

    /** Complete, exact-decimal provider records and response hashes, or throw; never default missing fields. */
    public function transactions(NiumDynamicIntent $intent): array;
}
