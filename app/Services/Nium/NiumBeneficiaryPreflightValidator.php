<?php

namespace App\Services\Nium;

use Illuminate\Validation\ValidationException;
use RuntimeException;

final class NiumBeneficiaryPreflightValidator
{
    public function __construct(private readonly NiumBeneficiaryRequirementsService $requirementsService) {}

    public function validate(array $payload, array $selectedCorridor, NiumBeneficiaryRequirementsResult $schema): array
    {
        $this->requirementsService->assertMatchesCorridor($schema, $selectedCorridor);
        foreach ($schema->dimensions as $key => $value) {
            if (strtoupper((string) ($payload[$key] ?? '')) !== $value) {
                throw new RuntimeException("Beneficiary {$key} does not match the selected corridor.");
            }
        }
        $errors = [];
        foreach ([1, 2] as $slot) {
            $type = $payload["routingCodeType{$slot}"] ?? null;
            $value = $payload["routingCodeValue{$slot}"] ?? null;
            $typeField = collect($schema->fields)->firstWhere('name', "routingCodeType{$slot}");
            $valueField = collect($schema->fields)->firstWhere('name', "routingCodeValue{$slot}");
            $required = ($typeField['required'] ?? false) || ($valueField['required'] ?? false)
                || ($slot === 1 && $schema->routingCodeTypes !== [] && $typeField === null && $valueField === null);
            if ($required || filled($type) || filled($value)) {
                if (! in_array($type, $schema->routingCodeTypes, true)) {
                    $errors["routingCodeType{$slot}"] = 'Select a routing type supported by this payment method.';
                }
                if (! filled($value)) {
                    $errors["routingCodeValue{$slot}"] = 'Enter the routing code for the selected type.';
                }
            }
        }
        foreach ($schema->fields as $field) {
            $name = $field['name'];
            $value = $payload[$name] ?? null;
            if ($field['required'] && ($value === null || $value === '' || $value === [])) {
                $errors[$name] = 'This field is required for the selected payment method.';

                continue;
            }
            if ($value === null) {
                continue;
            }
            $validType = match ($field['type'] ?? 'string') {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                default => false,
            };
            if (! $validType) {
                $errors[$name] = 'Enter a value of the type required for this payment method.';
            }
            if (isset($field['allowed_values']) && ! in_array($value, $field['allowed_values'], true)) {
                $errors[$name] = 'Select a value supported by the selected payment method.';
            }
            if (is_string($value)) {
                if (isset($field['pattern']) && @preg_match('/'.$field['pattern'].'/u', $value) !== 1) {
                    $errors[$name] = 'Enter a value in the format required for this payment method.';
                }
                if (isset($field['min_length']) && mb_strlen($value) < $field['min_length']) {
                    $errors[$name] = "Enter at least {$field['min_length']} characters.";
                }
                if (isset($field['max_length']) && mb_strlen($value) > $field['max_length']) {
                    $errors[$name] = "Enter no more than {$field['max_length']} characters.";
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['valid' => true, 'schema_fingerprint' => $schema->schemaFingerprint, 'dimensions' => $schema->dimensions];
    }
}
