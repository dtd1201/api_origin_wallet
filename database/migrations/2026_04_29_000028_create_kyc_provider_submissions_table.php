<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_provider_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('kyc_profile_id')->nullable()->constrained('kyc_profiles')->nullOnDelete();
            $table->foreignId('provider_id')->constrained('integration_providers')->cascadeOnDelete();
            $table->foreignId('provider_account_id')->nullable()->constrained('user_provider_accounts')->nullOnDelete();
            $table->string('status', 30)->default('pending');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('review_note')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('failure_reason')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider_id']);
            $table->index(['provider_id', 'status']);
            $table->index(['status', 'approved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_provider_submissions');
    }
};
