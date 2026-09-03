<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\BankAccount;
use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AdminApiSerializationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_list_and_detail_resources_preserve_safe_contracts_without_sensitive_fields(): void
    {
        $admin = $this->admin('admin');
        $customer = User::factory()->create([
            'email' => 'customer@example.com',
            'full_name' => 'Safe Customer',
            'status' => 'active',
            'kyc_status' => 'verified',
        ]);
        $provider = IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Safe Provider',
            'status' => 'active',
        ]);
        $bankAccount = BankAccount::query()->create([
            'user_id' => $customer->id,
            'provider_id' => $provider->id,
            'currency' => 'USD',
            'status' => 'active',
            'account_number' => '123456789',
            'iban' => 'SECRET-IBAN',
            'swift_bic' => 'SECRET-BIC',
            'routing_number' => 'SECRET-ROUTING',
            'raw_data' => ['token' => 'provider-token', 'secret' => 'provider-secret'],
        ]);
        $beneficiary = Beneficiary::query()->create([
            'user_id' => $customer->id,
            'provider_id' => $provider->id,
            'external_beneficiary_id' => 'beneficiary-reference',
            'beneficiary_type' => 'individual',
            'full_name' => 'Safe Beneficiary',
            'email' => 'beneficiary@example.com',
            'country_code' => 'US',
            'currency' => 'USD',
            'account_number' => '987654321',
            'iban' => 'BENEFICIARY-IBAN',
            'swift_bic' => 'BENEFICIARY-BIC',
            'raw_data' => ['provider_payload' => ['secret' => 'hidden']],
        ]);
        $transfer = Transfer::query()->create([
            'transfer_no' => 'TRF-SAFE-1',
            'user_id' => $customer->id,
            'provider_id' => $provider->id,
            'source_bank_account_id' => $bankAccount->id,
            'beneficiary_id' => $beneficiary->id,
            'transfer_type' => 'bank',
            'source_currency' => 'USD',
            'target_currency' => 'USD',
            'source_amount' => 100,
            'fee_amount' => 1,
            'status' => 'approval_required',
            'raw_data' => ['provider_payload' => ['access_token' => 'hidden']],
        ]);
        $transaction = Transaction::query()->create([
            'user_id' => $customer->id,
            'provider_id' => $provider->id,
            'bank_account_id' => $bankAccount->id,
            'transfer_id' => $transfer->id,
            'external_transaction_id' => 'TX-SAFE-1',
            'currency' => 'USD',
            'amount' => 100,
            'fee_amount' => 1,
            'description' => 'Allowed detail description',
            'status' => 'booked',
            'raw_data' => ['secret' => 'hidden'],
        ]);
        $token = $this->token($admin);

        $cases = [
            ['/api/admin/bank-accounts', 'data.0.currency', 'USD'],
            ["/api/admin/bank-accounts/{$bankAccount->id}", 'currency', 'USD'],
            ['/api/admin/beneficiaries', 'data.0.full_name', 'Safe Beneficiary'],
            ["/api/admin/beneficiaries/{$beneficiary->id}", 'email', 'beneficiary@example.com'],
            ['/api/admin/transactions', 'data.0.external_transaction_id', 'TX-SAFE-1'],
            ["/api/admin/transactions/{$transaction->id}", 'description', 'Allowed detail description'],
            ['/api/admin/transfers', 'data.0.transfer_no', 'TRF-SAFE-1'],
            ["/api/admin/transfers/{$transfer->id}", 'beneficiary.full_name', 'Safe Beneficiary'],
            ['/api/admin/integration-providers', 'data.0.name', 'Safe Provider'],
            ["/api/admin/integration-providers/{$provider->code}", 'name', 'Safe Provider'],
            ['/api/admin/users', 'data.0.email', 'customer@example.com'],
            ["/api/admin/users/{$customer->id}", 'email', 'customer@example.com'],
        ];

        foreach ($cases as [$uri, $path, $value]) {
            $response = $this->withToken($token)->getJson($uri)->assertOk()->assertJsonPath($path, $value);
            $this->assertSafePayload($response);
        }

        $this->withToken($token)->getJson('/api/admin/beneficiaries')
            ->assertJsonMissingPath('data.0.email');
        $this->withToken($token)->getJson('/api/admin/transactions')
            ->assertJsonMissingPath('data.0.description');
        $this->withToken($token)->getJson('/api/admin/users')
            ->assertJsonMissingPath('data.0.integration_links');
    }

    public function test_authorization_aware_user_fields_and_rbac_remain_enforced(): void
    {
        $customer = User::factory()->create();
        $provider = IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Safe Provider',
            'status' => 'active',
        ]);
        $customer->integrationLinks()->create([
            'provider_id' => $provider->id,
            'link_url' => 'https://example.test/connect/session-token',
            'link_label' => 'Connect',
            'is_active' => true,
        ]);
        $auditor = $this->admin('auditor');
        $manager = $this->admin('admin');

        $this->withToken($this->token($auditor))
            ->getJson("/api/admin/users/{$customer->id}")
            ->assertOk()
            ->assertJsonMissingPath('integration_links')
            ->assertJsonMissingPath('available_providers');

        $this->withToken($this->token($manager))
            ->getJson("/api/admin/users/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('integration_links.0.link_label', 'Connect')
            ->assertJsonPath('available_providers.0.name', 'Safe Provider');

        $this->withToken($this->token($auditor))
            ->postJson('/api/admin/users', ['email' => 'blocked@example.com', 'password' => 'secret123'])
            ->assertForbidden();
    }

    private function assertSafePayload(TestResponse $response): void
    {
        $serialized = json_encode($response->json(), JSON_THROW_ON_ERROR);

        foreach (['raw_data', 'account_number', 'iban', 'swift_bic', 'routing_number', 'provider_payload', 'provider-token', 'provider-secret'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($serialized));
        }
    }

    private function admin(string $roleCode): User
    {
        $user = User::factory()->create();
        $role = Role::query()->firstOrCreate(['code' => $roleCode], ['name' => Str::headline($roleCode)]);

        foreach ((array) config("rbac.roles.{$roleCode}", []) as $permissionCode) {
            if ($permissionCode === '*') {
                continue;
            }

            $permission = Permission::query()->firstOrCreate(
                ['code' => $permissionCode],
                ['name' => Str::headline($permissionCode)]
            );
            $role->permissions()->syncWithoutDetaching($permission);
        }

        $user->roles()->create(['role_id' => $role->id, 'role_code' => $role->code]);

        return $user;
    }

    private function token(User $user): string
    {
        $plainToken = Str::random(80);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'serialization-test',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        return $plainToken;
    }
}
