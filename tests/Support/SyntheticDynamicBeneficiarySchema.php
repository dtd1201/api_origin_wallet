<?php

declare(strict_types=1);

namespace Tests\Support;

/** Synthetic constraints for offline plumbing tests, never Nium corridor evidence. */
final class SyntheticDynamicBeneficiarySchema
{
    public static function body(array $properties = [], array $required = [], array $clauses = []): string
    {
        return json_encode(['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'type' => 'object',
            'properties' => (object) [...[
                'beneficiaryName' => ['type' => 'string', 'minLength' => 1],
                'beneficiaryAccountType' => ['type' => 'string', 'enum' => ['Individual', 'Corporate']],
                'beneficiaryAccountNumber' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9 -]+$'],
            ], ...$properties],
            'required' => array_values(array_unique(['beneficiaryName', 'beneficiaryAccountType', 'beneficiaryAccountNumber', ...$required])),
            ...($clauses === [] ? [] : ['allOf' => $clauses])], JSON_THROW_ON_ERROR);
    }
}
