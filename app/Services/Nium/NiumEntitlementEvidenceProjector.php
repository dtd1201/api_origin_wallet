<?php

namespace App\Services\Nium;

use Throwable;

/** Evidence definitions only. Never projects beneficiary values, balances or free text. */
final class NiumEntitlementEvidenceProjector
{
    private const METHODS = ['LOCAL', 'SWIFT', 'WALLET', 'CASH', 'CARD', 'PROXY', 'CHECK'];

    private const CATEGORIES = ['visaDirect', 'sepa', 'ach', 'proxy', 'proxyFps', 'wallet', 'wireForeign', 'proxyDuitnow', 'wireLocal', 'proxyBank', 'cash', 'unionPay', 'proxyUpi', 'check', 'fednow', 'fedwire', 'interac'];

    private const ROUTING = ['SWIFT', 'IFSC', 'ACH CODE', 'BSB CODE', 'SORT CODE', 'LOCATION ID', 'BANK CODE', 'TRANSIT NUMBER', 'BRANCH CODE', 'BRANCH NAME'];

    private const FIELD_NAMES = [
        'beneficiaryName', 'beneficiaryFirstName', 'beneficiaryLastName', 'beneficiaryMiddleName',
        'beneficiaryAccountNumber', 'beneficiaryAccountType', 'beneficiaryCountryCode',
        'beneficiaryAddress', 'beneficiaryCity', 'beneficiaryState', 'beneficiaryPostcode',
        'beneficiaryEmail', 'beneficiaryContactNumber', 'beneficiaryContactCountryCode',
        'beneficiaryBankName', 'beneficiaryBankCode', 'beneficiaryBranchCode', 'beneficiaryBankAccountType',
        'beneficiaryBankAddress', 'beneficiaryBankCountry', 'beneficiaryBankCountryCode',
        'destinationCountry', 'destinationCurrency', 'payoutMethod', 'customerType', 'clientLegalEntity',
        'routingCodeType1', 'routingCodeType2', 'routingCodeValue1', 'routingCodeValue2',
        'purposeCode', 'sourceOfFunds', 'accountNumber', 'bankAccountNumber',
    ];

    public function project(string $endpoint, mixed $body, NiumEntitlementEvidenceScope $scope, array $filters = []): array
    {
        if (! is_array($body)) {
            return $this->result([], 0, ['response_shape'], [], 'INCOMPLETE');
        }

        return match ($endpoint) {
            'client_details' => $this->client($body, $scope),
            'supported_corridors' => $this->corridors($body, $filters),
            'purpose_codes' => $this->purposes($body),
            'beneficiary_schema' => $this->beneficiary($body, $scope, $filters),
            'fee_plans' => $this->fees($body),
            // OpenAPI does not establish a usable response contract for these endpoints.
            'customer_corridors', 'payout_schema' => $this->result([], count($body), [], [], 'UNKNOWN', 'response_schema_unverified'),
            default => $this->result([], 0, [], [], 'UNKNOWN', 'endpoint_unverified'),
        };
    }

    private function client(array $body, NiumEntitlementEvidenceScope $scope): array
    {
        if (array_key_exists('clientHashId', $body) && $body['clientHashId'] !== $scope->clientId()) {
            return $this->result([], 0, [], ['client_response_scope_mismatch'], 'INCOMPLETE');
        }
        $unknown = count(array_diff(array_keys($body), ['currencies', 'currencyAuthorizationType', 'regulatoryRegion']));
        $missing = $this->missing($body, ['currencies', 'currencyAuthorizationType', 'regulatoryRegion']);
        $invalid = [];
        $data = [];
        if (array_key_exists('currencyAuthorizationType', $body)) {
            if (in_array($body['currencyAuthorizationType'], ['SINGLE', 'DUAL', 'MULTI', 'AUTO_SWEEP'], true)) {
                $data['currency_mode'] = $body['currencyAuthorizationType'];
            } else {
                $invalid[] = 'currency_mode';
            }
        }
        if (array_key_exists('regulatoryRegion', $body)) {
            if ($this->code($body['regulatoryRegion'], 2)) {
                $data['regulatory_region'] = $body['regulatoryRegion'];
                if ($body['regulatoryRegion'] !== $scope->region) {
                    $invalid[] = 'regulatory_region_scope_mismatch';
                }
            } else {
                $invalid[] = 'regulatory_region';
            }
        }
        if (isset($body['currencies']) && is_array($body['currencies']) && array_is_list($body['currencies']) && count($body['currencies']) <= 500) {
            $data['currencies'] = [];
            foreach ($body['currencies'] as $row) {
                if (! is_array($row) || ! $this->code($row['currencyCode'] ?? null, 3)) {
                    $invalid[] = 'currency_row';
                    continue;
                }
                $unknown += count(array_diff(array_keys($row), ['currencyCode', 'fxSellAllowed', 'remittanceAllowed', 'decimalUnit', 'authorizationOrder']));
                $entry = ['currency' => $row['currencyCode']];
                foreach (['fxSellAllowed' => 'can_fx_sell', 'remittanceAllowed' => 'can_remit'] as $source => $target) {
                    if (! array_key_exists($source, $row)) {
                        $missing[] = $source;
                    } elseif (is_bool($row[$source])) {
                        $entry[$target] = $row[$source];
                    } else {
                        $invalid[] = $source;
                    }
                }
                foreach (['decimalUnit' => 'decimal_unit', 'authorizationOrder' => 'funding_priority'] as $source => $target) {
                    if (array_key_exists($source, $row)) {
                        if (is_int($row[$source]) && $row[$source] >= 0 && $row[$source] <= 1000) {
                            $entry[$target] = $row[$source];
                        } else {
                            $invalid[] = $source;
                        }
                    }
                }
                $data['currencies'][] = $entry;
            }
        } elseif (array_key_exists('currencies', $body)) {
            $invalid[] = 'currencies';
        }

        return $this->result($data, $unknown, $missing, $invalid);
    }

