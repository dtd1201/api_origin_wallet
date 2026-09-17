<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_company_directors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_profile_id')
                ->constrained('kyc_profiles')
                ->cascadeOnDelete();

            $table->string('legal_name');
            $table->date('date_of_birth')->nullable();
            $table->char('nationality_country_code', 2)->nullable();
            $table->char('residence_country_code', 2)->nullable();

            $table->string('position', 100)->nullable();

            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 30)->nullable();
            $table->char('country_code', 2)->nullable();

            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index('kyc_profile_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_company_directors');
    }
};
