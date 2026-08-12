<?php

namespace App\Services\Nium;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class NiumBeneficiaryRequirementsService
{
    private const DIMENSIONS = ['destinationCountry', 'destinationCurrency', 'payoutMethod', 'beneficiaryAccountType', 'customerType'];

    public function __construct(private readonly NiumService $niumService) {}

    public function requirements(User $user, array $selectedCorridor): NiumBeneficiaryRequirementsResult
    {
        $dimensions = $this->dimensions($selectedCorridor);
        $routingTypes = $this->routingTypes($selectedCorridor['routingCodeType'] ?? []);
        $key = 'nium:beneficiary-requirements:v1:'.hash('sha256', json_encode([
            $dimensions, $routingTypes, hash('sha256', $this->niumService->clientId()),
            hash('sha256', (string) config('services.nium.beneficiary_validation_schema_endpoint', '')),
        ], JSON_THROW_ON_ERROR));

        $normalized = Cache::remember($key, (int) config('services.nium.beneficiary_requirements_cache_seconds', 3600), function () use ($user, $dimensions, $routingTypes): array {
            $endpoint = (string) config('services.nium.beneficiary_validation_schema_endpoint', '');
            if ($endpoint === '') {
                throw new RuntimeException('Nium beneficiary validation-schema endpoint is not configured.');
            }
            $response = $this->niumService->get(
                $this->niumService->path($endpoint, [
                    'client' => $this->niumService->clientId(),
                    'customer' => $this->niumService->customerId($user),
                    'currencyCode' => $dimensions['destinationCurrency'],
                ]),
                [...$dimensions, 'routingCodeType' => $routingTypes],
                $user,
            );
            if (! $response->successful() || ! is_array($response->json())) {
                throw new RuntimeException('Nium beneficiary requirements lookup failed.');
            }
            return $this->normalize($response->json(), $dimensions, $routingTypes);
        });

        return NiumBeneficiaryRequirementsResult::trusted(
            $normalized['dimensions'], $normalized['routing_code_types'], $normalized['fields'],
            $normalized['fetched_at'], $normalized['cache_version'],
        );
    }

    public function dimensions(array $corridor): array
    {
        $result = [];
        foreach (self::DIMENSIONS as $name) {
            $value = strtoupper(trim((string) ($corridor[$name] ?? '')));
            if ($value === '') {
                throw new RuntimeException("Selected Nium corridor is missing {$name}.");
            }
            $result[$name] = $value;
        }
        return $result;
    }

    public function assertMatchesCorridor(NiumBeneficiaryRequirementsResult $schema, array $corridor): void
    {
        $schema->assertTrusted((int) config('services.nium.beneficiary_requirements_cache_seconds', 3600));
        if ($schema->dimensions !== $this->dimensions($corridor)
            || $schema->routingCodeTypes !== $this->routingTypes($corridor['routingCodeType'] ?? [])) {
            throw new RuntimeException('Nium beneficiary schema dimensions do not match the selected corridor.');
        }
    }

    private function normalize(array $body, array $dimensions, array $routingTypes): array
    {
        $candidate = Arr::get($body, 'data') ?? Arr::get($body, 'validationSchema') ?? Arr::get($body, 'content.0') ?? $body;
        if (! is_array($candidate)) {
            throw new RuntimeException('Unknown Nium beneficiary requirements response shape.');
        }
        $fields = $candidate['fields'] ?? $candidate['requirements'] ?? $candidate['validationRules'] ?? null;
        if (! is_array($fields) || ! array_is_list($fields)) {
            throw new RuntimeException('Unknown Nium beneficiary requirements response shape.');
        }

        $normalized = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                throw new RuntimeException('Unknown Nium beneficiary field schema shape.');
            }
            $name = $field['fieldName'] ?? $field['name'] ?? $field['key'] ?? null;
            $required = $field['required'] ?? $field['mandatory'] ?? null;
            if (! is_string($name) || $name === '' || ! is_bool($required)) {
                throw new RuntimeException('Unknown Nium beneficiary field schema shape.');
            }
            $normalized[] = array_filter([
                'name' => $name,
                'required' => $required,
                'type' => is_string($field['type'] ?? null) ? strtolower($field['type']) : null,
                'pattern' => is_string($field['pattern'] ?? null) ? $field['pattern'] : null,
                'min_length' => is_int($field['minLength'] ?? null) ? $field['minLength'] : null,
                'max_length' => is_int($field['maxLength'] ?? null) ? $field['maxLength'] : null,
                'allowed_values' => is_array($field['allowedValues'] ?? null) ? array_values($field['allowedValues']) : null,
            ], static fn ($value) => $value !== null);
        }

        return ['dimensions' => $dimensions, 'routing_code_types' => $routingTypes, 'fields' => $normalized,
            'fetched_at' => now()->toISOString(), 'cache_version' => 'nium-beneficiary-requirements-v1'];
    }

    private function routingTypes(mixed $value): array
    {
        $types = is_array($value) ? $value : [$value];
        $types = array_values(array_unique(array_filter(array_map(static fn ($type) => strtoupper(trim((string) $type)), $types))));
        sort($types);
        return $types;
    }
}
