<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nium_va_detail_enrichments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id')->unique()->constrained('nium_virtual_account_operations')->restrictOnDelete();
            $table->foreignId('nium_virtual_account_id')->unique()->constrained('nium_virtual_accounts')->restrictOnDelete();
            $table->string('state', 24)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->uuid('claim_token')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('delivery_until')->nullable();
            $table->string('last_result', 32)->nullable();
            $table->timestamps();
            $table->index(['state', 'available_at']);
        });
        Schema::create('nium_va_detail_rate_limits', function (Blueprint $table): void {
            // No customer, wallet, credentials or banking evidence in operational state.
            $table->string('target_key', 64)->primary();
            $table->timestamp('window_started_at');
            $table->unsignedInteger('requests')->default(0);
            $table->timestamp('blocked_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nium_va_detail_rate_limits');
        Schema::dropIfExists('nium_va_detail_enrichments');
    }
};
