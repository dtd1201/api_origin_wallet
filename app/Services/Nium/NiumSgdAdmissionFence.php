<?php

namespace App\Services\Nium;

use App\Models\Transfer;
use RuntimeException;

/** Uncached, production-scoped admission control, independent of financial policy. */
final class NiumSgdAdmissionFence
{
    public const CLOSED = 'SGD LOCAL admission is paused. Existing payments remain available for reconciliation.';

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

    public function acquireFor(Transfer $transfer): ?NiumSgdAdmissionLease
    {
        $transfer->loadMissing('provider');

        return strtolower(trim((string) $transfer->provider?->code)) === 'nium'
            && strtoupper(trim((string) $transfer->target_currency)) === 'SGD' ? $this->acquire() : null;
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
        $isRemittance = $this->matches($urlPath, 'transfer_endpoint') || preg_match('~/remittance/?$~i', $urlPath);
        $isQuote = $this->matches($urlPath, 'quote_endpoint') || $this->matches($urlPath, 'payout_fx_lock_endpoint');
        $currency = strtoupper(trim((string) ($payload['destinationCurrencyCode'] ?? $payload['destinationCurrency']
            ?? $payload['payout']['destinationCurrency'] ?? '')));
        $hasFx = isset($payload['payout']['quoteId']) || isset($payload['payout']['auditId']);
        $boundSgd = $isRemittance && $transferId !== null && Transfer::query()->whereKey($transferId)
            ->where('target_currency', 'SGD')->whereHas('provider', fn ($q) => $q->where('code', 'nium'))->exists();
        $explicitHk = ($payload['payout']['sourceCurrency'] ?? null) === 'USD'
            && ($payload['payout']['destinationCurrency'] ?? null) === 'USD'
            && ($payload['payout']['payoutMethod'] ?? null) === 'SWIFT' && ! $hasFx;
        if ($method === 'POST' && $isRemittance && ($boundSgd || ! $explicitHk)) {
            return 'remittance';
        }
        if ($isQuote && $currency === 'SGD') {
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
