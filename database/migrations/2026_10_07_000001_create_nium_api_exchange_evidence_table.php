<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nium_api_exchange_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('api_request_log_id')->unique()->constrained('api_request_logs')->cascadeOnDelete();
            $table->string('request_method', 10);
            $table->text('request_url');
            $table->string('request_content_type', 255)->nullable();
            $table->string('response_content_type', 255)->nullable();
            $table->longText('raw_request_body')->nullable();
            $table->longText('raw_response_body')->nullable();
            $table->longText('request_headers')->nullable();
            $table->longText('response_headers')->nullable();
            $table->char('request_body_sha256', 64)->nullable();
            $table->char('response_body_sha256', 64)->nullable();
            $table->char('request_headers_sha256', 64)->nullable();
            $table->char('response_headers_sha256', 64)->nullable();
            $table->unsignedBigInteger('request_byte_length')->nullable();
            $table->unsignedBigInteger('response_byte_length')->nullable();
            $table->unsignedSmallInteger('capture_version')->default(1);
            $table->boolean('response_received');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nium_api_exchange_evidence');
    }
};
