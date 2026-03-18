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
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('integration_providers')->restrictOnDelete();
            $table->foreignId('webhook_endpoint_id')->nullable()->constrained('webhook_endpoints')->nullOnDelete();
            $table->string('event_id')->nullable();
            $table->string('event_type', 100);
            $table->string('external_resource_id')->nullable();
            $table->jsonb('payload');
            $table->string('signature', 500)->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->string('processing_status', 30)->default('pending');
            $table->text('error_message')->nullable();

            $table->unique(['provider_id', 'event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
