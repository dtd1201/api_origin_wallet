<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_correction_sync_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_profile_correction_id')->constrained('kyc_profile_corrections')->cascadeOnDelete();
            $table->unsignedInteger('correction_version');
            $table->uuid('request_id')->unique();
            $table->char('request_fingerprint', 64);
            $table->string('status', 30);
            $table->unsignedTinyInteger('active_slot')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('verification_result')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->foreignId('started_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('put_completed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['kyc_profile_correction_id', 'correction_version', 'active_slot'], 'kyc_correction_sync_active_unique');
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_correction_sync_attempts');
    }
};
