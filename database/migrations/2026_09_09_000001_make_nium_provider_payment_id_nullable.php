<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nium_virtual_accounts', function (Blueprint $table): void {
            $table->string('provider_payment_id', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('nium_virtual_accounts', function (Blueprint $table): void {
            $table->string('provider_payment_id', 255)->nullable(false)->change();
        });
    }
};
