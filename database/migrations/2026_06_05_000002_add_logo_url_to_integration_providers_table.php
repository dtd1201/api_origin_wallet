<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_providers', function (Blueprint $table) {
            $table->string('logo_url', 2048)->nullable()->after('name');
        });

        DB::table('integration_providers')
            ->select(['id', 'code'])
            ->orderBy('id')
            ->get()
            ->each(function (object $provider): void {
                $normalizedCode = strtolower(trim((string) $provider->code));

                if ($normalizedCode !== $provider->code) {
                    DB::table('integration_providers')
                        ->where('id', $provider->id)
                        ->update(['code' => $normalizedCode]);
                }
            });

        if (Schema::hasTable('managed_exchange_rates')) {
            DB::table('managed_exchange_rates')
                ->where('rate_type', 'provider')
                ->select(['id', 'source_code'])
                ->orderBy('id')
                ->get()
                ->each(function (object $rate): void {
                    $normalizedCode = strtolower(trim((string) $rate->source_code));

                    if ($normalizedCode !== $rate->source_code) {
                        DB::table('managed_exchange_rates')
                            ->where('id', $rate->id)
                            ->update(['source_code' => $normalizedCode]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::table('integration_providers', function (Blueprint $table) {
            $table->dropColumn('logo_url');
        });
    }
};
