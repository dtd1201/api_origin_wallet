<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nium_virtual_account_details', function (Blueprint $table): void {
            $table->string('provider_bank_name')->nullable();
            $table->string('provider_account_type')->nullable();
            $table->string('provider_account_category')->nullable();
            $table->string('routing_code_type_2')->nullable();
            $table->string('routing_code_value_2')->nullable();
            // Compare the version observed BEFORE GET with the version locked after GET.
            $table->unsignedBigInteger('enrichment_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('nium_virtual_account_details', function (Blueprint $table): void {
            $table->dropColumn([
                'provider_bank_name', 'provider_account_type', 'provider_account_category',
                'routing_code_type_2', 'routing_code_value_2', 'enrichment_version',
            ]);
        });
    }
};
