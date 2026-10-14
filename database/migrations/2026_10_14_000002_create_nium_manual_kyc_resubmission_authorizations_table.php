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
            $table->foreignId('user_provider_account_id');
            $table->foreignId('kyc_profile_correction_id');
            $table->unsignedInteger('correction_version');
            $table->foreignId('prior_api_request_log_id');
            $table->foreignId('authorized_by_user_id');
            $table->foreignId('passport_document_id');
            $table->foreignId('proof_of_address_document_id')->nullable();
            $table->string('attempt_reference', 100);
            $table->string('state', 30);
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
            $table->foreignId('new_api_request_log_id')->nullable();
            $table->timestamps();

            $table->foreign('user_provider_account_id', 'nmkra_provider_account_fk')
                ->references('id')->on('user_provider_accounts')->restrictOnDelete();
            $table->foreign('kyc_profile_correction_id', 'nmkra_correction_fk')
                ->references('id')->on('kyc_profile_corrections')->restrictOnDelete();
            $table->foreign('prior_api_request_log_id', 'nmkra_prior_log_fk')
                ->references('id')->on('api_request_logs')->restrictOnDelete();
            $table->foreign('authorized_by_user_id', 'nmkra_authorized_by_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('passport_document_id', 'nmkra_passport_document_fk')
                ->references('id')->on('kyc_documents')->restrictOnDelete();
            $table->foreign('proof_of_address_document_id', 'nmkra_address_document_fk')
                ->references('id')->on('kyc_documents')->restrictOnDelete();
            $table->foreign('new_api_request_log_id', 'nmkra_new_log_fk')
                ->references('id')->on('api_request_logs')->restrictOnDelete();

            $table->unique('prior_api_request_log_id', 'nmkra_prior_log_uq');
            $table->unique('attempt_reference', 'nmkra_attempt_reference_uq');
            $table->unique('new_api_request_log_id', 'nmkra_new_log_uq');
            $table->index('state', 'nmkra_state_idx');
            $table->unique(['kyc_profile_correction_id', 'attempt_reference'], 'nmkra_correction_reference_uq');
        });

        Schema::table('nium_manual_kyc_continuations', function (Blueprint $table): void {
            $table->foreignId('resubmission_authorization_id')->nullable()->after('kyc_profile_correction_id');
            $table->foreign('resubmission_authorization_id', 'nmkc_resubmission_auth_fk')
                ->references('id')->on('nium_manual_kyc_resubmission_authorizations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('nium_manual_kyc_continuations', function (Blueprint $table): void {
            $table->dropForeign('nmkc_resubmission_auth_fk');
            $table->dropColumn('resubmission_authorization_id');
        });
        Schema::dropIfExists('nium_manual_kyc_resubmission_authorizations');
    }
};
