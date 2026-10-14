<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nium_manual_kyc_resubmission_authorizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_provider_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('kyc_profile_correction_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('correction_version');
            $table->foreignId('prior_api_request_log_id')->unique()->constrained('api_request_logs')->restrictOnDelete();
            $table->foreignId('authorized_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('passport_document_id')->constrained('kyc_documents')->restrictOnDelete();
            $table->foreignId('proof_of_address_document_id')->nullable()->constrained('kyc_documents')->restrictOnDelete();
            $table->string('attempt_reference', 100)->unique();
            $table->string('state', 30)->index();
            $table->string('outcome_state', 30)->nullable();
            $table->char('applicant_reference_fingerprint', 64);
            $table->char('residence_fingerprint', 64);
            $table->char('passport_evidence_fingerprint', 64);
            $table->char('passport_file_id_fingerprint', 64);
            $table->char('proof_of_address_evidence_fingerprint', 64)->nullable();
            $table->char('proof_of_address_file_id_fingerprint', 64)->nullable();
            $table->text('reason');
            $table->timestamp('authorized_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamp('terminal_at')->nullable();
            $table->string('invalidation_code', 80)->nullable();
            $table->foreignId('new_api_request_log_id')->nullable()->unique()->constrained('api_request_logs')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['kyc_profile_correction_id', 'attempt_reference'], 'nium_manual_kyc_resubmission_correction_reference_unique');
        });

        Schema::table('nium_manual_kyc_continuations', function (Blueprint $table): void {
            $table->foreignId('resubmission_authorization_id')->nullable()->after('kyc_profile_correction_id')
                ->constrained('nium_manual_kyc_resubmission_authorizations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('nium_manual_kyc_continuations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('resubmission_authorization_id');
        });
        Schema::dropIfExists('nium_manual_kyc_resubmission_authorizations');
    }
};
