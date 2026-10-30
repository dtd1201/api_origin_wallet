<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nium_payout_pilot_authorizations', function (Blueprint $table): void {
            // Existing funded authorizations retain their meaning and history.
            $table->string('policy', 40)->default('funded_reserve');
            $table->unsignedBigInteger('funding_ledger_entry_id')->nullable()->change();
            $table->unsignedBigInteger('reserve_ledger_entry_id')->nullable()->change();
            $table->string('funding_sha256', 64)->nullable()->change();
            $table->decimal('charge_ceiling', 20, 8)->nullable()->change();
            $table->decimal('reserve_remaining', 20, 8)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('nium_payout_pilot_authorizations')->where('policy', 'one_time_uncapped')->exists()) {
            throw new RuntimeException('Retain the one-time authorization and its financial history.');
        }
        Schema::table('nium_payout_pilot_authorizations', function (Blueprint $table): void {
            $table->dropColumn('policy');
            $table->unsignedBigInteger('funding_ledger_entry_id')->nullable(false)->change();
            $table->unsignedBigInteger('reserve_ledger_entry_id')->nullable(false)->change();
            $table->string('funding_sha256', 64)->nullable(false)->change();
            $table->decimal('charge_ceiling', 20, 8)->nullable(false)->change();
            $table->decimal('reserve_remaining', 20, 8)->nullable(false)->change();
        });
    }
};
