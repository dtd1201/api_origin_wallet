<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table): void {
            $table->string('payout_method', 50)->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table): void {
            $table->dropColumn('payout_method');
        });
    }
};
