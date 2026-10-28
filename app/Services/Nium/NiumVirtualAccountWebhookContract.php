<?php

namespace App\Services\Nium;

use App\Models\IntegrationProvider;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/** Inbound VA contracts. Delivery identity is never assignment causality. */
final class NiumVirtualAccountWebhookContract
{
    public const ASSIGNED = 'VIRTUAL_ACCOUNT_ASSIGNED_WEBHOOK';

    public const FAILED = 'VIRTUAL_ACCOUNT_ASSIGNMENT_FAILED_WEBHOOK';

    public static function isAssigned(array $payload): bool
    {
        return in_array(self::type($payload), ['VA_ASSIGNED', 'VIRTUAL_ACCOUNT_ASSIGNED', self::ASSIGNED], true);
    }

    public static function isAssignmentEvent(array $payload): bool
    {
        return self::isAssigned($payload) || self::type($payload) === self::FAILED;
    }

    public static function type(array $payload): string
    {
        return strtoupper((string) ($payload['template'] ?? $payload['eventType'] ?? ''));
    }

    public static function validateDeliveryIdentity(array $payload): void
    {
        $ids = [];
        foreach (['id', 'eventId', 'event_id', 'webhookEventId', 'webhook_event_id'] as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }
            $id = $payload[$field];
            if (! is_string($id) || $id === '' || strlen($id) > 255 || preg_match('/[\x00-\x20]/', $id)) {
                throw new RuntimeException('Invalid VA webhook delivery identity.');
            }
            $ids[$id] = true;
        }
        if (count($ids) > 1) {
            throw new RuntimeException('Conflicting VA webhook delivery identities.');
        }
        // The documented bodies have no event ID. Existing ingress uses its authenticated
        // delivery header or a body fingerprint for deduplication, never as a POST request key.
    }

    /** Called only after the existing authenticated ingress has verified x-partner-key. */
    public static function capture(IntegrationProvider $provider, Request $request, string $eventId, string $eventType): array
    {
        if ($eventId === '' || strlen($eventId) > 255 || preg_match('/[\x00-\x20]/', $eventId)) {
            throw new RuntimeException('Invalid VA webhook delivery identity.');
        }
        try {
            $origin = NiumVirtualAccountRequestIdentity::origin((string) config('services.nium.base_url'));
        } catch (Throwable) {
            // Legacy ingress did not require outbound configuration. Such receipts cannot resolve a ledger.
            $origin = null;
        }
        $raw = $request->getContent();

        return [
            'capture_version' => 1, 'authentication' => 'verified_x_partner_key',
            'provider_id' => (int) $provider->id, 'provider_origin' => $origin,
            'configured_client_id' => config('services.nium.client_id'),
            'event_id' => $eventId, 'event_type' => $eventType,
            'raw_body' => $raw, 'body_sha256' => hash('sha256', $raw),
        ];
    }

    public static function payload(WebhookEvent $event): array
    {
        $proof = $event->nium_va_evidence;
        if (! is_array($proof) || ($proof['capture_version'] ?? null) !== 1
            || ($proof['authentication'] ?? null) !== 'verified_x_partner_key'
            || ($proof['provider_id'] ?? null) !== (int) $event->provider_id
            || ($proof['event_id'] ?? null) !== $event->event_id
            || ($proof['event_type'] ?? null) !== $event->event_type
            || ! is_string($proof['raw_body'] ?? null) || ! is_string($proof['body_sha256'] ?? null)
            || ! hash_equals($proof['body_sha256'], hash('sha256', $proof['raw_body']))) {
            throw new RuntimeException('VA webhook has no verified immutable receipt evidence.');
        }
        $payload = json_decode($proof['raw_body'], true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload) || array_is_list($payload) || ! self::isAssignmentEvent($payload)
            || self::type($payload) !== strtoupper($event->event_type)) {
            throw new RuntimeException('Unexpected VA webhook evidence schema.');
        }

        return $payload;
    }

    public static function matchesTarget(WebhookEvent $event, NiumVirtualAccountRequestIdentity $identity): bool
    {
        $payload = self::payload($event);
        $proof = $event->nium_va_evidence;
        $snapshot = $identity->snapshot();
        if ($proof['provider_id'] !== $snapshot['provider_id'] || $proof['provider_origin'] !== $snapshot['provider_origin']
            || $proof['configured_client_id'] !== $snapshot['client_id']) {
            return false;
        }
        foreach (['clientHashId' => 'client_id', 'customerHashId' => 'customer_id', 'walletHashId' => 'wallet_id'] as $field => $key) {
            if (! is_string($payload[$field] ?? null) || $payload[$field] !== $snapshot[$key]) {
                return false;
            }
        }

        return true;
    }

    /** Receipts are immutable; previously observed contradictions must survive a later poll. */
    public static function observedReceiptsPermitAssignment(NiumVirtualAccountRequestIdentity $identity, string $paymentId): bool
    {
        $events = WebhookEvent::query()->where('provider_id', $identity->snapshot()['provider_id'])
            ->whereNotNull('nium_va_evidence')
            ->whereRaw('UPPER(event_type) IN (?, ?, ?)', ['VA_ASSIGNED', 'VIRTUAL_ACCOUNT_ASSIGNED', self::ASSIGNED])->get();
        foreach ($events as $event) {
            $payload = self::payload($event);
            if (($payload['uniquePaymentId'] ?? null) === $paymentId && self::matchesTarget($event, $identity)
                && ! self::permitsAssignment($event, $identity, $paymentId)) {
                return false;
            }
        }

        return true;
    }

    public static function permitsAssignment(WebhookEvent $event, NiumVirtualAccountRequestIdentity $identity, string $paymentId): bool
    {
        $payload = self::payload($event);
        if (! self::matchesTarget($event, $identity) || ($payload['template'] ?? null) !== self::ASSIGNED
            || ! NiumPaymentIdValidator::isValid($payload['uniquePaymentId'] ?? null)
            || $payload['uniquePaymentId'] !== $paymentId || ($payload['currencyCode'] ?? null) !== $identity->currency()
            || array_key_exists('errors', $payload)
            || (array_key_exists('eventType', $payload) && $payload['eventType'] !== self::ASSIGNED)) {
            return false;
        }
        // The documented webhook enum LOCAL/GLOBAL/LOCAL+GLOBAL has no verified mapping
        // to POST funding rails (including WIRES). Bank names are display names, not
        // documented POST bank codes. Category is not documented on these webhooks.
        // Even equal spellings cannot invent the missing equivalence contract.
        foreach (['accountType', 'accountCategory', 'bankName', 'fullBankName'] as $unmapped) {
            if (array_key_exists($unmapped, $payload)) {
                return false;
            }
        }

        // Apply shared validation for explicit currency aliases and identity dimensions.
        return NiumVirtualAccountAssignmentResponse::matchesTarget($payload, $identity);
    }
}
