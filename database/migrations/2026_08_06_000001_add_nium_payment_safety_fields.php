<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table): void {
            $table->foreignId('fx_quote_id')->nullable()->after('beneficiary_id')->constrained('fx_quotes')->restrictOnDelete();
            $table->string('provider_operation_key', 100)->nullable()->after('client_reference');
            $table->timestamp('provider_status_at')->nullable()->after('submitted_at');
            $table->unique(['provider_id', 'provider_operation_key'], 'transfers_provider_operation_unique');
        });

        Schema::table('user_provider_accounts', function (Blueprint $table): void {
            $table->timestamp('transactions_last_synced_at')->nullable();
        });

        Schema::create('nium_virtual_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_provider_account_id')->constrained('user_provider_accounts')->cascadeOnDelete();
            $table->string('provider_payment_id', 255);
            $table->string('virtual_account_reference', 255)->nullable();
            $table->char('currency', 3);
            $table->string('account_category', 50)->nullable();
            $table->string('account_type', 50)->nullable();
            $table->string('status', 30)->default('requested');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();
            $table->unique(['user_provider_account_id', 'provider_payment_id'], 'nium_virtual_accounts_payment_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nium_virtual_accounts');

        Schema::table('user_provider_accounts', function (Blueprint $table): void {
            $table->dropColumn('transactions_last_synced_at');
        });

        Schema::table('transfers', function (Blueprint $table): void {
            $table->dropUnique('transfers_provider_operation_unique');
            $table->dropConstrainedForeignId('fx_quote_id');
            $table->dropColumn(['provider_operation_key', 'provider_status_at']);
        });
    }
};
