<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nium_virtual_account_details', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('nium_virtual_account_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('account_name')->nullable();
            $table->string('bank_name')->nullable();
            $table->text('bank_address')->nullable();

            $table->string('routing_code_type')->nullable();
            $table->string('routing_code_value')->nullable();

            $table->timestamps();

            $table->unique('nium_virtual_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nium_virtual_account_details');
    }
};
