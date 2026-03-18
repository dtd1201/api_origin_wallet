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
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_no', 50)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained('integration_providers')->restrictOnDelete();
            $table->foreignId('source_bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('beneficiary_id')->nullable()->constrained('beneficiaries')->nullOnDelete();
            $table->string('external_transfer_id')->nullable();
            $table->string('external_payment_id')->nullable();
            $table->string('transfer_type', 30);
            $table->char('source_currency', 3);
            $table->char('target_currency', 3);
            $table->decimal('source_amount', 20, 8);
            $table->decimal('target_amount', 20, 8)->nullable();
            $table->decimal('fx_rate', 20, 10)->nullable();
            $table->decimal('fee_amount', 20, 8)->default(0);
            $table->char('fee_currency', 3)->nullable();
            $table->string('purpose_code', 100)->nullable();
            $table->string('reference_text')->nullable();
            $table->string('client_reference')->nullable();
            $table->string('status', 30)->default('draft');
            $table->string('failure_code', 100)->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
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
        Schema::dropIfExists('transfers');
    }
};
