<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Beneficiary;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutEnvironment;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutFinancialAuthorization;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutGate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class NiumDynamicPayoutEnvironmentTest extends TestCase
{
    public static function approvedOrigins(): array
    {
        return [
            ['https://api.spend.nium.com', 'production'],
            ['https://gateway.nium.com', 'production'],
            ['https://gatewaysandbox.nium.com', 'sandbox'],
        ];
    }

    #[DataProvider('approvedOrigins')]
    public function test_exact_origin_has_one_consistent_environment_identity(string $origin, string $identity): void
    {
        config(['services.nium.base_url' => $origin.'/']);
        $resolved = DynamicPayoutEnvironment::configured();
        $this->assertSame($identity, $resolved->identity);
        $this->assertSame($origin, $resolved->origin);
        $resolved->assertBinding($origin, $identity);
    }

    public static function unsupportedOrigins(): array
    {
        return array_map(static fn ($origin) => [$origin], [
            '', 'https://unknown.nium.com', 'http://gateway.nium.com', 'http://api.spend.nium.com',
            'https://gateway.nium.com:443', 'https://api.spend.nium.com/api',
            'https://gateway.nium.com?environment=sandbox', 'https://gateway.nium.com#sandbox',
            'https://user@gateway.nium.com', 'https://gateway.nium.com.attacker.invalid',
            ' https://api.spend.nium.com', 'https://api.spend.nium.com//',
        ]);
    }

    #[DataProvider('approvedOrigins')]
    public function test_quote_reference_endpoint_acceptance_preserves_resolved_identity(string $origin, string $identity): void
    {
        $environment = DynamicPayoutEnvironment::resolve($origin);
        $environment->assertQuoteReferenceEndpoint('/api/v1/client/fixture-client/quotes', 'fixture-client');
        $this->assertSame($identity, $environment->identity);
    }

    public static function unverifiedQuotePaths(): array
    {
        return [
            ['/custom/quotes', 'fixture-client'],
            ['/api/v2/client/fixture-client/quotes', 'fixture-client'],
            ['/api/v1/client/fixture-client/quotes/', 'fixture-client'],
            ['/api/v1/client/fixture-client/quotes?mode=manual', 'fixture-client'],
            ['https://api.spend.nium.com/api/v1/client/fixture-client/quotes', 'fixture-client'],
            ['/api/v1/client/foreign-client/quotes', 'fixture-client'],
            ['/api/v1/client//quotes', ''],
        ];
    }

    #[DataProvider('unverifiedQuotePaths')]
    public function test_reference_endpoint_evidence_is_scoped_to_exact_path_and_client(string $path, string $client): void
    {
        $this->expectExceptionMessage('dynamic_quote_endpoint_unverified');
        DynamicPayoutEnvironment::resolve('https://api.spend.nium.com')->assertQuoteReferenceEndpoint($path, $client);
    }

    public function test_quote_endpoint_evidence_never_opens_the_full_spend_quote_contract(): void
    {
        $environment = DynamicPayoutEnvironment::resolve('https://api.spend.nium.com');
        $environment->assertQuoteReferenceEndpoint('/api/v1/client/fixture-client/quotes', 'fixture-client');
        $this->expectExceptionMessage('dynamic_production_quote_contract_unverified');
        $environment->assertContract('quote');
    }

    #[DataProvider('approvedOrigins')]
    public function test_documented_validation_endpoint_is_separate_from_payout_authority(string $origin, string $identity): void
    {
        $environment = DynamicPayoutEnvironment::resolve($origin);
        $environment->assertInstructionValidationEndpoint(
            '/api/v1/client/fixture-client/customer/fixture-customer/wallet/fixture-wallet/remittance/validate',
            'fixture-client', 'fixture-customer', 'fixture-wallet');
        $this->assertSame($identity, $environment->identity);
    }

    public static function invalidValidationEndpoints(): array
    {
        return [['remittance'], ['remittance/validate/'], ['remittance/validate?extra=true'],
            ['transactions'], ['foreign-customer/wallet/fixture-wallet/remittance/validate']];
    }

    #[DataProvider('invalidValidationEndpoints')]
    public function test_validation_endpoint_cannot_be_replaced_by_a_payout_or_other_route(string $suffix): void
    {
        $this->expectExceptionMessage('dynamic_instruction_validation_endpoint_unverified');
        DynamicPayoutEnvironment::resolve('https://api.spend.nium.com')->assertInstructionValidationEndpoint(
            '/api/v1/client/fixture-client/customer/fixture-customer/wallet/fixture-wallet/'.$suffix,
            'fixture-client', 'fixture-customer', 'fixture-wallet');
    }

    #[DataProvider('approvedOrigins')]
    public function test_customer_corridor_read_is_scoped_to_exact_client_customer_endpoint(string $origin, string $identity): void
    {
        $environment = DynamicPayoutEnvironment::resolve($origin);
        $environment->assertCustomerCorridorsReadEndpoint(
            '/api/v1/client/fixture-client/customer/fixture-customer/supportedCorridors', 'fixture-client', 'fixture-customer');
        $this->assertSame($identity, $environment->identity);
    }

    public static function unverifiedCustomerCorridorPaths(): array
    {
        return [
            ['/api/v3/client/fixture-client/supportedCorridors', 'fixture-client', 'fixture-customer'],
            ['/api/v1/client/foreign-client/customer/fixture-customer/supportedCorridors', 'fixture-client', 'fixture-customer'],
            ['/api/v1/client/fixture-client/customer/foreign-customer/supportedCorridors', 'fixture-client', 'fixture-customer'],
            ['/api/v1/client/fixture-client/customer/fixture-customer/supportedCorridors/', 'fixture-client', 'fixture-customer'],
            ['/api/v1/client/fixture-client/customer/fixture-customer/supportedCorridors?country=GB', 'fixture-client', 'fixture-customer'],
            ['/api/v1/client//customer/fixture-customer/supportedCorridors', '', 'fixture-customer'],
            ['/api/v1/client/fixture-client/customer//supportedCorridors', 'fixture-client', ''],
        ];
    }

    #[DataProvider('unverifiedCustomerCorridorPaths')]
    public function test_customer_corridor_read_refuses_foreign_or_unverified_endpoint(string $path, string $client, string $customer): void
    {
        $this->expectExceptionMessage('dynamic_customer_corridors_endpoint_unverified');
        DynamicPayoutEnvironment::resolve('https://api.spend.nium.com')->assertCustomerCorridorsReadEndpoint($path, $client, $customer);
    }

    public function test_customer_listing_read_does_not_authorize_the_full_spend_corridor_contract(): void
    {
        $environment = DynamicPayoutEnvironment::resolve('https://api.spend.nium.com');
        $environment->assertCustomerCorridorsReadEndpoint(
            '/api/v1/client/fixture-client/customer/fixture-customer/supportedCorridors', 'fixture-client', 'fixture-customer');
        $this->expectExceptionMessage('dynamic_production_customer_corridors_contract_unverified');
        $environment->assertContract('customer_corridors');
    }

    #[DataProvider('approvedOrigins')]
    public function test_schema_dialect_is_bound_to_an_approved_origin_and_exact_endpoint(string $origin, string $identity): void
    {
        $environment = DynamicPayoutEnvironment::resolve($origin);
        $this->assertSame($origin === 'https://api.spend.nium.com'
            ? 'https://json-schema.org/draft/2020-12/schema' : 'http://json-schema.org/draft-07/schema#',
            $environment->payoutSchemaDialect('/api/v1/client/fixture-client/customer/fixture-customer/currency/GBP/payout/schema',
                ['destinationCountry' => 'GB', 'payoutMethod' => 'LOCAL'], 'fixture-client', 'fixture-customer', 'GBP'));
        $this->assertSame($identity, $environment->identity);
    }

    public function test_evidenced_schema_read_does_not_open_spend_payout_authorization(): void
    {
        $environment = DynamicPayoutEnvironment::resolve('https://api.spend.nium.com');
        $environment->payoutSchemaDialect('/api/v1/client/fixture-client/customer/fixture-customer/currency/GBP/payout/schema',
            ['destinationCountry' => 'GB', 'payoutMethod' => 'LOCAL'], 'fixture-client', 'fixture-customer', 'GBP');
        $this->expectExceptionMessage('dynamic_production_payout_schema_contract_unverified');
        $environment->assertContract('payout_schema');
    }

    public function test_spend_schema_read_evidence_does_not_extend_to_other_currency_routes(): void
    {
        $this->expectExceptionMessage('dynamic_payout_schema_endpoint_unverified');
        DynamicPayoutEnvironment::resolve('https://api.spend.nium.com')->payoutSchemaDialect(
            '/api/v1/client/fixture-client/customer/fixture-customer/currency/EUR/payout/schema',
            ['destinationCountry' => 'GB', 'payoutMethod' => 'LOCAL'], 'fixture-client', 'fixture-customer', 'EUR');
    }

    #[DataProvider('unsupportedOrigins')]
    public function test_unknown_or_non_origin_configuration_is_never_sandbox(string $origin): void
    {
        $this->expectExceptionMessage('dynamic_provider_environment_unverified');
        DynamicPayoutEnvironment::resolve($origin);
    }

    public static function foreignBindings(): array
    {
        return [
            ['https://api.spend.nium.com', 'https://gatewaysandbox.nium.com', 'sandbox'],
            ['https://gatewaysandbox.nium.com', 'https://api.spend.nium.com', 'production'],
            ['https://api.spend.nium.com', 'https://gateway.nium.com', 'production'],
            ['https://gateway.nium.com', 'https://api.spend.nium.com', 'production'],
            ['https://api.spend.nium.com', 'https://api.spend.nium.com', 'sandbox'],
        ];
    }

    #[DataProvider('foreignBindings')]
    public function test_environment_or_origin_rebinding_is_rejected(string $current, string $bound, string $identity): void
    {
        $this->expectExceptionMessage('dynamic_provider_scope_unverified');
        DynamicPayoutEnvironment::resolve($current)->assertBinding($bound, $identity);
    }

    public static function operations(): array
    {
        return array_map(static fn ($operation) => [$operation],
            ['quote', 'customer_corridors', 'payout_schema', 'wallet', 'validation', 'remittance', 'transactions']);
    }

    public static function unverifiedSpendOperations(): array
    {
        return array_values(array_filter(self::operations(), static fn ($row) => ! in_array($row[0], ['remittance', 'wallet', 'transactions'], true)));
    }

    public function test_spend_wallet_contract_does_not_depend_on_or_authorize_remittance_configuration(): void
    {
        config(['services.nium.transfer_endpoint' => '/unverified/remittance']);
        $environment = DynamicPayoutEnvironment::resolve('https://api.spend.nium.com');
        $environment->assertContract('wallet');
        $this->expectExceptionMessage('dynamic_production_remittance_contract_unverified');
        $environment->assertContract('remittance');
    }

    public function test_spend_transactions_contract_and_read_guard_accept_the_same_verified_dialect(): void
    {
        $environment = DynamicPayoutEnvironment::resolve('https://api.spend.nium.com');
        $environment->assertTransactionsReadContract();
        $environment->assertContract('transactions');
        $environment->assertTransactionsReadEndpoint(
            '/api/v1/client/client/customer/customer/wallet/wallet/transactions', 'client', 'customer', 'wallet');
        $this->assertSame('production', $environment->identity);
    }

    public static function unverifiedTransactionsEndpoints(): array
    {
        return array_map(static fn (string $path): array => [$path], [
            '/api/v2/client/client/customer/customer/wallet/wallet/transactions',
            '/api/v1/client/foreign/customer/customer/wallet/wallet/transactions',
            '/api/v1/client/client/customer/foreign/wallet/wallet/transactions',
            '/api/v1/client/client/customer/customer/wallet/foreign/transactions',
            '/api/v1/client/client/customer/customer/wallet/wallet/transactions/',
            '/api/v1/client/client/customer/customer/wallet/wallet/transactions?externalId=operation',
            'https://api.spend.nium.com/api/v1/client/client/customer/customer/wallet/wallet/transactions',
        ]);
    }

    #[DataProvider('unverifiedTransactionsEndpoints')]
    public function test_transactions_evidence_never_authorizes_a_different_endpoint_or_scope(string $path): void
    {
        $this->expectExceptionMessage('dynamic_transactions_endpoint_unverified');
        DynamicPayoutEnvironment::resolve('https://api.spend.nium.com')->assertTransactionsReadEndpoint(
            $path, 'client', 'customer', 'wallet');
    }

    #[DataProvider('unverifiedSpendOperations')]
    public function test_production_host_identity_never_invents_an_api_contract(string $operation): void
    {
        config(['services.nium.transfer_endpoint' => '/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/remittance']);
        $this->expectExceptionMessage('dynamic_production_'.$operation.'_contract_unverified');
        DynamicPayoutEnvironment::resolve('https://api.spend.nium.com')->assertContract($operation);
    }

    public function test_spend_remittance_uses_only_the_evidenced_shared_legacy_endpoint(): void
    {
        config(['services.nium.transfer_endpoint' => '/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/remittance']);
        $environment = DynamicPayoutEnvironment::resolve('https://api.spend.nium.com');
        $environment->assertContract('remittance');
        $environment->assertBinding('https://api.spend.nium.com', 'production');
        $this->assertSame('production', $environment->identity);
    }

    public static function unverifiedLegacyRemittanceEndpoints(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'custom' => ['/custom/remittance'],
            'other version' => ['/api/v2/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/remittance'],
            'absolute URL' => ['https://api.spend.nium.com/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/remittance'],
            'trailing slash' => ['/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/remittance/'],
        ];
    }

    #[DataProvider('unverifiedLegacyRemittanceEndpoints')]
    public function test_spend_remittance_refuses_unverified_legacy_endpoint_configuration(?string $endpoint): void
    {
        config(['services.nium.transfer_endpoint' => $endpoint]);
        $this->expectExceptionMessage('dynamic_production_remittance_contract_unverified');
        DynamicPayoutEnvironment::resolve('https://api.spend.nium.com')->assertContract('remittance');
    }

    public function test_shared_remittance_evidence_does_not_authorize_unknown_operations(): void
    {
        config(['services.nium.transfer_endpoint' => '/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/remittance']);
        $this->expectExceptionMessage('dynamic_provider_contract_unverified');
        DynamicPayoutEnvironment::resolve('https://api.spend.nium.com')->assertContract('unknown');
    }

    #[DataProvider('operations')]
    public function test_existing_documented_gateway_contracts_remain_available(string $operation): void
    {
        foreach (['https://gateway.nium.com', 'https://gatewaysandbox.nium.com'] as $origin) {
            DynamicPayoutEnvironment::resolve($origin)->assertContract($operation);
        }
        $this->addToAssertionCount(1);
    }

    private function enableProcessingFlags(): void
    {
        config(['nium_dynamic_confirmation.automatic_dispatch_enabled' => true,
            'nium_dynamic_confirmation.enabled' => true, 'nium_dynamic_quotes.quote_contract_enabled' => true,
            'nium_dynamic_quotes.confirmation_contract_enabled' => true, 'nium_dynamic_payouts.reconciliation_enabled' => true]);
    }

    public function test_spend_host_requires_the_production_authorization_gate(): void
    {
        $this->enableProcessingFlags();
        config(['services.nium.base_url' => 'https://api.spend.nium.com',
            'nium_dynamic_payouts.live_automatic_dispatch_authorized' => false]);
        $this->expectExceptionMessage('dynamic_live_dispatch_requires_operator_authorization');
        DynamicPayoutGate::assertDispatchEnabled();
    }

    public function test_authorized_spend_processing_gate_accepts_get_recovery_without_inventing_financial_evidence(): void
    {
        $this->enableProcessingFlags();
        config(['services.nium.base_url' => 'https://api.spend.nium.com',
            'services.nium.transfer_endpoint' => '/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/remittance',
            'nium_dynamic_payouts.live_automatic_dispatch_authorized' => true,
            'nium_dynamic_payouts.operator_authorization_reference' => 'synthetic-operator-reference']);
        DynamicPayoutGate::assertDispatchEnabled();
        $this->assertSame(['dynamic_financial_scope_required'],
            (new DynamicPayoutFinancialAuthorization)->unavailableReasons());
    }

    public static function invalidLiveAuthorization(): array
    {
        return [
            'false' => [false, 'synthetic-reference'],
            'missing authorization' => [null, 'synthetic-reference'],
            'string true' => ['true', 'synthetic-reference'],
            'integer true' => [1, 'synthetic-reference'],
            'missing reference' => [true, null],
            'empty reference' => [true, ''],
            'blank reference' => [true, '  '],
            'non-string reference' => [true, 123],
            'control character' => [true, "synthetic\nreference"],
            'oversized reference' => [true, str_repeat('a', 256)],
        ];
    }

    #[DataProvider('invalidLiveAuthorization')]
    public function test_verified_get_contract_never_bypasses_explicit_live_authorization(mixed $authorized, mixed $reference): void
    {
        $this->enableProcessingFlags();
        config(['services.nium.base_url' => 'https://api.spend.nium.com',
            'services.nium.transfer_endpoint' => '/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/remittance',
            'nium_dynamic_payouts.live_automatic_dispatch_authorized' => $authorized,
            'nium_dynamic_payouts.operator_authorization_reference' => $reference]);
        $this->expectExceptionMessage('dynamic_live_dispatch_requires_operator_authorization');
        DynamicPayoutGate::assertDispatchEnabled();
    }

    public static function disabledProcessingRequirements(): array
    {
        return [
            ['nium_dynamic_confirmation.automatic_dispatch_enabled', 'dynamic_automatic_dispatch_disabled'],
            ['nium_dynamic_confirmation.enabled', 'dynamic_automatic_dispatch_disabled'],
            ['nium_dynamic_quotes.quote_contract_enabled', 'dynamic_automatic_dispatch_disabled'],
            ['nium_dynamic_quotes.confirmation_contract_enabled', 'dynamic_automatic_dispatch_disabled'],
            ['nium_dynamic_payouts.reconciliation_enabled', 'dynamic_dispatch_requires_reconciliation'],
        ];
    }

    #[DataProvider('disabledProcessingRequirements')]
    public function test_spend_get_compatibility_does_not_bypass_disabled_processing(string $flag, string $reason): void
    {
        $this->enableProcessingFlags();
        config(['services.nium.base_url' => 'https://api.spend.nium.com',
            'nium_dynamic_payouts.live_automatic_dispatch_authorized' => true,
            'nium_dynamic_payouts.operator_authorization_reference' => 'synthetic-reference', $flag => false]);
        $this->expectExceptionMessage($reason);
        DynamicPayoutGate::assertDispatchEnabled();
    }

    public function test_spend_get_compatibility_does_not_bypass_an_unverified_remittance_contract(): void
    {
        $this->enableProcessingFlags();
        config(['services.nium.base_url' => 'https://api.spend.nium.com',
            'services.nium.transfer_endpoint' => '/unverified/remittance',
            'nium_dynamic_payouts.live_automatic_dispatch_authorized' => true,
            'nium_dynamic_payouts.operator_authorization_reference' => 'synthetic-reference']);
        $this->expectExceptionMessage('dynamic_production_remittance_contract_unverified');
        DynamicPayoutGate::assertDispatchEnabled();
    }

    public function test_accepted_customer_pricing_cannot_authorize_an_unverified_spend_endpoint(): void
    {
        $beneficiary = (new Beneficiary)->forceFill(['country_code' => 'GB', 'currency' => 'GBP', 'payout_method' => 'LOCAL']);
        $this->assertSame([], (new DynamicPayoutFinancialAuthorization)->unavailableReasons($beneficiary));
        $this->expectExceptionMessage('dynamic_production_quote_contract_unverified');
        DynamicPayoutEnvironment::resolve('https://api.spend.nium.com')->assertContract('quote');
    }

    public function test_flags_without_a_scoped_contract_do_not_authorize_financial_terms(): void
    {
        $this->enableProcessingFlags();
        $this->expectExceptionMessage('dynamic_financial_scope_required');
        (new DynamicPayoutFinancialAuthorization)->assertAvailable();
    }
}
