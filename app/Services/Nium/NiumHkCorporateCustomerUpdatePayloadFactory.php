<?php

namespace App\Services\Nium;

use App\Models\KycProfileCorrection;
use App\Models\User;
use Illuminate\Support\Arr;
use RuntimeException;

class NiumHkCorporateCustomerUpdatePayloadFactory
{
    public function __construct(private readonly NiumCustomerPayloadFactory $createPayloadFactory) {}

    public function build(User $customer, KycProfileCorrection $correction): array
    {
        $account = $correction->providerAccount;
        $payload = $this->createPayloadFactory->build($customer, (string) $account->external_reference);

        if (($payload['type'] ?? null) !== 'corporate'
            || ($payload['region'] ?? null) !== 'HK'
            || ($payload['kycType'] ?? null) !== 'full') {
            throw new RuntimeException('Customer Update is limited to Corporate HK Full KYC.');
        }

        $proposed = (array) $correction->proposed_values;
        $payload['applicant']['firstName'] = $this->required($proposed, 'first_name');
        $payload['applicant']['lastName'] = $this->required($proposed, 'last_name');
        $payload['applicant']['dateOfBirth'] = $this->required($proposed, 'date_of_birth');

        $bindings = $this->bindings((array) $account->metadata);
        $applicantExternalId = trim((string) $correction->applicant_external_id);
        if ($applicantExternalId === ''
            || ! hash_equals($correction->applicant_external_fingerprint, hash('sha256', $applicantExternalId))) {
            throw new RuntimeException('The immutable applicant externalId binding is missing or invalid.');
        }
        $payload['applicant']['externalId'] = $applicantExternalId;
        $this->bindReference($payload['applicant'], $bindings, (string) $payload['applicant']['externalId']);
        if (! hash_equals((string) $correction->applicant_reference_id, (string) $payload['applicant']['referenceId'])) {
            throw new RuntimeException('The applicant externalId and referenceId evidence does not match the approved correction.');
        }

        foreach ($payload['stakeholders']['individual'] ?? [] as $index => &$stakeholder) {
            $this->bindReference($stakeholder, $bindings, (string) ($stakeholder['externalId'] ?? ''));
        }
        unset($stakeholder);

        return $payload;
    }

    private function bindings(array $metadata): array
    {
        $bindings = [];
        foreach ((array) Arr::get($metadata, 'nium_entity_kyc_states', []) as $state) {
            if (! is_array($state)) {
                continue;
            }
            $externalId = trim((string) ($state['external_id'] ?? ''));
            $referenceId = trim((string) ($state['provider_reference_id'] ?? ''));
            if ($externalId === '' || $referenceId === '') {
                continue;
            }
            $bindings[$externalId][] = $referenceId;
        }

        return $bindings;
    }

    private function bindReference(array &$person, array $bindings, string $externalId): void
    {
        $matches = array_values(array_unique($bindings[$externalId] ?? []));
        if ($externalId === '' || count($matches) !== 1) {
            throw new RuntimeException('A unique verified Nium referenceId is required for every applicant and individual stakeholder.');
        }
        $person['referenceId'] = $matches[0];
    }

    private function required(array $values, string $key): string
    {
        $value = trim((string) ($values[$key] ?? ''));
        if ($value === '') {
            throw new RuntimeException("Approved correction is missing {$key}.");
        }

        return $value;
    }
}
