<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use RuntimeException;

/** Identity approval is separate from API-contract and account-entitlement evidence. */
final readonly class DynamicPayoutEnvironment
{
    private function __construct(public string $origin, public string $identity) {}

    public static function resolve(string $baseUrl): self
    {
        // Only a single trailing slash is normalized. Credentials, ports, paths,
        // queries, fragments, HTTP and look-alike hosts are never approved origins.
        $origin = str_ends_with($baseUrl, '/') ? substr($baseUrl, 0, -1) : $baseUrl;
        $identity = match ($origin) {
            'https://gateway.nium.com', 'https://api.spend.nium.com' => 'production',
            'https://gatewaysandbox.nium.com' => 'sandbox',
            default => throw new RuntimeException('dynamic_provider_environment_unverified'),
        };

        return new self($origin, $identity);
    }

    public static function configured(): self
    {
        return self::resolve((string) config('services.nium.base_url'));
    }

    public function assertBinding(string $origin, ?string $identity = null): void
    {
        $bound = self::resolve($origin);
        if ($bound->origin !== $this->origin || ($identity !== null && $identity !== $this->identity)) {
            throw new RuntimeException('dynamic_provider_scope_unverified');
        }
    }

    public function assertContract(string $operation): void
    {
        if (! in_array($operation, ['quote', 'customer_corridors', 'payout_schema', 'wallet', 'validation', 'remittance', 'transactions'], true)) {
            throw new RuntimeException('dynamic_provider_contract_unverified');
        }
        if ($this->origin === 'https://api.spend.nium.com') {
            // STEP 541: observed exact V1 Wallet GET/root-array contract, with
            // operator-confirmed balance-as-available semantics. Wallet only.
            if ($operation === 'wallet') {
                return;
            }
            // Authenticated Spend V1 GET evidence establishes the paginated
            // Transactions read dialect, not fees, settlement or payout authority.
            if ($operation === 'transactions') {
                return;
            }
            // STEP 497: retained HK successes, the legacy submitter and the official
            // remittance schema establish this shared POST method/path. Require the
            // legacy endpoint to match Dynamic's fixed path; a custom route is not
            // evidence for it. This grants no corridor, payload or settlement authority.
            if ($operation === 'remittance' && config('services.nium.transfer_endpoint')
                === '/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/remittance') {
                return;
            }
            // No blanket authority for these operations. Endpoint-scoped reads
            // and non-settling validation apply their own exact response checks;
            // they never open schema, pricing or payout authority globally.
            throw new RuntimeException('dynamic_production_'.$operation.'_contract_unverified');
        }
    }

    /** Read compatibility does not prove complete fee accounting or authorize dispatch. */
    public function assertTransactionsReadContract(): void
    {
        $this->assertContract('transactions');
    }

    public function assertTransactionsReadEndpoint(string $path, string $client, string $customer, string $wallet): void
    {
        $this->assertTransactionsReadContract();
        if ($client === '' || $customer === '' || $wallet === ''
            || $path !== '/api/v1/client/'.urlencode($client).'/customer/'.urlencode($customer)
                .'/wallet/'.urlencode($wallet).'/transactions') {
            throw new RuntimeException('dynamic_transactions_endpoint_unverified');
        }
    }

    /** Endpoint evidence permits reference acquisition, never an assumed response or payout contract. */
    public function assertQuoteReferenceEndpoint(string $path, string $client): void
    {
        // STEP 586: Spend V1 /quotes endpoint verified. Existing strict response
        // validation and disabled-by-default Quote feature gate remain independent.
        if ($client === '' || $path !== '/api/v1/client/'.urlencode($client).'/quotes') {
            throw new RuntimeException('dynamic_quote_endpoint_unverified');
        }
    }

    /** STEP 602: the customer listing GET is evidenced, payout authorization is not. */
    public function assertCustomerCorridorsReadEndpoint(string $path, string $client, string $customer): void
    {
        if ($client === '' || $customer === ''
            || $path !== '/api/v1/client/'.urlencode($client).'/customer/'.urlencode($customer).'/supportedCorridors') {
            throw new RuntimeException('dynamic_customer_corridors_endpoint_unverified');
        }
    }

    /** Documented non-settling POST only; its response grants no payout or settlement authority. */
    public function assertInstructionValidationEndpoint(string $path, string $client, string $customer, string $wallet): void
    {
        if ($client === '' || $customer === '' || $wallet === ''
            || $path !== '/api/v1/client/'.urlencode($client).'/customer/'.urlencode($customer)
                .'/wallet/'.urlencode($wallet).'/remittance/validate') {
            throw new RuntimeException('dynamic_instruction_validation_endpoint_unverified');
        }
    }

    /** Read dialect evidence only; this never authorizes an amount mode or instruction. */
    public function payoutSchemaDialect(string $path, array $query, string $client, string $customer, string $currency): string
    {
        if ($client === '' || $customer === '' || preg_match('/^[A-Z]{3}$/D', $currency) !== 1
            || $path !== '/api/v1/client/'.urlencode($client).'/customer/'.urlencode($customer).'/currency/'.$currency.'/payout/schema'
            || count($query) !== 2 || ! is_string($query['destinationCountry'] ?? null)
            || preg_match('/^[A-Z]{2}$/D', $query['destinationCountry']) !== 1
            || ! is_string($query['payoutMethod'] ?? null)
            || preg_match('/^[A-Z][A-Z0-9_]*$/D', $query['payoutMethod']) !== 1) {
            throw new RuntimeException('dynamic_payout_schema_endpoint_unverified');
        }
        if ($this->origin === 'https://api.spend.nium.com') {
            // STEP 607: only the observed GBP/GB/LOCAL root-object dialect.
            if ($currency !== 'GBP' || $query['destinationCountry'] !== 'GB' || $query['payoutMethod'] !== 'LOCAL') {
                throw new RuntimeException('dynamic_payout_schema_endpoint_unverified');
            }
            return 'https://json-schema.org/draft/2020-12/schema';
        }

        $this->assertContract('payout_schema');
        return 'http://json-schema.org/draft-07/schema#';
    }
}
