<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('integration_providers')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('related_transfer_id')->nullable()->constrained('transfers')->nullOnDelete();
            $table->string('request_method', 10);
            $table->text('request_url');
            $table->jsonb('request_headers')->nullable();
            $table->jsonb('request_body')->nullable();
            $table->integer('response_status')->nullable();
            $table->jsonb('response_headers')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->boolean('is_success')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
    }
};
