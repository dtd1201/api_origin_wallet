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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained('integration_providers')->restrictOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('transfer_id')->nullable()->constrained('transfers')->nullOnDelete();
            $table->string('external_transaction_id');
            $table->string('transaction_type', 50)->nullable();
            $table->string('direction', 10)->nullable();
            $table->char('currency', 3);
            $table->decimal('amount', 20, 8);
            $table->decimal('fee_amount', 20, 8)->default(0);
            $table->text('description')->nullable();
            $table->string('reference_text')->nullable();
            $table->string('status', 30)->nullable();
            $table->timestamp('booked_at')->nullable();
            $table->date('value_date')->nullable();
            $table->jsonb('raw_data')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['provider_id', 'external_transaction_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
