<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managed_exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('rate_type', 30);
            $table->string('audience', 30)->default('public');
            $table->foreignId('provider_id')->nullable()->constrained('integration_providers')->nullOnDelete();
            $table->string('source_code', 100);
            $table->string('source_name');
            $table->char('source_currency', 3);
            $table->char('target_currency', 3);
            $table->decimal('buy_rate', 20, 8)->nullable();
            $table->decimal('sell_rate', 20, 8)->nullable();
            $table->decimal('mid_rate', 20, 8)->nullable();
            $table->decimal('fee_amount', 20, 8)->default(0);
            $table->string('status', 30)->default('active');
            $table->unsignedInteger('display_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['rate_type', 'audience', 'source_code', 'source_currency', 'target_currency'],
                'managed_rates_unique_quote'
            );
            $table->index(['rate_type', 'audience', 'status'], 'managed_rates_type_audience_status_idx');
            $table->index(['source_currency', 'target_currency'], 'managed_rates_currency_pair_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_exchange_rates');
    }
};
