<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_logins', function (Blueprint $table): void {
            $table->timestamp('verification_sent_at')
                ->nullable()
                ->after('last_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::table('pending_logins', function (Blueprint $table): void {
            $table->dropColumn('verification_sent_at');
        });
    }
};
