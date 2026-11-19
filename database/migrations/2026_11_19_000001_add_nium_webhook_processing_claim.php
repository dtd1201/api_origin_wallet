<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_events', fn (Blueprint $table) => $table->jsonb('nium_processing_claim')->nullable());
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve webhook claim history; roll back application code with workers stopped.');
    }
};
