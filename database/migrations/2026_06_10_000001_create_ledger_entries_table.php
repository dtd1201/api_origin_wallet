<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('balance_id')->nullable()->constrained('balances')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('integration_providers')->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('entry_type', 50);
            $table->string('status', 30)->default('posted');
            $table->char('currency', 3);
            $table->decimal('amount', 20, 8);
            $table->decimal('balance_after', 20, 8)->nullable();
            $table->string('source_type', 100)->nullable();
            $table->string('source_id', 100)->nullable();
            $table->text('description')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->jsonb('raw_data')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['provider_id', 'created_at']);
            $table->index(['currency', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['source_type', 'source_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
