<?php

namespace App\Services\Nium;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class NiumBeneficiaryRequirementsService
{
    private const DIMENSIONS = ['destinationCountry', 'destinationCurrency', 'payoutMethod', 'beneficiaryAccountType', 'customerType'];

    public function __construct(private readonly NiumService $niumService) {}

    public function requirements(User $user, array $selectedCorridor): NiumBeneficiaryRequirementsResult
    {
        $dimensions = $this->dimensions($selectedCorridor);
        $routingTypes = $this->routingTypes($selectedCorridor['routingCodeType'] ?? []);
        $key = 'nium:beneficiary-requirements:v3:'.hash('sha256', json_encode([
            $dimensions, $routingTypes, NiumBeneficiaryService::CLIENT_LEGAL_ENTITY, hash('sha256', $this->niumService->clientId()),
            hash('sha256', (string) config('services.nium.beneficiary_validation_schema_endpoint', '')),
        ], JSON_THROW_ON_ERROR));

        $status = null;
        try {
            $normalized = Cache::remember($key, (int) config('services.nium.beneficiary_requirements_cache_seconds', 3600), function () use ($user, $dimensions, $routingTypes, &$status): array {
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
                $status = $response->status();
                if (! $response->successful()) {
                    throw new NiumBeneficiarySchemaException('provider_http_failure', 'response');
                }
                if (! is_array($response->json())) {
                    throw new NiumBeneficiarySchemaException('invalid_json_response', 'response');
                }

                return $this->normalize($response->json(), $dimensions, $routingTypes, json_decode($response->body()));
            });
        } catch (Throwable $exception) {
            Log::warning('Nium beneficiary requirements unavailable.', [
                'reason' => $exception instanceof NiumBeneficiarySchemaException ? $exception->reason : 'lookup_failure',
                'schema_location' => $exception instanceof NiumBeneficiarySchemaException ? $exception->location : null,
                'http_status' => $status,
            ]);
            throw $exception;
        }

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

    private function normalize(array $body, array $dimensions, array $routingTypes, mixed $document): array
    {
        $candidate = Arr::get($body, 'data') ?? Arr::get($body, 'validationSchema') ?? Arr::get($body, 'content') ?? $body;
        if (! is_array($candidate)) {
            throw new NiumBeneficiarySchemaException('unknown_response_shape', 'response');
        }
        if (array_is_list($candidate)) {
            if (count($candidate) !== 1 || ! is_array($candidate[0])) {
                throw new NiumBeneficiarySchemaException('ambiguous_schema_array', 'response');
            }
            $candidate = $candidate[0];
        }
        $fields = $candidate['fields'] ?? $candidate['requirements'] ?? $candidate['validationRules'] ?? null;
        $isJsonSchema = count(array_intersect(array_keys($candidate), ['$schema', 'properties', 'allOf'])) > 0
            || ($fields === null && array_key_exists('type', $candidate));
        if ($isJsonSchema) {
            // Preserve JSON objects vs arrays: associative decoding conflates {} with [].
            $schema = data_get($document, 'data') ?? data_get($document, 'validationSchema') ?? data_get($document, 'content') ?? $document;
            if (is_array($schema)) {
                $schema = $schema[0];
            }
            $normalized = (new NiumBeneficiaryJsonSchemaNormalizer)->fields($schema, $dimensions, $routingTypes, NiumBeneficiaryService::CLIENT_LEGAL_ENTITY);

            return ['dimensions' => $dimensions, 'routing_code_types' => $routingTypes, 'fields' => $normalized,
                'fetched_at' => now()->toISOString(), 'cache_version' => 'nium-beneficiary-requirements-v3'];
        }
        if (! is_array($fields) || ! array_is_list($fields)) {
            throw new NiumBeneficiarySchemaException('unknown_response_shape', 'response');
        }

        $normalized = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                throw new NiumBeneficiarySchemaException('invalid_legacy_field', 'response.fields.*');
            }
            $name = $field['fieldName'] ?? $field['name'] ?? $field['key'] ?? null;
            $required = $field['required'] ?? $field['mandatory'] ?? null;
            if (! is_string($name) || $name === '' || ! is_bool($required)) {
                throw new NiumBeneficiarySchemaException('invalid_legacy_field', 'response.fields.*');
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
            'fetched_at' => now()->toISOString(), 'cache_version' => 'nium-beneficiary-requirements-v3'];
    }

    private function routingTypes(mixed $value): array
    {
        $types = is_array($value) ? $value : [$value];
        $types = array_values(array_unique(array_filter(array_map(static fn ($type) => strtoupper(trim((string) $type)), $types))));
        sort($types);

        return $types;
    }
}
