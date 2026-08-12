<?php

namespace App\Services\Nium;

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
        $routingType = strtoupper((string) ($payload['routingCodeType1'] ?? ''));
        if ($schema->routingCodeTypes !== [] && ($routingType === '' || ! in_array($routingType, $schema->routingCodeTypes, true))) {
            throw new RuntimeException('Beneficiary routing-code type does not match the selected corridor.');
        }
        foreach ($schema->fields as $field) {
            $name = $field['name'];
            $value = $payload[$name] ?? null;
            if ($field['required'] && ($value === null || $value === '' || (is_array($value) && $value === []))) {
                throw new RuntimeException("Beneficiary required field {$name} is missing or null.");
            }
            if ($value === null) {
                continue;
            }
            if (isset($field['allowed_values']) && ! in_array($value, $field['allowed_values'], true)) {
                throw new RuntimeException("Beneficiary field {$name} has an unexpected value.");
            }
            if (is_string($value) && isset($field['pattern']) && @preg_match('/'.$field['pattern'].'/u', $value) !== 1) {
                throw new RuntimeException("Beneficiary field {$name} does not satisfy provider validation.");
            }
        }
        return ['valid' => true, 'schema_fingerprint' => $schema->schemaFingerprint, 'dimensions' => $schema->dimensions];
    }
}
