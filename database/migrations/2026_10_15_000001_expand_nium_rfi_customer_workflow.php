<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nium_rfi_cases', function (Blueprint $table): void {
            $table->dropUnique('nium_rfi_cases_account_reference_unique');
            $table->string('origin', 30)->default('webhook')->after('scope');
            $table->text('user_explanation')->nullable();
            $table->text('revision_reason')->nullable();
            $table->timestamp('sent_to_user_at')->nullable();
            $table->timestamp('user_submitted_at')->nullable();
            $table->timestamp('admin_reviewed_at')->nullable();
            $table->unique(['provider_id', 'user_provider_account_id', 'scope', 'origin', 'provider_reference_fingerprint'], 'nium_rfi_cases_scoped_identity_unique');
        });

        Schema::create('nium_rfi_case_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nium_rfi_case_id')->constrained('nium_rfi_cases')->restrictOnDelete();
            $table->foreignId('kyc_document_id')->constrained('kyc_documents')->restrictOnDelete();
            $table->foreignId('attached_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('detached_at')->nullable();
            $table->timestamps();
            $table->unique(['nium_rfi_case_id', 'kyc_document_id'], 'nium_rfi_case_document_unique');
        });

        Schema::create('nium_rfi_case_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nium_rfi_case_id')->constrained('nium_rfi_cases')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 60);
            $table->string('from_state', 30)->nullable();
            $table->string('to_state', 30)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['nium_rfi_case_id', 'created_at'], 'nium_rfi_case_events_history_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nium_rfi_case_events');
        Schema::dropIfExists('nium_rfi_case_documents');
        Schema::table('nium_rfi_cases', function (Blueprint $table): void {
            $table->dropUnique('nium_rfi_cases_scoped_identity_unique');
            $table->dropColumn(['origin', 'user_explanation', 'revision_reason', 'sent_to_user_at', 'user_submitted_at', 'admin_reviewed_at']);
            $table->unique(['provider_id', 'user_provider_account_id', 'scope', 'provider_reference_fingerprint'], 'nium_rfi_cases_account_reference_unique');
        });
    }
};
