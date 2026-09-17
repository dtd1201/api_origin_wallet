<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table): void {
            $table->string('provider_status', 60)->nullable()->after('status');
            $table->text('provider_status_detail')->nullable()->after('provider_status');
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table): void {
            $table->dropColumn(['provider_status', 'provider_status_detail']);
        });
    }
};
