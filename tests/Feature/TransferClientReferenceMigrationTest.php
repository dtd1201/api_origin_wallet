<?php

namespace Tests\Feature;

use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class TransferClientReferenceMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasColumn('transfers', 'provider_status')) {
            Schema::table('transfers', function (Blueprint $table): void {
                $table->string('provider_status', 80)->nullable()->after('status');
                $table->text('provider_status_detail')->nullable()->after('provider_status');
            });
        }
    }

    public function test_migration_adds_only_the_unique_constraint_when_provider_status_columns_exist(): void
    {
        [$transfer] = $this->transferFixture();
        $migration = $this->migration();
        $migration->down();

        $this->assertTrue(Schema::hasColumns('transfers', ['provider_status', 'provider_status_detail', 'provider_status_at']));
        $this->assertFalse($this->hasIndex());

        $migration->up();

        $this->assertTrue($this->hasIndex());
        $this->assertTrue(Schema::hasColumns('transfers', ['provider_status', 'provider_status_detail', 'provider_status_at']));
        $this->assertSame('CLEAR', $transfer->fresh()->provider_status);
        $this->assertSame('preserve detail', $transfer->fresh()->provider_status_detail);
    }

    public function test_rollback_removes_only_the_new_constraint_and_preserves_columns_and_data(): void
    {
        [$transfer] = $this->transferFixture();

        $this->migration()->down();

        $this->assertFalse($this->hasIndex());
        $this->assertTrue(Schema::hasColumns('transfers', [
            'client_reference', 'provider_status', 'provider_status_detail', 'provider_status_at',
        ]));
        $this->assertSame('OW-PRESERVED', $transfer->fresh()->client_reference);
        $this->assertSame('CLEAR', $transfer->fresh()->provider_status);
        $this->assertSame('preserve detail', $transfer->fresh()->provider_status_detail);
    }

    public function test_duplicate_preflight_matches_the_non_null_rows_enforced_by_the_constraint(): void
    {
        [$transfer, $user, $provider] = $this->transferFixture();
        $migration = $this->migration();
        $migration->down();
        Transfer::query()->create([
            ...$transfer->only([
                'user_id', 'provider_id', 'transfer_type', 'source_currency', 'target_currency', 'source_amount',
            ]),
            'transfer_no' => 'TRF-DUPLICATE',
            'client_reference' => 'OW-PRESERVED',
        ]);
        Transfer::query()->create([
            'transfer_no' => 'TRF-NULL-ONE', 'user_id' => $user->id, 'provider_id' => $provider->id,
            'transfer_type' => 'payout', 'source_currency' => 'USD', 'target_currency' => 'USD',
            'source_amount' => 1, 'client_reference' => null,
        ]);
        Transfer::query()->create([
            'transfer_no' => 'TRF-NULL-TWO', 'user_id' => $user->id, 'provider_id' => $provider->id,
            'transfer_type' => 'payout', 'source_currency' => 'USD', 'target_currency' => 'USD',
            'source_amount' => 1, 'client_reference' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $migration->up();
    }

    private function transferFixture(): array
    {
        $user = User::factory()->create();
        $provider = IntegrationProvider::query()->create(['code' => 'migration-provider', 'name' => 'Migration Provider']);
        $transfer = Transfer::query()->create([
            'transfer_no' => 'TRF-PRESERVED', 'user_id' => $user->id, 'provider_id' => $provider->id,
            'transfer_type' => 'payout', 'source_currency' => 'USD', 'target_currency' => 'USD',
            'source_amount' => 100, 'client_reference' => 'OW-PRESERVED',
            'provider_status' => 'CLEAR', 'provider_status_detail' => 'preserve detail',
            'provider_status_at' => now(),
        ]);

        return [$transfer, $user, $provider];
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_09_10_000001_add_transfer_client_reference_idempotency.php');
    }

    private function hasIndex(): bool
    {
        return collect(Schema::getIndexes('transfers'))
            ->contains(fn (array $index): bool => $index['name'] === 'transfers_user_provider_client_reference_unique');
    }
}
