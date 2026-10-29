<?php

namespace App\Services\Nium;

use stdClass;

/** Compiles the representable JSON Schema subset into the existing flat preflight contract. */
final class NiumBeneficiaryJsonSchemaNormalizer
{
    private const ANNOTATIONS = ['$schema', '$id', 'title', 'description', '$comment', 'default', 'examples', 'errorMessage'];

    public function fields(mixed $schema, array $dimensions, array $routingTypes): array
    {
        $this->object($schema, 'schema');
        $schema = (array) $schema;
        if (array_key_exists('$schema', $schema) && ! in_array($schema['$schema'], [
            'http://json-schema.org/draft-07/schema#', 'https://json-schema.org/draft-07/schema#',
            'https://json-schema.org/draft/2019-09/schema', 'https://json-schema.org/draft/2020-12/schema',
        ], true)) {
            $this->fail('unsupported_dialect');
        }
        // Match the actual beneficiary payload casing, rather than the corridor's account-type casing.
        $facts = [...$dimensions, 'beneficiaryAccountType' => ucfirst(strtolower($dimensions['beneficiaryAccountType'])),
            'clientLegalEntity' => NiumBeneficiaryService::CLIENT_LEGAL_ENTITY];
        $rules = $this->objectRules((object) $schema, $facts);
        // Pin the facts used to specialize conditions, including their exact case, in preflight too.
        foreach ($facts as $name => $value) {
            $rules = $this->merge($rules, ['fields' => [$name => ['type' => 'string', 'allowed_values' => [$value]]], 'required' => [$name]]);
        }
        $fields = [];
        foreach (array_unique([...array_keys($rules['fields']), ...$rules['required']]) as $name) {
            $field = $rules['fields'][$name] ?? [];
            $required = in_array($name, $rules['required'], true);
            if (in_array($name, ['routingCodeType1', 'routingCodeType2'], true)) {
                $field['allowed_values'] = array_values(array_filter($field['allowed_values'] ?? $routingTypes,
                    static fn ($type) => in_array($type, $routingTypes, true)));
            }
            if (! isset($field['type'])) {
                $types = array_unique(array_map(static fn ($value) => match (gettype($value)) {
                    'string' => 'string', 'integer' => 'integer', 'double' => 'number', 'boolean' => 'boolean', default => 'unsupported',
                }, $field['allowed_values'] ?? []));
                if (count($types) !== 1 || in_array('unsupported', $types, true)) {
                    $this->fail('unrepresentable_property_type', 'schema.properties.*');
                }
                $field['type'] = reset($types);
            }
            if (($required && ($field['allowed_values'] ?? null) === [])
                || (isset($field['min_length'], $field['max_length']) && $field['min_length'] > $field['max_length'])) {
                $this->fail('conflicting_constraints', 'schema.properties.*');
            }
            $fields[] = ['name' => $name, 'required' => $required, ...$field];
        }

        return $fields;
    }

    private function objectRules(mixed $schema, array $facts, int $depth = 0): array
    {
        $this->object($schema, 'schema');
        $schema = (array) $schema;
        if ($depth > 20) {
            $this->fail('schema_too_deep');
        }
        $this->keywords($schema, ['type', 'properties', 'required', 'allOf', 'if', 'then', 'else', 'additionalProperties'], 'schema');
        if (array_key_exists('type', $schema) && $schema['type'] !== 'object') {
            $this->fail('unsupported_object_type');
        }
        if (array_key_exists('additionalProperties', $schema) && $schema['additionalProperties'] !== true) {
            $this->fail('unsupported_additional_properties');
        }
        $properties = array_key_exists('properties', $schema) ? $schema['properties'] : new stdClass;
        $this->object($properties, 'schema.properties');
        $rules = ['fields' => [], 'required' => $this->required($schema)];
        foreach ($properties as $name => $property) {
            if (! is_string($name) || $name === '') {
                $this->fail('invalid_property_name', 'schema.properties.*');
            }
            $rules['fields'][$name] = $this->property($property);
        }
        if (array_key_exists('allOf', $schema)) {
            if (! is_array($schema['allOf']) || ! array_is_list($schema['allOf']) || $schema['allOf'] === []) {
                $this->fail('invalid_all_of', 'schema.allOf');
            }
            foreach ($schema['allOf'] as $clause) {
                $rules = $this->merge($rules, $this->objectRules($clause, $facts, $depth + 1));
            }
        }
        if (array_key_exists('if', $schema)) {
            $matches = $this->condition($schema['if'], $facts);
            // Check both branches for unsupported semantics; apply only the selected branch.
            foreach (['then', 'else'] as $branch) {
                if (array_key_exists($branch, $schema)) {
                    $branchRules = $this->objectRules($schema[$branch], $facts, $depth + 1);
                    if ($matches === ($branch === 'then')) {
                        $rules = $this->merge($rules, $branchRules);
                    }
                }
            }
        } elseif (array_key_exists('then', $schema) || array_key_exists('else', $schema)) {
            $this->fail('ambiguous_conditional', 'schema.allOf');
        }

        return $rules;
    }

