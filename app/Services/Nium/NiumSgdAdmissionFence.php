<?php

namespace App\Services\Nium;

use App\Models\Transfer;
use RuntimeException;

/** Uncached, production-scoped admission control, independent of financial policy. */
final class NiumSgdAdmissionFence
{
    public const CLOSED = 'SGD LOCAL admission is paused. Existing payments remain available for reconciliation.';

    // Change Set 1 deliberately has no remittance authorization, even with an open test fence.
    public const DISPATCH_HOLD = 'SGD LOCAL remittance wire contract remains on HOLD. No dispatch is authorized.';

    /** Transport may acquire a quote only for the committed standard-workflow claim. */
    public function assertQuoteRequest(string $method, string $path, array $payload, ?int $transferId, ?string $requestId, int $providerId): void
    {
        $urlPath = parse_url($path, PHP_URL_PATH);
        $transfer = $transferId === null ? null : Transfer::query()->find($transferId);
        $workflow = $transfer?->raw_data['sgd_local_workflow'] ?? [];
        if ($method !== 'POST' || ! is_string($urlPath) || filled(parse_url($path, PHP_URL_QUERY))
            || ! $this->matches(rawurldecode($urlPath), 'quote_endpoint')
            || $transfer === null || $transfer->provider_id !== $providerId || $transfer->status !== 'draft'
            || $transfer->user?->status !== 'active'
            || ($workflow['version'] ?? null) !== 1 || ($workflow['release'] ?? null) !== NiumSgdLocalCugScope::RELEASE
            || ($workflow['state'] ?? null) !== 'locking' || ! is_string($requestId)
            || ! \Illuminate\Support\Str::isUuid($requestId) || ($workflow['lock_request_id'] ?? null) !== $requestId
            || $transfer->fx_quote_id !== null || $transfer->provider_operation_key !== null
            || filled($transfer->external_transfer_id) || filled($transfer->external_payment_id)) {
            throw new RuntimeException('SGD quotes require a claimed saved standard Review instruction.');
        }
        NiumSgdLocalPayoutGate::assertSubmissionAllowed($transfer);
        app(NiumSgdLocalPayoutWorkflow::class)->assertFunding($transfer);
        $context = app(NiumPayoutFxLockService::class)->context($transfer->provider, $transfer->user);
        $expectedPath = app(NiumService::class)->path((string) config('services.nium.quote_endpoint'), ['client' => $context['client']]);
        if (rawurldecode($urlPath) !== rawurldecode($expectedPath)
            || ! NiumPayoutFxLockContract::sameBinding($payload,
                NiumPayoutQuoteContract::request($context['customer'], 'USD', 'SGD', $transfer->source_amount))) {
            throw new RuntimeException('The SGD quote request does not match its saved instruction.');
        }
    }

    public function acquire(): NiumSgdAdmissionLease
    {
        $directory = $this->directory();
        $path = $directory.'/drain.lock';
        clearstatcache(true, $path);
        $handle = ! is_link($path) ? @fopen($path, 'rb') : false;
        if ($handle === false) {
            throw new RuntimeException(self::CLOSED);
        }
        // Never wait while holding a transfer/wallet transaction lock.
        if (! flock($handle, LOCK_SH | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException(self::CLOSED);
        }
        $lease = new NiumSgdAdmissionLease($handle);
        $opened = fstat($handle);
        $current = @lstat($path);
        if ($current === false || $opened['ino'] !== $current['ino'] || $opened['dev'] !== $current['dev']
            || ($opened['mode'] & 0170000) !== 0100000) {
            throw new RuntimeException(self::CLOSED);
        }
        $this->assertOpen();

        return $lease;
    }

    public function assertOpen(): void
    {
        $path = $this->directory().'/state';
        clearstatcache(true, $path);
        if (is_link($path) || ! is_file($path) || @file_get_contents($path) !== "OPEN v1\n") {
            throw new RuntimeException(self::CLOSED);
        }
    }

    private function directory(): string
    {
        $path = config('sgd_admission.directory');
        if (! is_string($path) || ! str_starts_with($path, '/') || str_contains($path, "\0")
            || ! is_dir($path) || is_link($path) || realpath($path) !== $path) {
            throw new RuntimeException(self::CLOSED);
        }

        return $path;
    }

    /** Identify SGD FX/remittance even when a low-level caller omits its operation label. */
    public function wireKind(string $method, string $path, array $payload, ?int $transferId): ?string
    {
        $urlPath = parse_url($path, PHP_URL_PATH);
        if (! is_string($urlPath)) {
            throw new RuntimeException('Invalid NIUM request path.');
        }
        $urlPath = rawurldecode($urlPath);
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $isRemittance = $this->matches($urlPath, 'transfer_endpoint') || preg_match('~/remittance/?$~i', $urlPath);
        $isQuote = $this->matches($urlPath, 'quote_endpoint') || $this->matches($urlPath, 'payout_fx_lock_endpoint')
            || preg_match('~/(?:quotes|lockExchangeRate)/?$~i', $urlPath);
        $destinations = [];
        foreach ([$payload, $query] as $values) {
            foreach ([$values['destinationCurrencyCode'] ?? null, $values['destinationCurrency'] ?? null,
                $values['payout']['destinationCurrency'] ?? null] as $value) {
                if ($value !== null) {
                    $destinations[] = is_string($value) ? strtoupper(trim($value)) : 'INVALID';
                }
            }
        }
        $hasFx = isset($payload['payout']['quoteId']) || isset($payload['payout']['auditId']);
        $boundSgd = ($isRemittance || $isQuote) && $transferId !== null && Transfer::query()->whereKey($transferId)
            ->where('target_currency', 'SGD')->whereHas('provider', fn ($q) => $q->where('code', 'nium'))->exists();
        $explicitHk = ($payload['payout']['sourceCurrency'] ?? null) === 'USD'
            && ($payload['payout']['destinationCurrency'] ?? null) === 'USD'
            && ($payload['payout']['payoutMethod'] ?? null) === 'SWIFT' && ! $hasFx;
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true) && $isRemittance
            && ($boundSgd || ! $explicitHk || in_array('SGD', $destinations, true))) {
            return 'remittance';
        }
        if ($isQuote && ($boundSgd || $destinations === [] || in_array('SGD', $destinations, true)
            || in_array('INVALID', $destinations, true))) {
            return 'quote'; // Legacy lockExchangeRate is a side-effecting GET.
        }

        return null;
    }

    private function matches(string $path, string $key): bool
    {
        $template = parse_url((string) config('services.nium.'.$key), PHP_URL_PATH);
        if (! is_string($template) || $template === '') {
            return false;
        }
        $parts = preg_split('/(\{[A-Za-z][A-Za-z0-9_]*\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
        $pattern = implode('', array_map(fn ($part) => str_starts_with($part, '{') ? '[^/]+' : preg_quote($part, '~'), $parts));

        return preg_match('~^'.$pattern.'/?$~D', $path) === 1;
    }
}
