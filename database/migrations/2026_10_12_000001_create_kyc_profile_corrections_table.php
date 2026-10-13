<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_profile_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_profile_id')->constrained('kyc_profiles')->cascadeOnDelete();
            $table->foreignId('applicant_related_person_id')->constrained('kyc_related_persons')->restrictOnDelete();
            $table->foreignId('user_provider_account_id')->constrained('user_provider_accounts')->restrictOnDelete();
            $table->unsignedInteger('correction_version');
            $table->char('source_fingerprint', 64);
            $table->text('customer_hash_id');
            $table->text('applicant_reference_id');
            $table->text('applicant_external_id');
            $table->char('customer_hash_fingerprint', 64);
            $table->char('applicant_reference_fingerprint', 64);
            $table->char('applicant_external_fingerprint', 64);
            $table->text('before_snapshot');
            $table->text('proposed_values');
            $table->text('requested_fields')->nullable();
            $table->text('requested_document_types')->nullable();
            $table->text('proposed_documents')->nullable();
            $table->text('request_instruction')->nullable();
            $table->string('request_origin', 30)->default('customer');
            $table->text('approved_source_snapshot')->nullable();
            $table->char('approved_source_fingerprint', 64)->nullable();
            $table->string('status', 30)->default('submitted');
            $table->unsignedTinyInteger('active_slot')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['kyc_profile_id', 'correction_version']);
            $table->unique(['kyc_profile_id', 'active_slot']);
            $table->index(['status', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_profile_corrections');
    }
};
