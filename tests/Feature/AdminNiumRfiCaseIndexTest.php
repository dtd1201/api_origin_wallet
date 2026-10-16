<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\IntegrationProvider;
use App\Models\NiumRfiCase;
use App\Models\User;
use App\Models\UserProviderAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminNiumRfiCaseIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_contract_returns_safe_customer_and_request_summaries_with_historical_origin(): void
    {
        $provider = $this->provider();
        [, $account] = $this->customer($provider, 'GREENTECH LEGAL LIMITED', 'Greentech Trading');
        $authoritative = $this->rfi($provider, $account, [
            'origin' => 'authoritative_fetch',
            'evidence' => [
                'rfiHashId' => 'sensitive-provider-rfi-id',
                'template' => [
                    'name' => 'Source of funds',
                    'requiredFields' => [
                        ['fieldLabel' => 'Bank statement'],
                        ['fieldLabel' => 'Invoice'],
                    ],
                ],
                'remarks' => 'Provide the requested business records.',
                'rawResponse' => ['private' => 'must-not-be-listed'],
            ],
        ]);
        $historical = $this->rfi($provider, $account, [
            'origin' => 'webhook',
            'status' => 'historical_webhook_evidence',
            'evidence' => ['eventId' => 'private-webhook-event'],
        ]);

        $response = $this->withToken($this->adminToken())->getJson('/api/admin/nium-rfi-cases')->assertOk();

        $response
            ->assertJsonPath('total', 2)
            ->assertJsonFragment([
                'id' => $authoritative->id,
                'origin' => 'authoritative_fetch',
                'customer_id' => $account->user_id,
                'customer_name' => 'Greentech Trading',
                'template_name' => 'Source of funds',
                'provider_remarks' => 'Provide the requested business records.',
                'required_document_count' => 2,
            ])
            ->assertJsonFragment([
                'id' => $historical->id,
                'origin' => 'webhook',
                'customer_name' => 'Greentech Trading',
                'template_name' => null,
                'provider_remarks' => null,
                'required_document_count' => 0,
            ]);

        $json = $response->getContent();
        $this->assertStringNotContainsString('sensitive-provider-rfi-id', $json);
        $this->assertStringNotContainsString('must-not-be-listed', $json);
        $this->assertStringNotContainsString('private-webhook-event', $json);
    }

    public function test_search_is_limited_to_case_id_customer_name_and_public_template_labels(): void
    {
        $provider = $this->provider();
        [, $greentech] = $this->customer($provider, 'GREENTECH LEGAL LIMITED', 'Greentech Trading');
        [, $other] = $this->customer($provider, 'OTHER CUSTOMER LIMITED', null);
        $case = $this->rfi($provider, $greentech, [
            'evidence' => [
                'rfiHashId' => 'secret-rfi-reference',
                'template' => ['name' => 'Ownership evidence'],
                'remarks' => 'secret searchable remarks',
            ],
        ]);
        $this->rfi($provider, $other, ['evidence' => ['template' => ['name' => 'Transaction purpose']]]);
        $token = $this->adminToken();

        foreach (['Greentech', 'GREENTECH LEGAL', 'Ownership evidence', (string) $case->id, '#'.$case->id] as $search) {
            $this->withToken($token)->getJson('/api/admin/nium-rfi-cases?search='.urlencode($search))
                ->assertOk()
                ->assertJsonPath('total', 1)
                ->assertJsonPath('data.0.id', $case->id);
        }

        foreach (['secret-rfi-reference', 'secret searchable remarks'] as $disallowedSearch) {
            $this->withToken($token)->getJson('/api/admin/nium-rfi-cases?search='.urlencode($disallowedSearch))
                ->assertOk()
                ->assertJsonPath('total', 0);
        }
    }

    public function test_customer_identity_is_null_without_a_profile_and_distinct_users_keep_distinct_ids(): void
    {
        $provider = $this->provider();
        [$firstUser, $firstAccount] = $this->customer($provider, 'SHARED CUSTOMER LIMITED', null);
        [$secondUser, $secondAccount] = $this->customer($provider, 'SHARED CUSTOMER LIMITED', null);
        $userWithoutProfile = User::factory()->create();
        $accountWithoutProfile = $userWithoutProfile->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => 'customer-without-profile',
            'status' => 'pending',
        ]);
        $this->rfi($provider, $firstAccount);
        $this->rfi($provider, $secondAccount);
        $missingIdentityCase = $this->rfi($provider, $accountWithoutProfile);

        $data = $this->withToken($this->adminToken())->getJson('/api/admin/nium-rfi-cases')
            ->assertOk()
            ->json('data');

        $this->assertSame([$firstUser->id, $secondUser->id], collect($data)
            ->where('customer_name', 'SHARED CUSTOMER LIMITED')
            ->pluck('customer_id')->sort()->values()->all());
        $missingIdentity = collect($data)->firstWhere('id', $missingIdentityCase->id);
        $this->assertSame($userWithoutProfile->id, $missingIdentity['customer_id']);
        $this->assertNull($missingIdentity['customer_name']);
    }

    public function test_search_remains_grouped_with_filters_pagination_and_user_scope(): void
    {
        $provider = $this->provider();
        [$firstUser, $firstAccount] = $this->customer($provider, 'ALPHA CUSTOMER LIMITED', 'Alpha Company');
        [, $secondAccount] = $this->customer($provider, 'ALPHA OTHER LIMITED', 'Alpha Company Two');

        $matching = $this->rfi($provider, $firstAccount, [
            'scope' => 'customer', 'status' => 'requested', 'submission_state' => 'draft',
            'evidence' => ['template' => ['name' => 'Alpha documents']],
        ]);
        $this->rfi($provider, $firstAccount, [
            'scope' => 'customer', 'status' => 'responded_under_review', 'submission_state' => 'draft',
            'evidence' => ['template' => ['name' => 'Alpha documents']],
        ]);
        $this->rfi($provider, $secondAccount, [
            'scope' => 'customer', 'status' => 'requested', 'submission_state' => 'draft',
            'evidence' => ['template' => ['name' => 'Alpha documents']],
        ]);

        $query = http_build_query([
            'search' => 'Alpha',
            'scope' => 'customer',
            'status' => 'requested',
            'submission_state' => 'draft',
            'user_id' => $firstUser->id,
            'per_page' => 1,
        ]);
        $this->withToken($this->adminToken())->getJson("/api/admin/nium-rfi-cases?{$query}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_index_preserves_backend_pagination(): void
    {
        $provider = $this->provider();
        [, $account] = $this->customer($provider, 'PAGINATED CUSTOMER LIMITED', null);
        $oldest = $this->rfi($provider, $account);
        $this->rfi($provider, $account);
        $this->rfi($provider, $account);
        $token = $this->adminToken();

        $this->withToken($token)->getJson('/api/admin/nium-rfi-cases?per_page=2')
            ->assertOk()
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('per_page', 2)
            ->assertJsonPath('total', 3)
            ->assertJsonCount(2, 'data');

        $this->withToken($token)->getJson('/api/admin/nium-rfi-cases?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('current_page', 2)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $oldest->id);
    }

    public function test_search_and_pagination_validation_reject_invalid_input(): void
    {
        $token = $this->adminToken();

        $this->withToken($token)->getJson('/api/admin/nium-rfi-cases?search[]=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('search');
        $this->withToken($token)->getJson('/api/admin/nium-rfi-cases?search='.str_repeat('a', 101))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('search');
        $this->withToken($token)->getJson('/api/admin/nium-rfi-cases?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    private function provider(): IntegrationProvider
    {
        return IntegrationProvider::query()->create(['code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
    }

    private function customer(IntegrationProvider $provider, string $legalName, ?string $businessName): array
    {
        $user = User::factory()->create(['kyc_status' => 'verified']);
        $user->kycProfile()->create([
            'status' => 'approved',
            'applicant_type' => 'business',
            'legal_name' => $legalName,
            'business_name' => $businessName,
            'address_line1' => 'Authorized Admin record',
            'city' => 'Hong Kong',
            'country_code' => 'HK',
        ]);
        $account = $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => 'customer-'.$user->id,
            'status' => 'pending',
        ]);

        return [$user, $account];
    }

    private function rfi(IntegrationProvider $provider, UserProviderAccount $account, array $overrides = []): NiumRfiCase
    {
        static $sequence = 0;
        $sequence++;

        return NiumRfiCase::query()->create([
            'provider_id' => $provider->id,
            'user_provider_account_id' => $account->id,
            'scope' => 'customer',
            'origin' => 'authoritative_fetch',
            'provider_reference_fingerprint' => hash('sha256', "admin-list-{$sequence}"),
            'status' => 'requested',
            'contract_gate' => 'provider_contract_unconfirmed',
            'submission_state' => 'new',
            'evidence' => [],
            ...$overrides,
        ]);
    }

    private function adminToken(): string
    {
        $admin = User::factory()->create();
        $admin->roles()->create(['role_code' => 'admin']);
        $plain = Str::random(80);
        ApiToken::query()->create([
            'user_id' => $admin->id,
            'name' => 'admin-rfi-index-test',
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDay(),
        ]);

        return $plain;
    }
}
