<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nium_corporate_constants', function (Blueprint $table): void {
            $table->id();
            $table->string('region', 10);
            $table->string('customer_type', 20);
            $table->string('country_code', 2);
            $table->string('constant_type', 30);
            $table->json('values');
            $table->timestamp('fetched_at');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['region', 'customer_type', 'country_code', 'constant_type'], 'nium_constants_dimensions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nium_corporate_constants');
    }
};
