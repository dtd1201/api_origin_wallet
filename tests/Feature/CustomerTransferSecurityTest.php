<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Balance;
use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Integrations\ProviderQuoteManager;
use App\Services\Integrations\ProviderTransferManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class CustomerTransferSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_creation_scopes_beneficiary_and_source_account_to_customer(): void
    {
        [$customer, $token, $provider] = $this->customer();
        [$otherCustomer] = $this->customer($provider);
        $otherBeneficiary = $this->beneficiary($otherCustomer, $provider);
        $otherAccount = $this->bankAccount($otherCustomer, $provider);

        $this->withToken($token)
            ->postJson("/api/user/users/{$customer->id}/transfers", $this->transferPayload($provider, [
                'beneficiary_id' => $otherBeneficiary->id,
            ]))
            ->assertNotFound();

        $this->withToken($token)
            ->postJson("/api/user/users/{$customer->id}/transfers", $this->transferPayload($provider, [
                'beneficiary_id' => $this->beneficiary($customer, $provider)->id,
                'source_bank_account_id' => $otherAccount->id,
            ]))
            ->assertNotFound();
    }

    public function test_same_customer_creation_works_and_provider_mismatch_is_rejected(): void
    {
        [$customer, $token, $provider] = $this->customer();
        $beneficiary = $this->beneficiary($customer, $provider);
        $account = $this->bankAccount($customer, $provider);

        $this->withToken($token)
            ->postJson("/api/user/users/{$customer->id}/transfers", $this->transferPayload($provider, [
                'beneficiary_id' => $beneficiary->id,
                'source_bank_account_id' => $account->id,
            ]))
            ->assertCreated()
            ->assertJsonPath('beneficiary_id', $beneficiary->id)
            ->assertJsonPath('source_bank_account_id', $account->id)
            ->assertJsonMissingPath('user_id')
            ->assertJsonMissingPath('raw_data');

        $otherProvider = IntegrationProvider::query()->create([
            'code' => 'provider-mismatch',
            'name' => 'Provider Mismatch',
            'status' => 'active',
        ]);
        $mismatchedBeneficiary = $this->beneficiary($customer, $otherProvider);

        $this->withToken($token)
            ->postJson("/api/user/users/{$customer->id}/transfers", $this->transferPayload($provider, [
                'beneficiary_id' => $mismatchedBeneficiary->id,
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Beneficiary provider does not match transfer provider.');
    }

    public function test_preexisting_transfer_cannot_submit_with_another_customers_parties(): void
    {
        [$customer, $token, $provider] = $this->customer();
        [$otherCustomer] = $this->customer($provider);
        $ownBeneficiary = $this->beneficiary($customer, $provider);
        $ownAccount = $this->bankAccount($customer, $provider);
        $otherBeneficiary = $this->beneficiary($otherCustomer, $provider);
        $otherAccount = $this->bankAccount($otherCustomer, $provider);

        foreach ([
            ['beneficiary_id' => $otherBeneficiary->id, 'source_bank_account_id' => $ownAccount->id],
            ['beneficiary_id' => $ownBeneficiary->id, 'source_bank_account_id' => $otherAccount->id],
        ] as $parties) {
            $transfer = $this->transfer($customer, $provider, $parties);

            $response = $this->withToken($token)
                ->postJson("/api/user/users/{$customer->id}/transfers/{$transfer->id}/submit")
                ->assertUnprocessable()
                ->assertJsonPath('message', 'Unable to submit transfer. Review its current state and try again.');

            $this->assertStringNotContainsString('another customer', $response->getContent());
        }
    }

    public function test_transfer_list_and_detail_use_customer_safe_nested_resources(): void
    {
        [$customer, $token, $provider] = $this->customer();
        $beneficiary = $this->beneficiary($customer, $provider, [
            'external_beneficiary_id' => 'SECRET_EXTERNAL_BENEFICIARY',
            'raw_data' => ['diagnostic' => 'SECRET_BENEFICIARY_DIAGNOSTIC'],
        ]);
        $account = $this->bankAccount($customer, $provider, [
            'external_account_id' => 'SECRET_EXTERNAL_ACCOUNT',
            'raw_data' => ['diagnostic' => 'SECRET_ACCOUNT_DIAGNOSTIC'],
        ]);
        $transfer = $this->transfer($customer, $provider, [
            'beneficiary_id' => $beneficiary->id,
            'source_bank_account_id' => $account->id,
            'provider_operation_key' => 'SECRET_OPERATION_KEY',
            'failure_reason' => 'SECRET_PROVIDER_FAILURE',
            'raw_data' => ['diagnostic' => 'SECRET_TRANSFER_DIAGNOSTIC'],
        ]);
        $transfer->approvals()->create([
            'approver_user_id' => $customer->id,
            'action' => 'approved',
            'note' => 'SECRET_INTERNAL_APPROVAL_NOTE',
        ]);
        $transfer->transactions()->create([
            'user_id' => $customer->id,
            'provider_id' => $provider->id,
            'bank_account_id' => $account->id,
            'external_transaction_id' => 'SECRET_EXTERNAL_TRANSACTION',
            'currency' => 'USD',
            'amount' => '10.00000000',
            'fee_amount' => '0.00000000',
            'status' => 'pending',
            'raw_data' => ['diagnostic' => 'SECRET_TRANSACTION_DIAGNOSTIC'],
        ]);

        $list = $this->withToken($token)
            ->getJson("/api/user/users/{$customer->id}/transfers")
            ->assertOk()
            ->assertJsonPath('0.id', $transfer->id)
            ->assertJsonMissingPath('0.user_id')
            ->assertJsonMissingPath('0.provider_operation_key')
            ->assertJsonMissingPath('0.raw_data');

        $detail = $this->withToken($token)
            ->getJson("/api/user/users/{$customer->id}/transfers/{$transfer->id}")
            ->assertOk()
            ->assertJsonMissingPath('user_id')
            ->assertJsonMissingPath('provider_operation_key')
            ->assertJsonMissingPath('raw_data')
            ->assertJsonMissingPath('approvals')
            ->assertJsonMissingPath('beneficiary.user_id')
            ->assertJsonMissingPath('beneficiary.external_beneficiary_id')
            ->assertJsonMissingPath('beneficiary.raw_data')
            ->assertJsonMissingPath('source_bank_account.user_id')
            ->assertJsonMissingPath('source_bank_account.external_account_id')
            ->assertJsonMissingPath('source_bank_account.raw_data')
            ->assertJsonMissingPath('transactions.0.user_id')
            ->assertJsonMissingPath('transactions.0.external_transaction_id')
            ->assertJsonMissingPath('transactions.0.raw_data');

        foreach ([$list->getContent(), $detail->getContent()] as $content) {
            foreach (['SECRET_OPERATION_KEY', 'SECRET_PROVIDER_FAILURE', 'SECRET_TRANSFER_DIAGNOSTIC',
                'SECRET_EXTERNAL_BENEFICIARY', 'SECRET_BENEFICIARY_DIAGNOSTIC', 'SECRET_EXTERNAL_ACCOUNT',
                'SECRET_ACCOUNT_DIAGNOSTIC', 'SECRET_INTERNAL_APPROVAL_NOTE', 'SECRET_EXTERNAL_TRANSACTION',
                'SECRET_TRANSACTION_DIAGNOSTIC'] as $sentinel) {
                $this->assertStringNotContainsString($sentinel, $content);
            }
        }
    }

    public function test_quote_and_transfer_failures_are_sanitized_while_validation_remains_meaningful(): void
    {
        [$customer, $token, $provider] = $this->customer();
        $beneficiary = $this->beneficiary($customer, $provider);
        $transfer = $this->transfer($customer, $provider, ['beneficiary_id' => $beneficiary->id]);

        $this->mock(ProviderQuoteManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createQuote')->once()
                ->andThrow(new RuntimeException('SECRET_QUOTE_PROVIDER_URL_AND_REQUEST_ID'));
        });
        $quoteFailure = $this->withToken($token)
            ->postJson("/api/user/users/{$customer->id}/fx-quotes", [
                'provider_id' => $provider->id,
                'source_currency' => 'USD',
                'target_currency' => 'EUR',
                'source_amount' => '10.00',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Unable to create an FX quote with the selected provider. Review the currencies and amount, then try again.');
        $this->assertStringNotContainsString('SECRET_QUOTE_PROVIDER_URL_AND_REQUEST_ID', $quoteFailure->getContent());

        $this->mock(ProviderTransferManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('submitTransfer')->once()
                ->andThrow(new RuntimeException('SECRET_TRANSFER_PROVIDER_RESPONSE'));
        });
        $transferFailure = $this->withToken($token)
            ->postJson("/api/user/users/{$customer->id}/transfers/{$transfer->id}/submit")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Unable to submit transfer. Review its current state and try again.');
        $this->assertStringNotContainsString('SECRET_TRANSFER_PROVIDER_RESPONSE', $transferFailure->getContent());

        $this->withToken($token)
            ->postJson("/api/user/users/{$customer->id}/transfers", ['provider_id' => $provider->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transfer_type', 'source_currency', 'target_currency', 'source_amount']);
    }

    private function customer(?IntegrationProvider $provider = null): array
    {
        $this->configureNium();
        $provider ??= IntegrationProvider::query()->firstOrCreate(
            ['code' => 'nium'],
            ['name' => 'Nium', 'status' => 'active'],
        );
        $customer = User::factory()->create(['kyc_status' => 'verified']);
        $customer->profile()->create(['user_type' => 'business']);
        $customer->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => 'customer-'.Str::random(12),
            'external_account_id' => 'wallet-'.Str::random(12),
            'status' => 'active',
            'provider_status' => 'CLEAR',
            'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(),
        ]);
        Balance::query()->create([
            'user_id' => $customer->id,
            'provider_id' => $provider->id,
            'currency' => 'USD',
            'available_balance' => '1000.00000000',
            'ledger_balance' => '1000.00000000',
            'as_of' => now(),
        ]);

        return [$customer, $this->issueTokenFor($customer), $provider];
    }

    private function configureNium(): void
    {
        config()->set('services.nium', array_replace_recursive((array) config('services.nium'), [
            'base_url' => 'https://gateway.nium.test',
            'client_id' => 'p6a-client-id',
            'auth' => ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'p6a-key'],
            'webhook' => ['static_header_name' => 'x-partner-key', 'static_header_value' => 'p6a-partner-key'],
            'health_endpoint' => '/api/v1/client/{clientHashId}',
            'customer_create_endpoint' => '/api/v1/client/{clientHashId}/customer',
            'customer_list_endpoint' => '/api/v1/client/{clientHashId}/customers',
            'customer_get_endpoint' => '/api/v1/client/{clientHashId}/customer/{customerHashId}',
        ]));
        config()->set('wallet.transfer_controls.require_admin_approval', false);
    }

    private function beneficiary(User $customer, IntegrationProvider $provider, array $overrides = [])
    {
        return $customer->beneficiaries()->create(array_replace([
            'provider_id' => $provider->id,
            'external_beneficiary_id' => 'beneficiary-'.Str::random(12),
            'beneficiary_type' => 'business',
            'full_name' => 'Customer Beneficiary',
            'country_code' => 'US',
            'currency' => 'USD',
            'status' => 'active',
        ], $overrides));
    }

    private function bankAccount(User $customer, IntegrationProvider $provider, array $overrides = [])
    {
        return $customer->bankAccounts()->create(array_replace([
            'provider_id' => $provider->id,
            'external_account_id' => 'account-'.Str::random(12),
            'account_type' => 'wallet',
            'currency' => 'USD',
            'status' => 'active',
        ], $overrides));
    }

    private function transfer(User $customer, IntegrationProvider $provider, array $overrides = []): Transfer
    {
        return $customer->transfers()->create(array_replace([
            'provider_id' => $provider->id,
            'transfer_no' => 'TRF-'.Str::upper(Str::random(12)),
            'transfer_type' => 'bank',
            'source_currency' => 'USD',
            'target_currency' => 'USD',
            'source_amount' => '10.00000000',
            'fee_amount' => '0.00000000',
            'status' => 'draft',
        ], $overrides));
    }

    private function transferPayload(IntegrationProvider $provider, array $overrides = []): array
    {
        return array_replace([
            'provider_id' => $provider->id,
            'transfer_type' => 'bank',
            'source_currency' => 'USD',
            'target_currency' => 'USD',
            'source_amount' => '10.00',
        ], $overrides);
    }

    private function issueTokenFor(User $user): string
    {
        $plainToken = Str::random(80);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'p6a-test-token',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        return $plainToken;
    }
}
