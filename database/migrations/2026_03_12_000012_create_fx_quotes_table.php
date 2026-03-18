<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fx_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained('integration_providers')->restrictOnDelete();
            $table->string('quote_ref', 100)->unique();
            $table->char('source_currency', 3);
            $table->char('target_currency', 3);
            $table->decimal('source_amount', 20, 8);
            $table->decimal('target_amount', 20, 8);
            $table->decimal('mid_rate', 20, 10)->nullable();
            $table->decimal('net_rate', 20, 10)->nullable();
            $table->decimal('fee_amount', 20, 8)->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->jsonb('raw_data')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fx_quotes');
    }
};