    private function corridors(array $body, array $filters): array
    {
        $missing = $this->missing($body, ['content', 'totalPages', 'totalElements']);
        if ($missing !== [] || ! is_array($body['content']) || ! array_is_list($body['content'])
            || count($body['content']) > 100 || ! is_int($body['totalPages']) || $body['totalPages'] < 0
            || ! is_int($body['totalElements']) || $body['totalElements'] < 0) {
            return $this->result([], 0, $missing, ['pagination_shape'], 'INCOMPLETE');
        }
        $rows = [];
        $invalid = [];
        $unknown = count(array_diff(array_keys($body), ['content', 'totalPages', 'totalElements']));
        foreach ($body['content'] as $row) {
            if (! is_array($row)) {
                $invalid[] = 'corridor_row';
                continue;
            }
            $allowed = ['destinationCountry', 'destinationCurrency', 'payoutMethod', 'payoutMethodCategory', 'customerType',
                'beneficiaryAccountType', 'clientType', 'customerCountry', 'routingCodeType', 'minimumAmount', 'maximumAmount', 'limitCurrency'];
            $unknown += count(array_diff(array_keys($row), $allowed));
            $missing = [...$missing, ...$this->missing($row, array_keys($filters))];
            $entry = [];
            foreach ($filters as $field => $value) {
                if (array_key_exists($field, $row)) {
                    if ($row[$field] !== $value) {
                        $invalid[] = 'corridor_filter_mismatch';
                    } else {
                        $entry[$field] = $value;
                    }
                }
            }
            foreach (['payoutMethodCategory' => self::CATEGORIES, 'clientType' => ['FI', 'NonFI']] as $field => $values) {
                if (array_key_exists($field, $row)) {
                    if (in_array($row[$field], $values, true)) {
                        $entry[$field] = $row[$field];
                    } else {
                        $invalid[] = $field;
                    }
                }
            }
            foreach (['customerCountry', 'routingCodeType'] as $field) {
                if (! array_key_exists($field, $row)) {
                    $missing[] = $field;
                    continue;
                }
                $values = $field === 'routingCodeType' && is_string($row[$field]) ? [$row[$field]] : $row[$field];
                if (! is_array($values) || ! array_is_list($values) || count($values) > 300) {
                    $invalid[] = $field;
                    continue;
                }
                $valid = array_filter($values, fn ($value) => $field === 'customerCountry' ? $this->code($value, 2) : in_array($value, self::ROUTING, true));
                if (count($valid) !== count($values)) {
                    $invalid[] = $field;
                }
                $entry[$field] = array_values($valid);
            }
            foreach (['minimumAmount', 'maximumAmount'] as $field) {
                if (! array_key_exists($field, $row)) {
                    $missing[] = $field;
                } elseif (is_string($row[$field]) && preg_match('/^\d{1,18}(?:\.\d{1,10})?$/D', $row[$field])) {
                    $entry[$field] = $row[$field];
                } else {
                    $invalid[] = $field;
                }
            }
            if ($this->code($row['limitCurrency'] ?? null, 3)) {
                $entry['limitCurrency'] = $row['limitCurrency'];
            } elseif (! array_key_exists('limitCurrency', $row)) {
                $missing[] = 'limitCurrency';
            } else {
                $invalid[] = 'limitCurrency';
            }
            // Preserve every row/variant. Unknown restrictions never become unrestricted.
            $entry['unprojected_restrictions'] = array_intersect(array_keys($row), ['mandatoryDataRequirement', 'supportingDocuments', 'fxSource']) !== [] ? 'UNKNOWN' : 'NOT_PROVIDED';
            $rows[] = $entry;
        }

        return $this->result(['rows' => $rows, 'total_pages' => $body['totalPages'], 'total_elements' => $body['totalElements']], $unknown, $missing, $invalid);
    }

