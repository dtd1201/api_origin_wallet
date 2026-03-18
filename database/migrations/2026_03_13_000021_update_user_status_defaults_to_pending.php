<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE users ALTER COLUMN status SET DEFAULT 'pending'");
        DB::statement("ALTER TABLE users ALTER COLUMN kyc_status SET DEFAULT 'pending'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE users ALTER COLUMN status SET DEFAULT 'active'");
        DB::statement("ALTER TABLE users ALTER COLUMN kyc_status SET DEFAULT 'unverified'");
    }
};
