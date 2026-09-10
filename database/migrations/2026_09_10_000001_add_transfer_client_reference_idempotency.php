<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicate = DB::table('transfers')
            ->select(['user_id', 'provider_id', 'client_reference'])
            ->whereNotNull('client_reference')
            ->groupBy(['user_id', 'provider_id', 'client_reference'])
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException('Cannot add transfer client-reference idempotency constraint while duplicate references exist.');
        }

        Schema::table('transfers', function (Blueprint $table): void {
            $table->unique(['user_id', 'provider_id', 'client_reference'], 'transfers_user_provider_client_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table): void {
            $table->dropUnique('transfers_user_provider_client_reference_unique');
        });
    }
};
