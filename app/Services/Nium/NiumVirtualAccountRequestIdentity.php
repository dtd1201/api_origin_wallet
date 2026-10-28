<?php

namespace App\Services\Nium;

use App\Models\UserProviderAccount;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class NiumVirtualAccountRequestIdentity
{
    private array $snapshot;

    private function __construct(array $snapshot)
    {
        foreach (['client_id', 'customer_id', 'wallet_id'] as $key) {
            if (! is_string($snapshot[$key] ?? null) || preg_match('/^[A-Za-z0-9_-]{1,255}$/D', $snapshot[$key]) !== 1
                || self::identifier($snapshot[$key]) !== $snapshot[$key]) {
                throw new InvalidArgumentException('A canonical assignment target is required.');
            }
        }
        $origin = self::origin($snapshot['provider_origin'] ?? '');
        $endpoint = self::endpoint($snapshot['client_id'], $snapshot['customer_id'], $snapshot['wallet_id'], $snapshot['api_version']);
        if ($origin !== $snapshot['provider_origin'] || $snapshot['environment'] !== self::environment($origin)
            || $snapshot['endpoint'] !== $endpoint
            || $snapshot['provider_account_id'] < 1 || $snapshot['provider_id'] < 1 || $snapshot['user_id'] < 1) {
            throw new InvalidArgumentException('The assignment origin and endpoint must match its immutable target.');
        }
        $payload = $snapshot['payload'];
        if (! is_array($payload)) {
            throw new InvalidArgumentException('Invalid canonical assignment payload.');
        }
        NiumVirtualAccountAssignmentContract::assertPayload($snapshot['api_version'], $payload, $snapshot['funding_rail'] ?? null);
        ksort($payload, SORT_STRING);
        $this->snapshot = array_replace($snapshot, ['payload' => $payload]);
    }

    /** Caller supplies the final effective payload, including any already-resolved bank selection. */
    public static function forAccount(UserProviderAccount $account, array $effectivePayload, string $apiVersion = 'v2', ?string $fundingRail = null): self
    {
        if (strtolower((string) $account->provider()->value('code')) !== 'nium') {
            throw new InvalidArgumentException('Assignment identity requires the Nium provider relationship.');
        }
        $baseUrl = (string) config('services.nium.base_url');
        $origin = self::origin($baseUrl);
        if (! in_array(parse_url($baseUrl, PHP_URL_PATH), [null, '', '/'], true)) {
            throw new InvalidArgumentException('Nium base URL must contain only the trusted provider origin.');
        }
        $client = self::identifier((string) config('services.nium.client_id'));
        $customer = self::identifier((string) $account->external_customer_id);
        $wallet = self::identifier((string) $account->external_account_id);
        $template = NiumVirtualAccountAssignmentContract::endpointTemplate($apiVersion);
        if ($apiVersion === 'v2' && config('services.nium.assign_payment_id_endpoint') !== $template) {
            throw new InvalidArgumentException('The configured assignment endpoint is not the supported V2 contract.');
        }

        return new self([
            'environment' => self::environment($origin), 'provider_origin' => $origin,
            'provider_account_id' => (int) $account->id, 'provider_id' => (int) $account->provider_id,
            'user_id' => (int) $account->user_id, 'client_id' => $client, 'customer_id' => $customer,
            'wallet_id' => $wallet, 'api_version' => $apiVersion, 'endpoint' => self::endpoint($client, $customer, $wallet, $apiVersion),
            'payload' => $effectivePayload,
        ] + ($apiVersion === 'v1' ? ['funding_rail' => $fundingRail] : []));
    }

    /** Restores encrypted historical identity without substituting today's mutable configuration. */
    public static function fromSnapshot(array $snapshot): self
    {
        return new self($snapshot);
    }

    public function assertCurrentTarget(UserProviderAccount $account): void
    {
        if (! $this->matches(self::forAccount($account, $this->snapshot['payload'], $this->snapshot['api_version'], $this->snapshot['funding_rail'] ?? null))) {
            throw new InvalidArgumentException('Trusted provider configuration or account target has changed.');
        }
    }

    public static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || ! is_string($parts['host'] ?? null) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20]/', $url) === 1
            || filter_var($parts['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new InvalidArgumentException('A trusted HTTPS provider origin is required.');
        }
        $port = $parts['port'] ?? 443;

        if ($port < 1) {
            throw new InvalidArgumentException('Invalid provider HTTPS port.');
        }

        return 'https://'.strtolower(rtrim($parts['host'], '.')).($port === 443 ? '' : ':'.$port);
    }

    private static function environment(string $origin): string
    {
        return 'nium:'.hash('sha256', $origin);
    }

    private static function identifier(string $value): string
    {
        return Str::isUuid($value) ? strtolower($value) : $value;
    }

    private static function endpoint(string $client, string $customer, string $wallet, string $version): string
    {
        NiumVirtualAccountAssignmentContract::assertVersion($version);

        return '/api/'.$version.'/client/'.rawurlencode($client).'/customer/'.rawurlencode($customer).'/wallet/'.rawurlencode($wallet).'/paymentId';
    }

    public function snapshot(): array
    {
        return $this->snapshot;
    }

    public function payloadHash(): string
    {
        return hash('sha256', json_encode($this->snapshot['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function scopeKey(): string
    {
        return hash('sha256', json_encode([
            $this->snapshot['environment'], $this->snapshot['client_id'], $this->snapshot['wallet_id'], $this->currency(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function currency(): string
    {
        return $this->snapshot['payload'][$this->snapshot['api_version'] === 'v1' ? 'currencyCode' : 'currency'];
    }

    public function fundingRail(): string
    {
        return $this->snapshot['api_version'] === 'v1' ? $this->snapshot['funding_rail'] : $this->snapshot['payload']['accountType'];
    }

    public function matches(self $other): bool
    {
        return $this->snapshot === $other->snapshot;
    }
}
