<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nium_rfi_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained('integration_providers')->restrictOnDelete();
            $table->foreignId('user_provider_account_id')->constrained('user_provider_accounts')->restrictOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignId('webhook_event_id')->nullable()->constrained('webhook_events')->nullOnDelete();
            $table->string('scope', 30);
            $table->string('provider_reference_fingerprint', 64);
            $table->string('status', 30)->default('requested')->index();
            $table->jsonb('evidence');
            $table->jsonb('response_draft')->nullable();
            $table->jsonb('supporting_file_ids')->nullable();
            $table->string('contract_gate', 80)->default('provider_contract_unconfirmed');
            $table->string('submission_state', 30)->default('not_claimed')->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->jsonb('provider_response_evidence')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
            $table->unique(['provider_id', 'user_provider_account_id', 'scope', 'provider_reference_fingerprint'], 'nium_rfi_cases_account_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nium_rfi_cases');
    }
};
