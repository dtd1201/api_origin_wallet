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
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained('integration_providers')->restrictOnDelete();
            $table->string('external_account_id')->nullable();
            $table->string('account_type', 50)->nullable();
            $table->char('currency', 3);
            $table->char('country_code', 2)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_code', 100)->nullable();
            $table->string('branch_code', 100)->nullable();
            $table->string('account_name')->nullable();
            $table->string('account_number', 100)->nullable();
            $table->string('iban', 100)->nullable();
            $table->string('swift_bic', 50)->nullable();
            $table->string('routing_number', 100)->nullable();
            $table->string('status', 30)->default('active');
            $table->boolean('is_default')->default(false);
            $table->jsonb('raw_data')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
