<?php

namespace App\Services\Nium;

use RuntimeException;

/** An explicit operator-approved selection; never discovers or expands customer scope. */
final readonly class NiumEntitlementEvidenceScope
{
    private function __construct(
        public string $alias,
        public string $environment,
        public string $origin,
        private string $client,
        private ?string $customer,
        public string $clientAlias,
        public ?string $customerAlias,
        public string $region,
        public string $appEnvironment,
        public string $approvalFingerprint,
        public array $corridors,
        public array $verifiedPatterns,
    ) {}

    public static function resolve(string $alias, string $environment, array $selection): self
    {
        $policy = config('nium_entitlement_evidence');
        $scope = is_array($policy) ? ($policy['scopes'][$alias] ?? null) : null;
        if (! self::alias($alias, 'scope') || ! in_array($environment, ['sandbox', 'production'], true)
            || ! is_array($scope) || ($scope['verified'] ?? null) !== true
            || ! is_string($scope['verification_sha256'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}$/D', $scope['verification_sha256'])
            || ($scope['provider_environment'] ?? null) !== $environment
            || ($scope['app_environment'] ?? null) !== config('app.env')
            || ! in_array($scope['app_environment'] ?? null, ['local', 'testing', 'staging', 'production', 'development', 'sandbox'], true)) {
            throw new RuntimeException('evidence_scope_not_verified');
        }
        $origin = $scope['origin'] ?? null;
        $parts = is_string($origin) ? parse_url($origin) : false;
        if (! is_array($parts) || array_diff(array_keys($parts), ['scheme', 'host']) !== []
            || ($parts['scheme'] ?? null) !== 'https'
            || ! preg_match('/^(?:[a-z0-9-]+\.)*nium\.com$/D', $parts['host'] ?? '')
            || ! in_array($origin, $policy['approved_origins'][$environment] ?? [], true)
            || $origin !== rtrim((string) config('services.nium.base_url'), '/')) {
            throw new RuntimeException('evidence_origin_not_approved');
        }
        $client = $scope['client_hash_id'] ?? null;
        $customer = $scope['customer_hash_id'] ?? null;
        if (! self::identifier($client) || $client !== config('services.nium.client_id')
            || ! self::alias($scope['client_alias'] ?? null, 'client')
            || ($customer !== null && (! self::identifier($customer) || ! self::alias($scope['customer_alias'] ?? null, 'customer')))
            || ($customer === null && ($scope['customer_alias'] ?? null) !== null)
            || ! is_string($scope['regulatory_region'] ?? null)
            || ! preg_match('/^[A-Z]{2}$/D', $scope['regulatory_region'])
            || $scope['regulatory_region'] !== config('services.nium.regulatory_region')) {
            throw new RuntimeException('evidence_identity_binding_invalid');
        }
        $corridors = [];
        foreach (array_unique($selection, SORT_REGULAR) as $key) {
            $row = is_string($key) ? ($scope['corridors'][$key] ?? null) : null;
            if ($customer === null || ! self::alias($key, 'corridor') || ! is_array($row)
                || ($row['verified'] ?? null) !== true
                || array_diff(array_keys($row), ['verified', 'destinationCountry', 'destinationCurrency', 'payoutMethod', 'customerType', 'beneficiaryAccountType']) !== []) {
                throw new RuntimeException('evidence_corridor_not_verified');
            }
            $filters = [];
            foreach (['destinationCountry', 'destinationCurrency', 'payoutMethod', 'customerType', 'beneficiaryAccountType'] as $field) {
                $value = $row[$field] ?? null;
                $valid = match ($field) {
                    'destinationCountry' => is_string($value) && preg_match('/^[A-Z]{2}$/D', $value),
                    'destinationCurrency' => is_string($value) && preg_match('/^[A-Z]{3}$/D', $value),
                    'payoutMethod' => in_array($value, ['LOCAL', 'SWIFT', 'WALLET', 'CASH', 'CARD', 'PROXY', 'CHECK'], true),
                    default => in_array($value, ['INDIVIDUAL', 'CORPORATE'], true),
                };
                if (! $valid) {
                    throw new RuntimeException('evidence_corridor_dimensions_invalid');
                }
                $filters[$field] = $value;
            }
            $corridors[$key] = $filters;
        }
        ksort($corridors);

        return new self($alias, $environment, $origin, $client, $customer, $scope['client_alias'],
            $scope['customer_alias'] ?? null, $scope['regulatory_region'], $scope['app_environment'], $scope['verification_sha256'], $corridors,
            is_array($scope['verified_schema_patterns'] ?? null) ? $scope['verified_schema_patterns'] : []);
    }

    public function clientId(): string
    {
        return $this->client;
    }

    public function customerId(): ?string
    {
        return $this->customer;
    }

    public function projection(): array
    {
        return ['alias' => $this->alias, 'provider_environment' => $this->environment,
            'app_environment' => $this->appEnvironment, 'provider_origin' => $this->origin,
            'approval_reference_sha256' => $this->approvalFingerprint,
            'client_alias' => $this->clientAlias, 'customer_alias' => $this->customerAlias,
            'regulatory_region' => $this->region, 'corridors' => $this->corridors];
    }

    private static function alias(mixed $value, string $prefix): bool
    {
        return is_string($value) && preg_match('/^'.$prefix.'-[a-f0-9]{8}$/D', $value) === 1;
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di', $value) === 1;
    }
}