    private function purposes(array $body): array
    {
        if (! array_is_list($body) || count($body) > 2000) {
            return $this->result([], 0, [], ['purpose_shape'], 'INCOMPLETE');
        }
        $codes = [];
        $invalid = [];
        $unknown = 0;
        foreach ($body as $row) {
            if (! is_array($row) || ! is_string($row['purposeCode'] ?? null) || ! preg_match('/^IR\d{3,8}$/D', $row['purposeCode'])) {
                $invalid[] = 'purpose_code';
                continue;
            }
            $codes[] = $row['purposeCode'];
            $unknown += count(array_diff(array_keys($row), ['purposeCode']));
        }

        return $this->result(['global_vocabulary' => $codes, 'corridor_subset_status' => 'UNKNOWN'], $unknown, [], $invalid);
    }

    private function fees(array $body): array
    {
        if (! is_array($body['feePlans'] ?? null) || ! array_is_list($body['feePlans']) || count($body['feePlans']) > 500) {
            return $this->result([], 0, [], ['fee_plan_shape'], 'INCOMPLETE');
        }
        $plans = [];
        foreach ($body['feePlans'] as $plan) {
            if (! is_array($plan) || ! is_bool($plan['isDefault'] ?? null) || ! is_string($plan['planName'] ?? null)) {
                return $this->result([], 0, [], ['fee_plan_shape'], 'INCOMPLETE');
            }
            $plans[] = ['plan_alias' => 'plan-'.substr(hash('sha256', $plan['planName']), 0, 16), 'is_default' => $plan['isDefault']];
        }

        return $this->result(['plans' => $plans, 'fee_rules_status' => 'UNKNOWN', 'customer_applicability' => 'UNKNOWN'],
            count($body['feePlans']) + count(array_diff(array_keys($body), ['feePlans'])), [], [], 'UNKNOWN', 'fee_rules_and_binding_unverified');
    }

