<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use App\Models\NiumDynamicIntent;
use App\Models\NiumDynamicPayoutOutbox;
use RuntimeException;

/** A token alone grants nothing: persisted claim, exact wire bytes and fresh evidence are checked. */
final readonly class DynamicPayoutWireClaim
{
    public function __construct(private DynamicPayoutDispatcher $dispatcher, public string $outboxId, public string $token) {}

    public function authorize(string $method, string $url, string $body, array $headers, bool $begin): void
    {
        DynamicPayoutGate::assertDispatchEnabled();
        $outbox = NiumDynamicPayoutOutbox::query()->findOrFail($this->outboxId);
        $intent = NiumDynamicIntent::query()->findOrFail($outbox->intent_id);
        $client = $intent->confirmed_terms['context']['corridor']['client'];
        $path = '/api/v1/client/'.rawurlencode($client).'/customer/'.rawurlencode($intent->customer)
            .'/wallet/'.rawurlencode($intent->source_wallet).'/remittance';
        $header = null;
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'x-idempotency-id') {
                $header = is_array($value) ? ($value[0] ?? null) : $value;
            }
        }
        $expected = rtrim((string) config('services.nium.base_url'), '/').$path;
        if ($method !== 'POST' || ($url !== $path && $url !== $expected)
            || ! is_string($outbox->claim_token) || ! hash_equals($outbox->claim_token, $this->token)
            || $header !== $outbox->operation_key || $outbox->state !== 'claimed' || $outbox->attempts !== 0
            || ! hash_equals($outbox->request_sha256, hash('sha256', $body))
            || $outbox->request_json !== $body || DynamicPayoutRequest::json($intent, $outbox->operation_key) !== $body) {
            throw new RuntimeException('dynamic_payout_wire_claim_invalid');
        }
        if ($begin) {
            $this->dispatcher->beginWire($this->outboxId, $this->token);
        }
    }
}
