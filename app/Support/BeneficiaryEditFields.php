<?php

namespace App\Support;

use App\Models\Beneficiary;
use Illuminate\Support\Arr;

/** Customer-owned form values only; never serialize provider requests or evidence. */
final class BeneficiaryEditFields
{
    // Explicitly reviewed beneficiary inputs, not a dynamic provider-metadata bag.
    private const ADDITIONAL_FIELDS = [
        'beneficiaryAlias' => ['beneficiaryAlias', 'beneficiary_alias', 'beneficiary.alias'],
        'beneficiaryIdentificationType' => ['beneficiaryIdentificationType', 'beneficiary_identification_type'],
        'beneficiaryIdentificationValue' => ['beneficiaryIdentificationValue', 'beneficiary_identification_value'],
        'beneficiaryContactName' => ['beneficiaryContactName', 'beneficiary_contact_name'],
        'beneficiaryEntityType' => ['beneficiaryEntityType', 'beneficiary_entity_type'],
        'beneficiaryDob' => ['beneficiaryDob', 'beneficiary_dob'],
        'beneficiaryEstablishmentDate' => ['beneficiaryEstablishmentDate', 'beneficiary_establishment_date'],
        'beneficiaryName_local' => ['beneficiaryName_local', 'beneficiary_name_local'],
        'beneficiaryBankCode' => [],
        'beneficiaryBranchCode' => [],
    ];

    public static function forBeneficiary(Beneficiary $beneficiary): array
    {
        $raw = (array) ($beneficiary->raw_data ?? []);
        $result = [];
        foreach ([
            'bank_account_type' => ['nium.beneficiaryBankAccountType', 'nium.bankAccountType'],
            'contact_country_code' => ['nium.beneficiaryContactCountryCode', 'nium.beneficiary_contact_country_code', 'origin.phone_country_code'],
            'relationship' => ['nium.remitterBeneficiaryRelationship', 'nium.remitter_beneficiary_relationship', 'nium.beneficiary.remitterBeneficiaryRelationship', 'origin.vendor_type'],
        ] as $field => $paths) {
            self::copyString($result, $field, $raw, $paths);
        }
        $route = Arr::get($raw, 'origin.account_route');
        if (in_array($route, ['bank', 'provider'], true)) {
            $result['account_route'] = $route;
        }
        $verify = Arr::get($raw, 'nium.verify_before_create');
        if (is_bool($verify)) {
            $result['verify_before_create'] = $verify;
        }

        $routing = Arr::get($raw, 'nium.routingInfo') ?? Arr::get($raw, 'nium.routing_info');
        if (is_array($routing) && $routing !== []) {
            $safeRouting = [];
            foreach (array_slice($routing, 0, 2) as $entry) {
                if (is_array($entry) && is_string($entry['type'] ?? null) && is_string($entry['value'] ?? null)) {
                    $safeRouting[] = ['type' => $entry['type'], 'value' => $entry['value']];
                }
            }
            $result['routing'] = $safeRouting;
        } else {
            // Legacy columns are customer bank details; do not infer a routing type.
            $routing = [];
            foreach (['swift_bic' => 'SWIFT', 'bank_code' => Arr::get($raw, 'nium.bankCodeType'), 'branch_code' => Arr::get($raw, 'nium.branchCodeType')] as $column => $type) {
                if (is_string($type) && filled($type) && is_string($beneficiary->{$column}) && filled($beneficiary->{$column})) {
                    $routing[] = ['type' => $type, 'value' => $beneficiary->{$column}];
                }
            }
            if ($routing !== []) {
                $result['routing'] = array_slice($routing, 0, 2);
            }
        }

        $additional = [];
        foreach (self::ADDITIONAL_FIELDS as $name => $aliases) {
            self::copyString($additional, $name, $raw, ["nium.additionalFields.{$name}", ...array_map(fn ($alias) => "nium.{$alias}", $aliases)]);
        }
        if ($additional !== []) {
            $result['additional_fields'] = $additional;
        }

        return $result;
    }

