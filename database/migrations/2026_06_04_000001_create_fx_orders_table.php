<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 50)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained('integration_providers')->restrictOnDelete();
            $table->char('source_currency', 3);
            $table->char('target_currency', 3);
            $table->decimal('source_amount', 20, 8);
            $table->decimal('target_amount', 20, 8)->nullable();
            $table->decimal('fx_rate', 20, 10)->nullable();
            $table->decimal('fee_amount', 20, 8)->default(0);
            $table->char('fee_currency', 3)->nullable();
            $table->string('status', 30)->default('pending');
            $table->jsonb('customer_snapshot');
            $table->jsonb('raw_data')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_orders');
    }
};
