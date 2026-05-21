<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_verification_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('kyc_profile_id')->nullable()->constrained('kyc_profiles')->nullOnDelete();
            $table->string('provider', 50)->default('origin_capture');
            $table->string('external_session_id', 120)->unique();
            $table->string('subject_type', 50);
            $table->string('status', 30)->default('created');
            $table->decimal('liveness_score', 5, 2)->nullable();
            $table->decimal('face_match_score', 5, 2)->nullable();
            $table->jsonb('document_ocr')->nullable();
            $table->jsonb('checks')->nullable();
            $table->jsonb('raw_response')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'subject_type', 'status']);
            $table->index(['provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_verification_sessions');
    }
};
