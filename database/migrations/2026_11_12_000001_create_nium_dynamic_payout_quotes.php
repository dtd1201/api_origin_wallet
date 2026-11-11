<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nium_dynamic_payout_quotes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('provider_id')->constrained('integration_providers')->restrictOnDelete();
            $table->foreignId('provider_account_id')->constrained('user_provider_accounts')->restrictOnDelete();
            $table->foreignId('beneficiary_id')->constrained()->restrictOnDelete();
            $table->foreignId('balance_id')->constrained('balances')->restrictOnDelete();
            $table->string('quote_id', 100);
            $table->text('request_json');
            $table->text('response_json'); // Encrypted original provider body; no monetary float round trip.
            $table->jsonb('binding');
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('created_at', 6);
            $table->unique(['provider_id', 'quote_id']);
            $table->index(['user_id', 'beneficiary_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nium_dynamic_payout_quotes');
    }
};
