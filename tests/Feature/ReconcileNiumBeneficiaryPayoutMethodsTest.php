<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ReconcileNiumBeneficiaryPayoutMethodsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.nium', array_replace_recursive((array) config('services.nium'), [
            'base_url' => 'https://gateway.nium.test',
            'client_id' => 'reconcile-client',
            'auth' => ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'reconcile-secret'],
            'webhook' => ['static_header_name' => 'x-partner-key', 'static_header_value' => 'reconcile-partner-secret'],
            'health_endpoint' => '/api/v1/client/{clientHashId}',
            'customer_create_endpoint' => '/api/v1/client/{clientHashId}/customer',
            'customer_list_endpoint' => '/api/v1/client/{clientHashId}/customers',
            'customer_get_endpoint' => '/api/v1/client/{clientHashId}/customer/{customerHashId}',
            'beneficiary_endpoint' => '/api/v2/client/{clientHashId}/customer/{customerHashId}/beneficiaries',
        ]));
    }

    public function test_dry_run_matches_without_changing_beneficiary(): void
    {
        [$user, $provider] = $this->customer();
        $beneficiary = $this->beneficiary($user, $provider, 'beneficiary-one');
        $transactionLevel = DB::transactionLevel();
        Http::fake(function () use ($transactionLevel) {
            $this->assertSame($transactionLevel, DB::transactionLevel());

            return Http::response([$this->providerBeneficiary('beneficiary-one')]);
        });

        $this->artisan('nium:reconcile-beneficiary-payout-methods', $this->arguments($user, $provider))
            ->expectsOutput("MATCHED beneficiary {$beneficiary->id} [beneficiary-one]: exact HK/USD/SWIFT provider evidence verified")
            ->expectsOutput('Reconciliation dry run: 1 matched, 0 rejected.')
            ->assertSuccessful();

        $this->assertNull($beneficiary->fresh()->payout_method);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_successful_batch_apply_repairs_and_audits_every_match(): void
    {
        [$user, $provider] = $this->customer();
        $first = $this->beneficiary($user, $provider, 'beneficiary-one');
        $second = $this->beneficiary($user, $provider, 'beneficiary-two');
        Http::fake(['*' => Http::response([
            $this->providerBeneficiary('beneficiary-one'),
            $this->providerBeneficiary('beneficiary-two'),
        ])]);

        $this->artisan('nium:reconcile-beneficiary-payout-methods', $this->applyArguments($user, $provider))->assertSuccessful();

        $this->assertSame('SWIFT', $first->fresh()->payout_method);
        $this->assertSame('SWIFT', $second->fresh()->payout_method);
        $this->assertDatabaseCount('audit_logs', 2);
        $audit = AuditLog::query()->where('entity_id', (string) $first->id)->sole();
        $this->assertSame('OPS-456 legacy reconciliation', $audit->new_data['operator_context']);
        $this->assertSame('HK', $audit->new_data['destination_country']);
        $this->assertSame('USD', $audit->new_data['destination_currency']);
        $this->assertSame('SWIFT', $audit->new_data['payout_method']);
        $this->assertSame(
            ApiRequestLog::query()->where('operation', 'beneficiary_list_reconciliation')->sole()->id,
            $audit->new_data['reconciliation_api_request_log_id'],
        );
    }

    public function test_partial_and_mismatched_provider_responses_reject_without_inference(): void
    {
        [$user, $provider] = $this->customer();
        $matched = $this->beneficiary($user, $provider, 'matched');
        $missing = $this->beneficiary($user, $provider, 'missing');
        $wrongCorridor = $this->beneficiary($user, $provider, 'wrong-corridor');
        Http::fake(['*' => Http::response([
            $this->providerBeneficiary('matched'),
            $this->providerBeneficiary('wrong-corridor', ['destinationCountry' => 'US']),
        ])]);

        $this->artisan('nium:reconcile-beneficiary-payout-methods', $this->applyArguments($user, $provider))
            ->expectsOutput("REJECTED beneficiary {$missing->id} [missing]: external beneficiary ID is absent from provider response")
            ->expectsOutput("REJECTED beneficiary {$wrongCorridor->id} [wrong-corridor]: provider destination country is not HK")
            ->assertSuccessful();

        $this->assertSame('SWIFT', $matched->fresh()->payout_method);
        $this->assertNull($missing->fresh()->payout_method);
        $this->assertNull($wrongCorridor->fresh()->payout_method);
    }

    public function test_duplicate_external_ids_are_rejected(): void
    {
        [$user, $provider] = $this->customer();
        $beneficiary = $this->beneficiary($user, $provider, 'duplicate');
        Http::fake(['*' => Http::response([
            $this->providerBeneficiary('duplicate'),
            $this->providerBeneficiary('duplicate'),
        ])]);

        $this->artisan('nium:reconcile-beneficiary-payout-methods', $this->applyArguments($user, $provider))
            ->expectsOutput("REJECTED beneficiary {$beneficiary->id} [duplicate]: duplicate external beneficiary ID in provider response")
            ->assertSuccessful();

        $this->assertNull($beneficiary->fresh()->payout_method);
    }

    public function test_state_change_during_http_is_detected_before_locked_mutation(): void
    {
        [$user, $provider] = $this->customer();
        $beneficiary = $this->beneficiary($user, $provider, 'changed');
        Http::fake(function () use ($beneficiary) {
            $beneficiary->update(['status' => 'inactive']);

            return Http::response([$this->providerBeneficiary('changed')]);
        });

        $this->artisan('nium:reconcile-beneficiary-payout-methods', $this->applyArguments($user, $provider))
            ->expectsOutput("Beneficiary {$beneficiary->id} changed after reconciliation and was not modified.")
            ->assertFailed();

        $this->assertNull($beneficiary->fresh()->payout_method);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_http_failure_changes_nothing(): void
    {
        [$user, $provider] = $this->customer();
        $beneficiary = $this->beneficiary($user, $provider, 'http-failure');
        Http::fake(['*' => Http::response(['message' => 'unavailable'], 503)]);

        $this->artisan('nium:reconcile-beneficiary-payout-methods', $this->applyArguments($user, $provider))
            ->expectsOutput('Nium beneficiary list request failed with HTTP 503.')
            ->assertFailed();

        $this->assertNull($beneficiary->fresh()->payout_method);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_audit_failure_rolls_back_entire_batch(): void
    {
        [$user, $provider] = $this->customer();
        $first = $this->beneficiary($user, $provider, 'rollback-one');
        $second = $this->beneficiary($user, $provider, 'rollback-two');
        Http::fake(['*' => Http::response([
            $this->providerBeneficiary('rollback-one'),
            $this->providerBeneficiary('rollback-two'),
        ])]);
        $auditInserts = 0;
        DB::listen(function (QueryExecuted $query) use (&$auditInserts): void {
            if (str_contains(strtolower($query->sql), 'insert into "audit_logs"') && ++$auditInserts === 2) {
                throw new RuntimeException('forced audit failure');
            }
        });

        $this->artisan('nium:reconcile-beneficiary-payout-methods', $this->applyArguments($user, $provider))
            ->expectsOutput('forced audit failure')
            ->assertFailed();

        $this->assertNull($first->fresh()->payout_method);
        $this->assertNull($second->fresh()->payout_method);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    private function customer(): array
    {
        $provider = IntegrationProvider::query()->create(['code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        $user = User::factory()->create();
        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => 'customer-hash',
            'external_account_id' => 'wallet-hash',
            'status' => 'active',
            'provider_status' => 'clear',
            'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(),
            'provider_ids_verified_at' => now(),
        ]);

        return [$user, $provider];
    }

    private function beneficiary(User $user, IntegrationProvider $provider, string $externalId): Beneficiary
    {
        return Beneficiary::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'external_beneficiary_id' => $externalId,
            'beneficiary_type' => 'business',
            'full_name' => $externalId,
            'country_code' => 'HK',
            'currency' => 'USD',
            'status' => 'active',
        ]);
    }

    private function providerBeneficiary(string $externalId, array $overrides = []): array
    {
        return [
            'beneficiaryHashId' => $externalId,
            'destinationCountry' => 'HK',
            'destinationCurrency' => 'USD',
            'payoutMethod' => 'SWIFT',
            ...$overrides,
        ];
    }

    private function arguments(User $user, IntegrationProvider $provider): array
    {
        return ['user' => $user->id, '--provider' => $provider->id];
    }

    private function applyArguments(User $user, IntegrationProvider $provider): array
    {
        return [
            ...$this->arguments($user, $provider),
            '--apply' => true,
            '--approve' => 'REPAIR_NIUM_BENEFICIARY_PAYOUT_METHOD',
            '--operator' => 'OPS-456 legacy reconciliation',
        ];
    }
}