    private function condition(mixed $condition, array $facts): bool
    {
        $this->object($condition, 'schema.if');
        $condition = (array) $condition;
        $this->keywords($condition, ['type', 'properties', 'required'], 'schema.if');
        if (array_key_exists('type', $condition) && $condition['type'] !== 'object') {
            $this->fail('unsupported_condition', 'schema.if');
        }
        $properties = array_key_exists('properties', $condition) ? $condition['properties'] : new stdClass;
        $this->object($properties, 'schema.if.properties');
        foreach (array_unique([...array_keys((array) $properties), ...$this->required($condition)]) as $name) {
            if (! array_key_exists($name, $facts)) {
                $this->fail('payload_dependent_condition', 'schema.if.properties.*');
            }
        }
        $matches = true;
        foreach ($properties as $name => $property) {
            $field = $this->property($property);
            // Corridor facts are strings; no payload value or presence is guessed here.
            $value = $facts[$name];
            $matches = $matches && (! isset($field['type']) || $field['type'] === 'string')
                && (! isset($field['allowed_values']) || in_array($value, $field['allowed_values'], true))
                && (! isset($field['min_length']) || mb_strlen($value) >= $field['min_length'])
                && (! isset($field['max_length']) || mb_strlen($value) <= $field['max_length'])
                && (! isset($field['pattern']) || preg_match('/'.$field['pattern'].'/u', $value) === 1);
        }

        return $matches;
    }

    private function property(mixed $property): array
    {
        $path = 'schema.properties.*';
        $this->object($property, $path);
        $property = (array) $property;
        $this->keywords($property, ['type', 'enum', 'const', 'pattern', 'minLength', 'maxLength'], $path);
        $field = [];
        if (array_key_exists('type', $property)) {
            if (! in_array($property['type'], ['string', 'integer', 'number', 'boolean'], true)) {
                $this->fail('unsupported_property_type', $path);
            }
            $field['type'] = $property['type'];
        }
        foreach (['enum', 'const'] as $keyword) {
            if (! array_key_exists($keyword, $property)) {
                continue;
            }
            $values = $keyword === 'enum' ? $property[$keyword] : [$property[$keyword]];
            if (! is_array($values) || ! array_is_list($values) || $values === []
                || count(array_filter($values, static fn ($value) => ! is_scalar($value))) > 0) {
                $this->fail('unsupported_enum', $path);
            }
            $field['allowed_values'] = isset($field['allowed_values'])
                ? array_values(array_filter($field['allowed_values'], static fn ($value) => in_array($value, $values, true))) : $values;
        }
        foreach (['minLength' => 'min_length', 'maxLength' => 'max_length'] as $source => $target) {
            if (array_key_exists($source, $property)) {
                if (! is_int($property[$source]) || $property[$source] < 0) {
                    $this->fail('invalid_length', $path);
                }
                $field[$target] = $property[$source];
            }
        }
        if (array_key_exists('pattern', $property)) {
            if (! is_string($property['pattern']) || @preg_match('/'.$property['pattern'].'/u', '') === false) {
                $this->fail('unsupported_pattern', $path);
            }
            $field['pattern'] = $property['pattern'];
        }

        return $field;
    }

    private function merge(array $left, array $right): array
    {
        $left['required'] = array_values(array_unique([...$left['required'], ...$right['required']]));
        foreach ($right['fields'] as $name => $constraints) {
            $field = $left['fields'][$name] ?? [];
            foreach ($constraints as $key => $value) {
                if (! array_key_exists($key, $field)) {
                    $field[$key] = $value;
                } elseif ($key === 'allowed_values') {
                    $field[$key] = array_values(array_filter($field[$key], static fn ($item) => in_array($item, $value, true)));
                } elseif ($key === 'min_length') {
                    $field[$key] = max($field[$key], $value);
                } elseif ($key === 'max_length') {
                    $field[$key] = min($field[$key], $value);
                } elseif ($field[$key] !== $value) {
                    $this->fail('unrepresentable_conjunction', 'schema.allOf.properties.*');
                }
            }
            $left['fields'][$name] = $field;
        }

        return $left;
    }

    private function required(array $schema): array
    {
        $required = array_key_exists('required', $schema) ? $schema['required'] : [];
        if (! is_array($required) || ! array_is_list($required)
            || count(array_filter($required, static fn ($name) => ! is_string($name) || $name === '')) > 0) {
            $this->fail('invalid_required', 'schema.required');
        }

        return $required;
    }

    private function object(mixed $value, string $path): void
    {
        if (! $value instanceof stdClass) {
            $this->fail('invalid_schema_object', $path);
        }
    }

    private function keywords(array $schema, array $supported, string $path): void
    {
        $unsupported = array_diff(array_keys($schema), [...self::ANNOTATIONS, ...$supported]);
        if ($unsupported !== []) {
            // Identify standard keywords without copying arbitrary provider-controlled keys into logs.
            $known = array_intersect($unsupported, ['$ref', '$defs', 'definitions', 'anyOf', 'oneOf', 'not', 'format',
                'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf',
                'dependencies', 'dependentRequired', 'dependentSchemas', 'patternProperties', 'unevaluatedProperties',
                'items', 'contains', 'minItems', 'maxItems', 'uniqueItems']);
            $this->fail('unsupported_keyword', $known === [] ? $path : $path.'.'.reset($known));
        }
    }

    private function fail(string $reason, string $location = 'schema'): never
    {
        throw new NiumBeneficiarySchemaException($reason, $location);
    }
}
