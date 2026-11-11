<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\NiumDynamicIntent;
use App\Models\NiumDynamicPayoutOutbox;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutTransport;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutWireClaim;
use Closure;

/** No HTTP. Rows and financial classifications here are synthetic, never provider verification. */
final class DynamicPayoutTransportStub implements DynamicPayoutTransport
{
    public int $submissions = 0;
    public int $reads = 0;
    public array $bodies = [];
    public array $batch = [];
    public ?Closure $beforeWire = null;
    public ?Closure $afterWire = null;
    public ?Closure $duringRead = null;
    public ?string $wireLog = null;

    public function submit(NiumDynamicIntent $intent, NiumDynamicPayoutOutbox $outbox, DynamicPayoutWireClaim $claim): array
    {
        if ($this->beforeWire !== null) {
            ($this->beforeWire)();
        }
        $client = $intent->confirmed_terms['context']['corridor']['client'];
        $path = '/api/v1/client/'.$client.'/customer/'.$intent->customer.'/wallet/'.$intent->source_wallet.'/remittance';
        $claim->authorize('POST', $path, $outbox->request_json, ['x-idempotency-id' => $outbox->operation_key], true);
        $this->submissions++;
        $this->bodies[] = $outbox->request_json;
        if ($this->wireLog !== null) {
            file_put_contents($this->wireLog, $outbox->operation_key."\n", FILE_APPEND | LOCK_EX);
        }
        if ($this->afterWire !== null) {
            ($this->afterWire)();
        }

        return ['http_status' => 200, 'provider_reference' => 'RT-fixture-dynamic'];
    }

    public function transactions(NiumDynamicIntent $intent): array
    {
        $this->reads++;
        if ($this->duringRead !== null) {
            ($this->duringRead)();
        }

        return $this->batch;
    }
}
