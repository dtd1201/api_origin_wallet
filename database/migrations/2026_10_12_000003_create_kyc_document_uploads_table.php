<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_document_uploads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('kyc_profile_correction_id')->nullable()->constrained('kyc_profile_corrections')->restrictOnDelete();
            $table->string('storage_disk', 50);
            $table->string('file_path', 2048);
            $table->char('file_hash', 64);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->string('status', 20)->default('uploaded');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('referenced_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->unique(['storage_disk', 'file_path']);
            $table->index(['status', 'expires_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_document_uploads');
    }
};
