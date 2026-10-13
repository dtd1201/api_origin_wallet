<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nium_manual_kyc_continuations', function (Blueprint $table): void {
            $table->char('file_id_fingerprint', 64)->nullable()->after('correction_version');
            $table->timestamp('submit_claim_expires_at')->nullable()->index()->after('submit_started_at');
        });

        // Existing undeployed/test rows must fail closed until their File ID binding is reviewed.
        DB::table('nium_manual_kyc_continuations')
            ->whereNull('file_id_fingerprint')
            ->update([
                'state' => 'HOLD',
                'hold_code' => 'file_binding_migration_required',
                'hold_message' => 'The continuation predates durable Nium File ID binding and requires operator reconciliation.',
                'claim_token' => null,
                'claimed_at' => null,
                'next_attempt_at' => null,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('nium_manual_kyc_continuations', function (Blueprint $table): void {
            $table->dropIndex(['submit_claim_expires_at']);
            $table->dropColumn(['file_id_fingerprint', 'submit_claim_expires_at']);
        });
    }
};
