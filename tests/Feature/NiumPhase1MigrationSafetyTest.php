<?php

namespace Tests\Feature;

use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class NiumPhase1MigrationSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase1_migration_rolls_back_and_migrates_again_with_expected_columns_and_unique_key(): void
    {
        $migration = require database_path('migrations/2026_07_14_000002_add_provider_onboarding_state_to_user_provider_accounts.php');
        $migration->down();

        $this->assertFalse(Schema::hasColumn('user_provider_accounts', 'customer_id_verified_at'));
        $this->assertFalse(Schema::hasColumn('user_provider_accounts', 'security_conflict_at'));

        $migration->up();

        $this->assertTrue(Schema::hasColumn('user_provider_accounts', 'customer_id_verified_at'));
        $this->assertTrue(Schema::hasColumn('user_provider_accounts', 'wallet_id_verified_at'));
        $this->assertTrue(Schema::hasColumn('user_provider_accounts', 'reconciliation_status'));

        $provider = IntegrationProvider::query()->create([
            'code' => 'migration-unique-provider',
            'name' => 'Migration Unique Provider',
            'status' => 'active',
        ]);
        $user = User::factory()->create();
        UserProviderAccount::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
        ]);

        $this->expectException(QueryException::class);
        UserProviderAccount::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
        ]);
    }

    public function test_phase1_migration_preflight_aborts_and_reports_legacy_duplicate_record_ids(): void
    {
        $migration = require database_path('migrations/2026_07_14_000002_add_provider_onboarding_state_to_user_provider_accounts.php');
        $migration->down();
        $provider = IntegrationProvider::query()->create([
            'code' => 'migration-preflight-provider',
            'name' => 'Migration Preflight Provider',
            'status' => 'active',
        ]);
        $user = User::factory()->create();
        $first = UserProviderAccount::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
        ]);
        $second = UserProviderAccount::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
        ]);

        try {
            $migration->up();
            $this->fail('Duplicate preflight should abort the migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Resolve duplicates manually', $exception->getMessage());
            $this->assertStringContainsString((string) $first->id, $exception->getMessage());
            $this->assertStringContainsString((string) $second->id, $exception->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('user_provider_accounts', 'customer_id_verified_at'));
    }
}
