<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aml_screenings', function (Blueprint $table): void {
            $table->string('provider', 100)->nullable()->after('screening_provider');
            $table->string('screening_reference')->nullable()->after('provider');
            $table->string('screening_result', 30)->nullable()->after('screening_reference');
            $table->string('compliance_decision', 30)->default('pending_review')->after('status');
            $table->timestamp('completed_at')->nullable()->after('screened_at');
            $table->jsonb('result_summary')->nullable()->after('completed_at');
            $table->timestamp('superseded_at')->nullable()->after('result_summary');

            $table->unique(['provider', 'screening_reference']);
            $table->index(['user_id', 'compliance_decision']);
        });

        DB::table('aml_screenings')->update([
            'provider' => DB::raw('screening_provider'),
            'screening_result' => DB::raw("CASE WHEN status IN ('clear', 'manual_clear') THEN 'clear' WHEN status IN ('potential_match', 'confirmed_match') THEN 'match' ELSE NULL END"),
            'compliance_decision' => DB::raw("CASE WHEN status IN ('clear', 'manual_clear') THEN 'clear' WHEN status = 'confirmed_match' THEN 'rejected' ELSE 'pending_review' END"),
            'completed_at' => DB::raw("CASE WHEN status IN ('clear', 'potential_match', 'manual_clear', 'confirmed_match') THEN screened_at ELSE NULL END"),
            'superseded_at' => DB::raw("CASE WHEN status = 'superseded' THEN updated_at ELSE NULL END"),
            'status' => DB::raw("CASE WHEN status = 'failed' THEN 'failed' WHEN status = 'potential_match' THEN 'manual_review' WHEN status IN ('clear', 'manual_clear', 'confirmed_match') THEN 'completed' ELSE 'pending' END"),
        ]);
    }

    public function down(): void
    {
        Schema::table('aml_screenings', function (Blueprint $table): void {
            $table->dropUnique(['provider', 'screening_reference']);
            $table->dropIndex(['user_id', 'compliance_decision']);
            $table->dropColumn([
                'provider',
                'screening_reference',
                'screening_result',
                'compliance_decision',
                'completed_at',
                'result_summary',
                'superseded_at',
            ]);
        });
    }
};
