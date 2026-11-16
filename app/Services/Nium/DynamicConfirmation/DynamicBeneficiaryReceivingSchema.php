<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use App\Services\Nium\{NiumBeneficiaryJsonSchemaNormalizer, NiumBeneficiaryPreflightValidator, NiumBeneficiaryRequirementsResult};
use RuntimeException;

/** Dynamic-only adapter over the existing beneficiary schema compiler/preflight. */
final class DynamicBeneficiaryReceivingSchema
{
    public function __construct(private readonly NiumBeneficiaryPreflightValidator $validator) {}

    public function validate(string $body, array $registered, array $scope, string $observedAt): string
    {
        // The envelope permits the same single-schema JSON forms as beneficiary
        // registration. Preserve object/array types and reject duplicate JSON keys.
        ExactProviderJson::decode('{"document":'.$body.'}');
        $document = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
        if ($document instanceof \stdClass) {
            $envelopes = array_intersect(['data', 'validationSchema', 'content'], array_keys((array) $document));
            if (count($envelopes) > 1) $this->refuse();
            if ($envelopes !== []) $document = $document->{reset($envelopes)};
        }
        if (is_array($document)) {
            if (count($document) !== 1) $this->refuse();
            $document = $document[0];
        }
        if (! $document instanceof \stdClass) $this->refuse();
        $dimensions = $scope['schema_query'];
        $routingTypes = $dimensions['routingCodeType'];
        unset($dimensions['routingCodeType']);
        // Actual receiving inputs specialize conditions (including bank account
        // type/routing). Missing facts and unsupported semantics are never guessed.
        $facts = $dimensions;
        foreach (['beneficiaryBankAccountType', 'routingCodeType1', 'routingCodeType2'] as $name) {
            if (is_string($registered[$name] ?? null)) $facts[$name] = $registered[$name];
        }
        $fields = (new NiumBeneficiaryJsonSchemaNormalizer)->fields($document, $facts,
            $routingTypes, $registered['clientLegalEntity']);
        $byName = array_column($fields, null, 'name');
        foreach (['beneficiaryName', 'beneficiaryAccountNumber'] as $name) {
            if (($byName[$name]['type'] ?? null) !== 'string') $this->refuse();
        }
        $schema = NiumBeneficiaryRequirementsResult::trusted($dimensions, $routingTypes, $fields,
            $observedAt, 'nium-dynamic-beneficiary-schema-v1');
        $this->validator->validate([...$registered, 'customerType' => $dimensions['customerType']],
            [...$dimensions, 'routingCodeType' => $routingTypes], $schema);

        return hash('sha256', $body);
    }

    private function refuse(): never
    {
        throw new RuntimeException('dynamic_registered_account_customer_scope_unavailable');
    }
}
