<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\BankAccount;
use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Integrations\ProviderBeneficiaryManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class CustomerApiSerializationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_bank_account_list_and_detail_use_the_customer_allowlist(): void
    {
        [$user, $token, $provider] = $this->customer();
        $account = $user->bankAccounts()->create([
            'provider_id' => $provider->id,
            'external_account_id' => 'PROVIDER_ACCOUNT_INTERNAL_123',
            'account_type' => 'receiving',
            'currency' => 'USD',
            'country_code' => 'US',
            'bank_name' => 'Customer Bank',
            'bank_code' => 'BANK-CODE',
            'branch_code' => 'BRANCH-CODE',
            'account_name' => 'Customer Account',
            'account_number' => '123456789',
            'iban' => 'US00CUSTOMER123',
            'swift_bic' => 'CUSTOMERBIC',
            'routing_number' => '110000000',
            'status' => 'active',
            'is_default' => true,
            'raw_data' => ['diagnostic' => 'SECRET_PROVIDER_DIAGNOSTIC_BANK_ACCOUNT'],
        ]);

        $list = $this->withToken($token)
            ->getJson("/api/user/users/{$user->id}/bank-accounts")
            ->assertOk()
            ->assertJsonPath('0.id', $account->id)
            ->assertJsonPath('0.provider_id', $provider->id)
            ->assertJsonPath('0.account_number', '123456789')
            ->assertJsonMissingPath('0.user_id')
            ->assertJsonMissingPath('0.external_account_id')
            ->assertJsonMissingPath('0.raw_data');

        $detail = $this->withToken($token)
            ->getJson("/api/user/users/{$user->id}/bank-accounts/{$account->id}")
            ->assertOk()
            ->assertJsonPath('bank_name', 'Customer Bank')
            ->assertJsonPath('routing_number', '110000000')
            ->assertJsonMissingPath('user_id')
            ->assertJsonMissingPath('external_account_id')
            ->assertJsonMissingPath('raw_data');

        $this->assertStringNotContainsString('SECRET_PROVIDER_DIAGNOSTIC_BANK_ACCOUNT', $list->getContent());
        $this->assertStringNotContainsString('SECRET_PROVIDER_DIAGNOSTIC_BANK_ACCOUNT', $detail->getContent());
    }

    public function test_bank_account_and_beneficiary_ownership_remain_enforced(): void
    {
        [$owner, , $provider] = $this->customer();
        [$other, $otherToken] = $this->customer($provider);
        $account = $owner->bankAccounts()->create([
            'provider_id' => $provider->id,
            'currency' => 'USD',
            'status' => 'active',
        ]);
        $beneficiary = $owner->beneficiaries()->create($this->beneficiaryAttributes($provider));

        $this->withToken($otherToken)
            ->getJson("/api/user/users/{$other->id}/bank-accounts/{$account->id}")
            ->assertNotFound();
        $this->withToken($otherToken)
            ->getJson("/api/user/users/{$other->id}/beneficiaries/{$beneficiary->id}")
            ->assertNotFound();
        $this->withToken($otherToken)
            ->putJson("/api/user/users/{$other->id}/beneficiaries/{$beneficiary->id}", ['full_name' => 'Changed'])
            ->assertNotFound();
        $this->withToken($otherToken)
            ->deleteJson("/api/user/users/{$other->id}/beneficiaries/{$beneficiary->id}")
            ->assertNotFound();
    }

    public function test_beneficiary_list_detail_create_and_update_use_the_customer_allowlist(): void
    {
        [$user, $token, $provider] = $this->customer();
        $beneficiary = $user->beneficiaries()->create($this->beneficiaryAttributes($provider));

        $this->mock(ProviderBeneficiaryManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createBeneficiary')->once()->andReturnUsing(function ($provider, Beneficiary $beneficiary): Beneficiary {
                $beneficiary->update([
                    'external_beneficiary_id' => 'PROVIDER_BENEFICIARY_CREATED',
                    'status' => 'active',
                    'raw_data' => ['diagnostic' => 'INTERNAL_NIUM_METADATA_CREATE'],
                ]);

                return $beneficiary->fresh();
            });
            $mock->shouldReceive('updateBeneficiary')->once()->andReturnUsing(function ($provider, Beneficiary $beneficiary): Beneficiary {
                $beneficiary->update(['raw_data' => ['diagnostic' => 'INTERNAL_NIUM_METADATA_UPDATE']]);

                return $beneficiary->fresh();
            });
        });

        foreach ([
            "/api/user/users/{$user->id}/beneficiaries",
            "/api/user/users/{$user->id}/beneficiaries/{$beneficiary->id}",
        ] as $uri) {
            $response = $this->withToken($token)->getJson($uri)->assertOk();
            $this->assertStringNotContainsString('SECRET_PROVIDER_DIAGNOSTIC_BENEFICIARY', $response->getContent());
            $response->assertJsonMissingPath(str_ends_with($uri, (string) $beneficiary->id) ? 'raw_data' : '0.raw_data');
            $response->assertJsonMissingPath(str_ends_with($uri, (string) $beneficiary->id) ? 'user_id' : '0.user_id');
            $response->assertJsonMissingPath(str_ends_with($uri, (string) $beneficiary->id) ? 'external_beneficiary_id' : '0.external_beneficiary_id');
        }

        $created = $this->withToken($token)
            ->postJson("/api/user/users/{$user->id}/beneficiaries", $this->beneficiaryPayload($provider))
            ->assertCreated()
            ->assertJsonPath('full_name', 'Created Beneficiary')
            ->assertJsonMissingPath('raw_data')
            ->assertJsonMissingPath('user_id')
            ->assertJsonMissingPath('external_beneficiary_id');
        $this->assertStringNotContainsString('INTERNAL_NIUM_METADATA_CREATE', $created->getContent());

        $updated = $this->withToken($token)
            ->putJson("/api/user/users/{$user->id}/beneficiaries/{$beneficiary->id}", ['full_name' => 'Updated Beneficiary'])
            ->assertOk()
            ->assertJsonPath('full_name', 'Updated Beneficiary')
            ->assertJsonMissingPath('raw_data')
            ->assertJsonMissingPath('user_id')
            ->assertJsonMissingPath('external_beneficiary_id');
        $this->assertStringNotContainsString('INTERNAL_NIUM_METADATA_UPDATE', $updated->getContent());
    }

    public function test_provider_exception_is_sanitized_but_validation_errors_remain_meaningful(): void
    {
        [$user, $token, $provider] = $this->customer();
        $this->mock(ProviderBeneficiaryManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createBeneficiary')
                ->once()
                ->andThrow(new RuntimeException('PROVIDER_INTERNAL_ERROR_SECRET_URL_REQUEST_ID'));
        });

        $providerFailure = $this->withToken($token)
            ->postJson("/api/user/users/{$user->id}/beneficiaries", $this->beneficiaryPayload($provider))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Unable to create beneficiary with the selected provider. Review the details and try again.');
        $this->assertStringNotContainsString('PROVIDER_INTERNAL_ERROR_SECRET_URL_REQUEST_ID', $providerFailure->getContent());

        $this->withToken($token)
            ->postJson("/api/user/users/{$user->id}/beneficiaries", ['provider_id' => $provider->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['beneficiary_type', 'full_name', 'country_code', 'currency']);
    }

    private function customer(?IntegrationProvider $provider = null): array
    {
        config()->set('integrations.providers.nium', [
            'beneficiary' => ProviderBeneficiaryManager::class,
        ]);
        config()->set('services.nium.base_url', 'https://gateway.nium.test');
        config()->set('services.nium.client_id', 'p5-client-id');
        config()->set('services.nium.auth.mode', 'header');
        config()->set('services.nium.auth.header_name', 'x-api-key');
        config()->set('services.nium.auth.header_value', 'p5-test-key');
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'p5-test-partner-key');
        $user = User::factory()->create();
        $user->profile()->create(['user_type' => 'business']);
        $provider ??= IntegrationProvider::query()->firstOrCreate(
            ['code' => 'nium'],
            ['name' => 'Nium', 'status' => 'active'],
        );
        $this->assertTrue($provider->supportsBeneficiaries());

        return [$user, $this->issueTokenFor($user), $provider];
    }

    private function beneficiaryAttributes(IntegrationProvider $provider): array
    {
        return [
            'provider_id' => $provider->id,
            'external_beneficiary_id' => 'PROVIDER_BENEFICIARY_INTERNAL',
            'beneficiary_type' => 'business',
            'full_name' => 'Safe Beneficiary',
            'country_code' => 'US',
            'currency' => 'USD',
            'bank_name' => 'Customer Bank',
            'account_number' => '123456789',
            'status' => 'active',
            'raw_data' => ['diagnostic' => 'SECRET_PROVIDER_DIAGNOSTIC_BENEFICIARY'],
        ];
    }

    private function beneficiaryPayload(IntegrationProvider $provider): array
    {
        return [
            'provider_id' => $provider->id,
            'beneficiary_type' => 'business',
            'full_name' => 'Created Beneficiary',
            'country_code' => 'US',
            'currency' => 'USD',
            'bank_name' => 'Customer Bank',
            'account_number' => '987654321',
            'raw_data' => ['nium' => ['payoutMethod' => 'LOCAL']],
        ];
    }

    private function issueTokenFor(User $user): string
    {
        $plainToken = Str::random(80);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'p5-test-token',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        return $plainToken;
    }
}
