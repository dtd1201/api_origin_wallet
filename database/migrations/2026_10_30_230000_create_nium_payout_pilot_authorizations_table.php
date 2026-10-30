<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nium_payout_pilot_authorizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // A single exercise, not a renewable customer/corridor allowance.
            $table->string('pilot')->unique();
            $table->foreignId('transfer_id')->unique()->constrained('transfers')->restrictOnDelete();
            $table->foreignId('funding_ledger_entry_id')->unique()->constrained('ledger_entries')->restrictOnDelete();
            $table->foreignId('reserve_ledger_entry_id')->unique()->constrained('ledger_entries')->restrictOnDelete();
            $table->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $table->jsonb('binding');
            $table->jsonb('verification');
            $table->string('funding_sha256', 64);
            $table->decimal('charge_ceiling', 20, 8);
            $table->decimal('reserve_remaining', 20, 8);
            $table->timestamp('expires_at');
            $table->string('consumed_operation_key')->nullable()->unique();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Never silently remove the durable permission or its still-funded reserve.
        if (DB::table('nium_payout_pilot_authorizations')->exists()) {
            throw new RuntimeException('Pilot authorizations require financial review before rollback.');
        }
        Schema::dropIfExists('nium_payout_pilot_authorizations');
    }
};