    /** Partial customer updates preserve omitted values and all operational metadata. */
    public static function mergeUpdate(Beneficiary $beneficiary, array $patch, ?string $authoritativeMethod = null): array
    {
        $raw = (array) ($beneficiary->raw_data ?? []);
        foreach (['account_route', 'vendor_type', 'phone_country_code', 'transaction_document_name'] as $name) {
            if (Arr::has($patch, "origin.{$name}")) {
                Arr::set($raw, "origin.{$name}", Arr::get($patch, "origin.{$name}"));
            }
        }
        foreach ([
            'payoutMethod', 'bankAccountType', 'beneficiaryBankAccountType', 'beneficiaryContactCountryCode',
            'remitterBeneficiaryRelationship', 'bankCodeType', 'branchCodeType', 'routingInfo', 'verify_before_create',
        ] as $name) {
            if (Arr::has($patch, "nium.{$name}")) {
                // Arrays such as routingInfo replace the complete list, never merge by index.
                Arr::set($raw, "nium.{$name}", Arr::get($patch, "nium.{$name}"));
            }
        }
        foreach ([
            'beneficiaryBankAccountType' => ['bankAccountType'],
            'bankAccountType' => ['beneficiaryBankAccountType'],
            'beneficiaryContactCountryCode' => ['beneficiary_contact_country_code'],
            'remitterBeneficiaryRelationship' => ['remitter_beneficiary_relationship', 'beneficiary.remitterBeneficiaryRelationship'],
            'payoutMethod' => ['payout_method'],
            'routingInfo' => ['routing_info'],
        ] as $name => $aliases) {
            if (Arr::has($patch, "nium.{$name}")) {
                foreach ($aliases as $alias) {
                    if (Arr::has($raw, "nium.{$alias}")) {
                        Arr::set($raw, "nium.{$alias}", Arr::get($patch, "nium.{$name}"));
                    }
                }
            }
        }
        foreach (['payoutMethod', 'bankAccountType', 'proxyType', 'proxyValue', 'routingInfo'] as $name) {
            if (Arr::has($patch, "nium.account_verification.{$name}")) {
                Arr::set($raw, "nium.account_verification.{$name}", Arr::get($patch, "nium.account_verification.{$name}"));
            }
        }
        foreach ((array) Arr::get($patch, 'nium.additionalFields', []) as $name => $value) {
            $aliases = self::ADDITIONAL_FIELDS[$name] ?? [];
            $direct = array_filter($aliases, fn ($alias) => Arr::has($raw, "nium.{$alias}"));
            if ($direct !== []) {
                // Older beneficiaries store these inputs directly. Avoid sending the
                // same field twice through the existing provider payload builder.
                foreach ($direct as $alias) {
                    Arr::set($raw, "nium.{$alias}", $value);
                }
                Arr::forget($raw, "nium.additionalFields.{$name}");
            } else {
                Arr::set($raw, "nium.additionalFields.{$name}", $value);
            }
        }
        $method = $authoritativeMethod ?? $beneficiary->payout_method;
        if (! filled(Arr::get($raw, 'nium.payoutMethod') ?? Arr::get($raw, 'nium.payout_method')) && filled($method)) {
            Arr::set($raw, 'nium.payoutMethod', $method);
        }
        if (! Arr::has($raw, 'nium.beneficiaryBankAccountType') && is_string(Arr::get($raw, 'nium.bankAccountType'))) {
            Arr::set($raw, 'nium.beneficiaryBankAccountType', Arr::get($raw, 'nium.bankAccountType'));
        }

        return $raw;
    }

    private static function copyString(array &$result, string $key, array $raw, array $paths): void
    {
        foreach ($paths as $path) {
            $value = Arr::get($raw, $path);
            if (is_string($value)) {
                $result[$key] = $value;

                return;
            }
        }
    }
}
