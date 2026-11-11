<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use App\Models\Beneficiary;
use App\Models\NiumDynamicPayoutQuote;
use App\Models\User;
use App\Services\Nium\NiumEntitlementEvidenceTransport;
use App\Services\Nium\NiumService;
use App\Domain\Nium\DynamicMultiCurrency\Financial\Decimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Runtime customer-scoped reads. No operator audit-collector configuration or catalogue promotion. */
final class DynamicPayoutEligibility
{
    public function __construct(private readonly NiumService $nium) {}

    public function observe(User $user, Beneficiary $beneficiary, NiumDynamicPayoutQuote $quote,
        string $legalEntity, array $amounts): array
    {
        if (DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
            throw new RuntimeException('dynamic_eligibility_network_requires_no_transaction');
        }
        $binding = $quote->binding;
        $started = now();
        $bodies = [];
        foreach (['customer_corridors', 'payout_schema'] as $endpoint) {
            $this->assertScope($binding);
            $path = $this->nium->path(NiumEntitlementEvidenceTransport::ENDPOINTS[$endpoint],
                ['client' => $binding['client'], 'customer' => $binding['customer'], 'currencyCode' => $beneficiary->currency]);
            $response = $this->nium->get(path: $path,
                query: $endpoint === 'payout_schema' ? ['destinationCountry' => $beneficiary->country_code, 'payoutMethod' => 'LOCAL'] : [],
                user: $user, operation: 'dynamic_'.$endpoint, externalReference: $quote->id);
            $this->assertScope($binding);
            if (! $response->successful() || strlen($response->body()) > 1048576
                || $started->copy()->addSeconds(30)->isPast()) {
                throw new RuntimeException('dynamic_'.$endpoint.'_unavailable');
            }
            $bodies[$endpoint] = $response->body();
        }
        try {
            $body = ExactProviderJson::decode($bodies['customer_corridors']);
        } catch (\Throwable $exception) {
            throw new RuntimeException('dynamic_customer_corridor_response_contract_unavailable', previous: $exception);
        }
        foreach (['clientHashId' => 'client', 'customerHashId' => 'customer'] as $field => $key) {
            if (isset($body[$field]) && $body[$field] !== $binding[$key]) throw new RuntimeException('dynamic_eligibility_scope_changed');
        }
        $selected = self::select($body, $beneficiary->country_code, $beneficiary->currency, $legalEntity);
        try {
            // OpenAPI permits a JSON-schema object encoded as a JSON string.
            $schemaBody = json_decode($bodies['payout_schema'], true, 64, JSON_THROW_ON_ERROR);
            $schema = ExactProviderJson::decode(is_string($schemaBody) ? $schemaBody : $bodies['payout_schema']);
        } catch (\Throwable $exception) {
            throw new RuntimeException('dynamic_payout_schema_contract_unavailable', previous: $exception);
        }
        $scales = self::scales($schema, $beneficiary->currency);
        foreach (['USD' => $amounts['quoted_principal']['amount'], $beneficiary->currency => $amounts['quoted_destination_amount']['amount']] as $currency => $amount) {
            if (! (new Decimal($amount))->fitsScale($scales[$currency])) {
                throw new RuntimeException('dynamic_payout_amount_precision_unsupported');
            }
        }
        return ['version' => 'nium-customer-payout-oas-example-v1',
            'selection' => $selected, 'currency_scales' => $scales,
            'customer_corridors_sha256' => hash('sha256', $bodies['customer_corridors']),
            'payout_schema_sha256' => hash('sha256', $bodies['payout_schema']),
            'observed_at' => now()->toISOString(), 'expires_at' => $started->copy()->addSeconds(30)->toISOString(),
            'scope' => array_intersect_key($binding, array_flip(['base_url', 'client', 'customer', 'wallet']))];
    }

    /** Documented 200 example: data[], snake_case tuple fields and legal-entity/country arrays. */
    public static function select(array $body, string $country, string $currency, string $entity): array
    {
        if (! is_array($body['data'] ?? null) || ! array_is_list($body['data'])) {
            throw new RuntimeException('dynamic_customer_corridor_response_contract_unavailable');
        }
        $matches = [];
        foreach ($body['data'] as $row) {
            if (! is_array($row) || ($row['destination_currency'] ?? null) !== $currency
                || ($row['payout_method'] ?? null) !== 'LOCAL') continue;
            if (! is_array($row['destination_country'] ?? null) || ! array_is_list($row['destination_country'])
                || ! is_array($row['client_legal_entity'] ?? null) || ! array_is_list($row['client_legal_entity'])) {
                throw new RuntimeException('dynamic_customer_corridor_response_contract_unavailable');
            }
            if (in_array($country, $row['destination_country'], true)
                && in_array('LEGAL_ENTITY_'.$entity, $row['client_legal_entity'], true)) {
                // No undocumented interpretation of lookup-bypass flags.
                if (($row['ignoreLookup'] ?? null) !== false || ! is_string($row['routing_code_type'] ?? null)) {
                    throw new RuntimeException('dynamic_customer_corridor_response_contract_unavailable');
                }
                $matches[] = ['legal_entity' => $entity, 'destination_country' => $country,
                    'destination_currency' => $currency, 'payout_method' => 'LOCAL', 'routing_code_type' => $row['routing_code_type']];
            }
        }
        if (count($matches) !== 1) {
            throw new RuntimeException(count($matches) === 0 ? 'dynamic_customer_corridor_not_permitted' : 'dynamic_customer_corridor_ambiguous');
        }
        return $matches[0];
    }

    public static function scales(array $schema, string $currency): array
    {
        if (($schema['type'] ?? null) !== 'object' || ! is_array($schema['properties'] ?? null)
            || ($schema['$schema'] ?? null) !== 'http://json-schema.org/draft-07/schema#') {
            throw new RuntimeException('dynamic_payout_schema_contract_unavailable');
        }
        // Both remittance amount fields have a documented maximum of two decimals.
        // A customer schema can narrow it. All other conditions are enforced by
        // Nium's /remittance/validate using the actual body, not a partial local validator.
        $scales = ['USD' => 2, $currency => 2];
        foreach (['payout.sourceAmount' => 'USD', 'payout.destinationAmount' => $currency] as $name => $code) {
            $property = $schema['properties'][$name] ?? null;
            if ($property === null) continue;
            if (! is_array($property) || ($property['type'] ?? null) !== 'number'
                || (isset($property['maximumDecimals']) && ! in_array($property['maximumDecimals'], ['0', '1', '2'], true))) {
                throw new RuntimeException('dynamic_payout_schema_contract_unavailable');
            }
            $scales[$code] = isset($property['maximumDecimals']) ? (int) $property['maximumDecimals'] : 2;
        }
        return $scales;
    }

    private function assertScope(array $binding): void
    {
        if (! in_array($binding['base_url'], ['https://gatewaysandbox.nium.com', 'https://gateway.nium.com'], true)
            || rtrim((string) config('services.nium.base_url'), '/') !== $binding['base_url']
            || $this->nium->clientId() !== $binding['client']) {
            throw new RuntimeException('dynamic_eligibility_scope_changed');
        }
    }
}
