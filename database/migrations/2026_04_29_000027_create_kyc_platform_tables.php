<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('status', 30)->default('draft');
            $table->string('applicant_type', 20);
            $table->string('legal_name');
            $table->date('date_of_birth')->nullable();
            $table->char('nationality_country_code', 2)->nullable();
            $table->char('residence_country_code', 2)->nullable();
            $table->string('business_name')->nullable();
            $table->string('business_registration_number', 100)->nullable();
            $table->string('tax_id', 100)->nullable();
            $table->char('registered_country_code', 2)->nullable();
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('city', 100);
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 30)->nullable();
            $table->char('country_code', 2);
            $table->jsonb('metadata')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'submitted_at']);
        });

        Schema::create('kyc_related_persons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_profile_id')->constrained('kyc_profiles')->cascadeOnDelete();
            $table->string('relationship_type', 50);
            $table->string('status', 30)->default('submitted');
            $table->string('legal_name');
            $table->date('date_of_birth')->nullable();
            $table->char('nationality_country_code', 2)->nullable();
            $table->char('residence_country_code', 2)->nullable();
            $table->decimal('ownership_percentage', 5, 2)->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 30)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['kyc_profile_id', 'relationship_type']);
        });

        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_profile_id')->constrained('kyc_profiles')->cascadeOnDelete();
            $table->foreignId('kyc_related_person_id')->nullable()->constrained('kyc_related_persons')->nullOnDelete();
            $table->string('type', 50);
            $table->string('status', 30)->default('submitted');
            $table->string('file_url', 2048);
            $table->string('file_hash')->nullable();
            $table->string('side', 20)->nullable();
            $table->string('document_number', 100)->nullable();
            $table->char('issuing_country_code', 2)->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['kyc_profile_id', 'type', 'status']);
            $table->index(['kyc_related_person_id', 'type']);
        });

        Schema::create('kyc_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_profile_id')->constrained('kyc_profiles')->cascadeOnDelete();
            $table->string('key', 100);
            $table->string('label');
            $table->string('category', 50);
            $table->string('status', 30)->default('required');
            $table->string('requirement_type', 50);
            $table->string('subject_type', 50)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('review_note')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['kyc_profile_id', 'key']);
            $table->index(['status', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_requirements');
        Schema::dropIfExists('kyc_documents');
        Schema::dropIfExists('kyc_related_persons');
        Schema::dropIfExists('kyc_profiles');
    }
};
