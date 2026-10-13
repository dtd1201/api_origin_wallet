<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nium_manual_kyc_continuations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kyc_profile_correction_id')->unique()->constrained('kyc_profile_corrections')->restrictOnDelete();
            $table->foreignId('kyc_document_id')->constrained('kyc_documents')->restrictOnDelete();
            $table->foreignId('user_provider_account_id')->constrained('user_provider_accounts')->restrictOnDelete();
            $table->unsignedInteger('correction_version');
            $table->string('state', 30)->index();
            $table->unsignedSmallInteger('poll_attempts')->default(0);
            $table->uuid('claim_token')->nullable()->unique();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('deadline_at');
            $table->timestamp('submit_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('hold_code', 80)->nullable();
            $table->text('hold_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nium_manual_kyc_continuations');
    }
};
