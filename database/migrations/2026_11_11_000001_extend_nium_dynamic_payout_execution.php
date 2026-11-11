<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nium_dynamic_payout_outbox', function (Blueprint $table): void {
            $table->uuid('claim_token')->nullable();
            $table->uuid('operation_key')->nullable()->unique();
            $table->text('request_json')->nullable();
            $table->char('request_sha256', 64)->nullable();
            $table->string('financial_state', 40)->default('pending');
            $table->string('financial_risk_state', 50)->default('PROVIDER_ACCOUNTING_UNVERIFIED');
            $table->string('provider_status', 40)->nullable();
            $table->string('exception_code', 100)->nullable();
            $table->unsignedInteger('reconciliation_attempts')->default(0);
            $table->timestampTz('next_reconciliation_at', 6)->nullable()->index();
            $table->uuid('reconciliation_token')->nullable();
            $table->timestampTz('reconciliation_claimed_at', 6)->nullable();
            $table->timestampTz('reconciled_at', 6)->nullable();
            $table->text('financial_evidence')->nullable();
        });
        Schema::table('nium_dynamic_reservations', function (Blueprint $table): void {
            $table->foreignId('debit_entry_id')->nullable()->unique()->constrained('ledger_entries')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE nium_dynamic_payout_outbox DROP CONSTRAINT nium_dynamic_outbox_never_dispatchable');
            // dispatchable remains false: only the guarded dispatcher can claim held rows.
            DB::statement("ALTER TABLE nium_dynamic_payout_outbox ADD CONSTRAINT nium_dynamic_outbox_execution_state CHECK (dispatchable = false AND attempts >= 0 AND attempts <= 1 AND state IN ('held','cancelled','claimed','submitted','ambiguous','matched','exception'))");
            DB::statement('ALTER TABLE nium_dynamic_reservations DROP CONSTRAINT nium_dynamic_reservation_state');
            DB::statement("ALTER TABLE nium_dynamic_reservations ADD CONSTRAINT nium_dynamic_reservation_state CHECK (amount > 0 AND state IN ('held','released','consumed') AND (state <> 'consumed' OR (debit_entry_id IS NOT NULL AND release_entry_id IS NULL)))");
        }
    }

    public function down(): void
    {
        // Never erase a durable submission claim, provider reference or financial receipt.
        throw new RuntimeException('Dynamic execution migration is forward-only; disable workers for rollback.');
    }
};
