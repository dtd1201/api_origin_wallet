<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('user_provider_accounts')
            ->select('user_id', 'provider_id', DB::raw('COUNT(*) AS duplicate_count'))
            ->groupBy('user_id', 'provider_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $details = $duplicates->map(function ($duplicate): array {
                return [
                    'user_id' => $duplicate->user_id,
                    'provider_id' => $duplicate->provider_id,
                    'record_ids' => DB::table('user_provider_accounts')
                        ->where('user_id', $duplicate->user_id)
                        ->where('provider_id', $duplicate->provider_id)
                        ->orderBy('id')
                        ->pluck('id')
                        ->all(),
                ];
            })->all();

            throw new RuntimeException(
                'Cannot enforce one provider account per user/provider. Resolve duplicates manually: '.json_encode($details),
            );
        }

        Schema::table('user_provider_accounts', function (Blueprint $table): void {
            $table->string('external_reference')->nullable()->after('external_account_id');
            $table->string('provider_status', 40)->nullable()->after('status');
            $table->string('provider_sub_status', 60)->nullable()->after('provider_status');
            $table->string('compliance_status', 60)->nullable()->after('provider_sub_status');
            $table->string('rfi_status', 60)->nullable()->after('compliance_status');
            $table->string('odd_status', 60)->nullable()->after('rfi_status');
            $table->timestamp('customer_id_verified_at')->nullable()->after('odd_status');
            $table->timestamp('wallet_id_verified_at')->nullable()->after('customer_id_verified_at');
            $table->timestamp('provider_ids_verified_at')->nullable()->after('wallet_id_verified_at');
            $table->timestamp('provider_status_updated_at')->nullable()->after('provider_ids_verified_at');
            $table->timestamp('security_conflict_at')->nullable()->after('provider_status_updated_at');
            $table->string('security_conflict_reason', 100)->nullable()->after('security_conflict_at');
            $table->string('reconciliation_status', 40)->nullable()->after('security_conflict_reason');
            $table->text('reconciliation_error')->nullable()->after('reconciliation_status');
            $table->timestamp('reconciliation_requested_at')->nullable()->after('reconciliation_error');
            $table->timestamp('reconciled_at')->nullable()->after('reconciliation_requested_at');

            $table->unique(['provider_id', 'external_reference'], 'provider_accounts_provider_external_ref_unique');
            $table->unique(['user_id', 'provider_id'], 'provider_accounts_user_provider_unique');
            $table->index(['provider_id', 'reconciliation_status'], 'provider_accounts_reconciliation_idx');
        });
    }

    public function down(): void
    {
        Schema::table('user_provider_accounts', function (Blueprint $table): void {
            $table->dropIndex('provider_accounts_reconciliation_idx');
            $table->dropUnique('provider_accounts_user_provider_unique');
            $table->dropUnique('provider_accounts_provider_external_ref_unique');
            $table->dropColumn([
                'external_reference',
                'provider_status',
                'provider_sub_status',
                'compliance_status',
                'rfi_status',
                'odd_status',
                'customer_id_verified_at',
                'wallet_id_verified_at',
                'provider_ids_verified_at',
                'provider_status_updated_at',
                'security_conflict_at',
                'security_conflict_reason',
                'reconciliation_status',
                'reconciliation_error',
                'reconciliation_requested_at',
                'reconciled_at',
            ]);
        });
    }
};
