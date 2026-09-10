<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BeneficiaryPayoutMethodMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_is_nullable_reversible_and_does_not_infer_legacy_values(): void
    {
        $user = User::factory()->create();
        $provider = IntegrationProvider::query()->create(['code' => 'migration-provider', 'name' => 'Migration Provider']);
        $beneficiary = Beneficiary::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'beneficiary_type' => 'business',
            'full_name' => 'Legacy Beneficiary',
            'country_code' => 'HK',
            'currency' => 'USD',
            'status' => 'active',
        ]);
        $migration = require database_path('migrations/2026_09_10_000002_add_payout_method_to_beneficiaries.php');

        $this->assertNull($beneficiary->payout_method);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('beneficiaries', 'payout_method'));
        $this->assertDatabaseHas('beneficiaries', ['id' => $beneficiary->id, 'status' => 'active']);

        $migration->up();
        $this->assertTrue(Schema::hasColumn('beneficiaries', 'payout_method'));
        $this->assertNull(Beneficiary::query()->findOrFail($beneficiary->id)->payout_method);
    }
}
