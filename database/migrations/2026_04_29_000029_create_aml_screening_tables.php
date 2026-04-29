<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aml_screenings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('kyc_profile_id')->constrained('kyc_profiles')->cascadeOnDelete();
            $table->string('subject_type', 50);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_name');
            $table->string('subject_role', 50)->nullable();
            $table->string('screening_provider', 50)->default('internal');
            $table->string('status', 30)->default('pending');
            $table->string('risk_level', 30)->default('unknown');
            $table->unsignedTinyInteger('risk_score')->nullable();
            $table->timestamp('screened_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->jsonb('raw_data')->nullable();
            $table->timestamps();

            $table->index(['kyc_profile_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('aml_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aml_screening_id')->constrained('aml_screenings')->cascadeOnDelete();
            $table->string('list_type', 50);
            $table->string('source', 100);
            $table->string('matched_name');
            $table->decimal('score', 5, 2)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('external_reference')->nullable();
            $table->string('status', 30)->default('open');
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->jsonb('raw_data')->nullable();
            $table->timestamps();

            $table->index(['aml_screening_id', 'status']);
            $table->index(['list_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aml_matches');
        Schema::dropIfExists('aml_screenings');
    }
};
