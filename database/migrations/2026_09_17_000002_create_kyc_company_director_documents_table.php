<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_company_director_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('kyc_company_director_id')
                ->constrained('kyc_company_directors')
                ->cascadeOnDelete();

            $table->string('type', 100);
            $table->string('status', 30)->default('submitted');

            $table->string('file_url', 2048);
            $table->string('storage_disk', 50)->nullable();
            $table->string('file_path', 2048)->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('file_hash')->nullable();

            $table->string('side', 20)->nullable();
            $table->string('document_number', 100)->nullable();
            $table->char('issuing_country_code', 2)->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();

            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index('kyc_company_director_id');
            $table->index('file_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_company_director_documents');
    }
};
