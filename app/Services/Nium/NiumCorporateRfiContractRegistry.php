<?php

namespace App\Services\Nium;

use App\Models\NiumRfiCase;
use RuntimeException;

final class NiumCorporateRfiContractRegistry
{
    public function __construct(private readonly NiumCorporateRfiPostEnvelopeAdapter $adapter) {}

    public function assertVerified(NiumRfiCase $case): array
    {
        $envelope = $this->adapter->prepare($case);
        $blockers = array_values(array_filter((array) ($envelope['contractBlockers'] ?? []), 'is_string'));
        if ($blockers !== []) {
            throw new RuntimeException('NIUM_RFI_PROVIDER_CONTRACT_GATE: unresolved contract blockers: '.implode(' ', $blockers));
        }

        $actual = $this->identity($envelope);
        foreach ((array) config('nium_rfi_contracts.corporate', []) as $verified) {
            if (! is_array($verified)) {
                continue;
            }

            $expected = [
                'environment' => trim((string) ($verified['environment'] ?? '')),
                'endpoint' => trim((string) ($verified['endpoint'] ?? '')),
                'method' => strtoupper(trim((string) ($verified['method'] ?? ''))),
                'request_schema_version' => trim((string) ($verified['request_schema_version'] ?? '')),
                'contract_version' => trim((string) ($verified['contract_version'] ?? '')),
                'template_fingerprint' => strtolower(trim((string) ($verified['template_fingerprint'] ?? ''))),
                'required_field_shape_fingerprint' => strtolower(trim((string) ($verified['required_field_shape_fingerprint'] ?? ''))),
            ];
            if ($this->matches($expected, $actual)) {
                return ['envelope' => $envelope, 'contract' => $actual];
            }
        }

        throw new RuntimeException('NIUM_RFI_PROVIDER_CONTRACT_GATE: this environment, endpoint, version, template, and required-field shape are not verified for live submission.');
    }

    public function blockers(NiumRfiCase $case): array
    {
        try {
            $this->assertVerified($case);
            return [];
        } catch (RuntimeException $exception) {
            return [$exception->getMessage()];
        }
    }

    public function identity(array $envelope): array
    {
        $requiredField = (array) data_get($envelope, 'reviewMetadata.requiredField', []);

        return [
            'environment' => app()->environment(),
            'endpoint' => (string) data_get($envelope, 'transport.endpointTemplate'),
            'method' => strtoupper((string) data_get($envelope, 'transport.method')),
            'request_schema_version' => trim((string) config('services.nium.customer_rfi_request_schema_version')),
            'contract_version' => trim((string) config('services.nium.customer_rfi_response_contract_version')),
            'template_fingerprint' => hash('sha256', (string) data_get($envelope, 'reviewMetadata.templateId')),
            'required_field_shape_fingerprint' => hash('sha256', json_encode([
                'template_name' => data_get($envelope, 'reviewMetadata.templateName'),
                'rfi_type' => data_get($envelope, 'reviewMetadata.rfiType'),
                'field_value' => $requiredField['fieldValue'] ?? null,
                'field_type' => $requiredField['type'] ?? null,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        ];
    }

    private function matches(array $expected, array $actual): bool
    {
        foreach ($actual as $key => $value) {
            if (! is_string($value) || $value === '' || ! hash_equals((string) ($expected[$key] ?? ''), $value)) {
                return false;
            }
        }

        return true;
    }
}
