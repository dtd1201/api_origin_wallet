<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nium_virtual_account_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_provider_account_id')->constrained('user_provider_accounts')->cascadeOnDelete();
            $table->foreignId('nium_virtual_account_id')->nullable()->constrained('nium_virtual_accounts')->nullOnDelete();
            $table->foreignId('api_request_log_id')->nullable()->constrained('api_request_logs')->nullOnDelete();
            $table->string('operation_id', 255);
            $table->char('currency', 3);
            $table->string('account_category', 50);
            $table->string('account_type', 50);
            $table->string('bank_name', 100)->nullable();
            $table->string('status', 30);
            $table->string('provider_payment_id', 255)->nullable();
            $table->json('baseline_payment_ids')->nullable();
            $table->boolean('baseline_complete')->default(false);
            $table->string('resolution_code', 80)->nullable();
            $table->timestamp('claimed_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(
                ['user_provider_account_id', 'status'],
                'nium_va_operations_account_status_idx',
            );
            $table->index(
                ['user_provider_account_id', 'provider_payment_id'],
                'nium_va_operations_payment_idx',
            );
            $table->unique(
                ['user_provider_account_id', 'operation_id'],
                'nium_va_operations_account_operation_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nium_virtual_account_operations');
    }
};