    private function beneficiary(array $body, NiumEntitlementEvidenceScope $scope, array $filters): array
    {
        $candidate = $body;
        $unknown = 0;
        foreach (['data', 'validationSchema', 'content'] as $wrapper) {
            if (array_key_exists($wrapper, $body)) {
                $candidate = $body[$wrapper];
                $unknown += count($body) - 1;
                break;
            }
        }
        if (is_array($candidate) && array_is_list($candidate) && count($candidate) === 1) {
            $candidate = $candidate[0];
        }
        try {
            if (! is_array($candidate)) {
                throw new \RuntimeException;
            }
            if (isset($candidate['properties']) || isset($candidate['allOf'])) {
                // The application normalizer specializes and adds local facts. Evidence
                // must preserve only provider definitions, never those inferred facts.
                if (($candidate['type'] ?? null) !== 'object' || ! is_array($candidate['properties'] ?? null)
                    || array_intersect(array_keys($candidate), ['allOf', 'anyOf', 'oneOf', 'if', 'then', 'else', '$ref', 'dependentRequired', 'dependentSchemas']) !== []) {
                    return $this->result([], $unknown + 1, [], [], 'UNKNOWN', 'conditional_schema_unverified');
                }
                if (isset($candidate['$schema']) && ! in_array($candidate['$schema'], [
                    'http://json-schema.org/draft-07/schema#', 'https://json-schema.org/draft-07/schema#',
                    'https://json-schema.org/draft/2019-09/schema', 'https://json-schema.org/draft/2020-12/schema',
                ], true)) {
                    return $this->result([], $unknown + 1, [], [], 'UNKNOWN', 'schema_dialect_unverified');
                }
                $unknown += count(array_diff(array_keys($candidate), ['type', 'properties', 'required', '$schema']));
                $required = $candidate['required'] ?? [];
                if (! is_array($required) || ! array_is_list($required)
                    || array_filter($required, fn ($name) => ! is_string($name)) !== []) {
                    throw new \RuntimeException;
                }
                $fields = [];
                foreach ($candidate['properties'] as $name => $definition) {
                    if (! is_array($definition)) {
                        throw new \RuntimeException;
                    }
                    $unknown += count(array_diff(array_keys($definition), ['type', 'pattern', 'minLength', 'maxLength', 'enum']));
                    $fields[] = ['name' => $name, 'required' => in_array($name, $required, true),
                        'type' => $definition['type'] ?? null, 'pattern' => $definition['pattern'] ?? null,
                        'min_length' => $definition['minLength'] ?? null, 'max_length' => $definition['maxLength'] ?? null,
                        'allowed_values' => $definition['enum'] ?? null];
                }
                if (array_diff($required, array_keys($candidate['properties'])) !== []) {
                    return $this->result([], $unknown + 1, [], [], 'UNKNOWN', 'required_definition_missing');
                }
            } elseif (is_array($candidate['fields'] ?? null) && array_is_list($candidate['fields'])) {
                $unknown += count(array_diff(array_keys($candidate), ['fields']));
                $fields = [];
                foreach ($candidate['fields'] as $field) {
                    if (! is_array($field)) {
                        throw new \RuntimeException;
                    }
                    $unknown += count(array_diff(array_keys($field), ['fieldName', 'required', 'type', 'pattern', 'minLength', 'maxLength', 'allowedValues']));
                    $fields[] = ['name' => $field['fieldName'] ?? null, 'required' => $field['required'] ?? null,
                        'type' => $field['type'] ?? null, 'pattern' => $field['pattern'] ?? null,
                        'min_length' => $field['minLength'] ?? null, 'max_length' => $field['maxLength'] ?? null,
                        'allowed_values' => $field['allowedValues'] ?? null];
                }
            } else {
                return $this->result([], $unknown, [], [], 'UNKNOWN', 'beneficiary_schema_unrecognized');
            }
            if (count($fields) > 200) {
                throw new \RuntimeException;
            }
            $definitions = [];
            foreach ($fields as $field) {
                $name = $field['name'] ?? null;
                if (! in_array($name, self::FIELD_NAMES, true)) {
                    $unknown++;
                    continue;
                }
                if (! is_bool($field['required'] ?? null) || ! in_array($field['type'] ?? null, ['string', 'boolean', 'integer', 'number'], true)) {
                    throw new \RuntimeException;
                }
                $definition = ['field' => $name, 'required' => $field['required'], 'type' => $field['type']];
                foreach (['min_length', 'max_length'] as $constraint) {
                    if (isset($field[$constraint])) {
                        if (! is_int($field[$constraint]) || $field[$constraint] < 0 || $field[$constraint] > 4096) {
                            throw new \RuntimeException;
                        }
                        $definition[$constraint] = $field[$constraint];
                    }
                }
                if (isset($definition['min_length'], $definition['max_length'])
                    && $definition['min_length'] > $definition['max_length']) {
                    throw new \RuntimeException;
                }
                if (isset($field['pattern'])) {
                    if (is_string($field['pattern']) && strlen($field['pattern']) <= 512
                        && in_array($field['pattern'], $scope->verifiedPatterns, true)) {
                        $definition['pattern'] = $field['pattern'];
                    } else {
                        $definition['pattern_status'] = 'UNKNOWN';
                        $unknown++;
                    }
                }
                if (isset($field['allowed_values'])) {
                    $allowed = match ($name) {
                        'beneficiaryAccountType', 'customerType' => ['INDIVIDUAL', 'CORPORATE', 'Individual', 'Corporate'],
                        'payoutMethod' => self::METHODS,
                        'routingCodeType1', 'routingCodeType2' => self::ROUTING,
                        'destinationCountry', 'destinationCurrency', 'clientLegalEntity' => [$filters[$name] ?? $scope->region],
                        default => null,
                    };
                    if ($allowed !== null && is_array($field['allowed_values']) && array_is_list($field['allowed_values'])
                        && array_filter($field['allowed_values'], fn ($value) => ! in_array($value, $allowed, true)) === []) {
                        $definition['allowed_values'] = $field['allowed_values'];
                    } else {
                        $definition['allowed_values_status'] = 'UNKNOWN';
                        $unknown++;
                    }
                }
                $definitions[] = $definition;
            }

            return $this->result(['definitions' => $definitions], $unknown);
        } catch (Throwable) {
            return $this->result([], $unknown, [], ['schema_constraints'], 'INCOMPLETE');
        }
    }

    private function code(mixed $value, int $length): bool
    {
        return is_string($value) && preg_match('/^[A-Z]{'.$length.'}$/D', $value) === 1;
    }

    private function missing(array $body, array $fields): array
    {
        return array_values(array_diff($fields, array_keys($body)));
    }

    private function result(array $data, int $unknown = 0, array $missing = [], array $invalid = [], ?string $status = null, ?string $reason = null): array
    {
        return ['status' => $invalid !== [] ? 'INCOMPLETE' : ($status ?? ($missing !== [] || $unknown > 0 ? 'UNKNOWN' : 'COMPLETE')),
            'data' => $data, 'unknown_fields' => ['status' => $unknown > 0 ? 'UNKNOWN' : 'NONE', 'omitted_count' => $unknown],
            'missing_fields' => array_values(array_unique($missing)), 'invalid_fields' => array_values(array_unique($invalid)),
            'reason' => $reason];
    }
}
