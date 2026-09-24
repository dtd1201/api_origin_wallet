<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fx_orders', function (Blueprint $table) {
            $table->foreignId('fx_quote_id')
                ->nullable()
                ->after('provider_id')
                ->constrained('fx_quotes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fx_orders', function (Blueprint $table) {
            $table->dropForeign(['fx_quote_id']);
            $table->dropColumn('fx_quote_id');
        });
    }
};
