<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table): void {
            // Mutable processing metadata; original payload/evidence guards stay intact.
            $table->json('nium_va_recovery')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Nium VA recovery history requires forward-only recovery.');
    }
};
