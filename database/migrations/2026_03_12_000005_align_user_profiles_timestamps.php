<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('user_profiles') || DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE user_profiles ALTER COLUMN created_at SET DEFAULT NOW()');
        DB::statement('ALTER TABLE user_profiles ALTER COLUMN created_at SET NOT NULL');
        DB::statement('ALTER TABLE user_profiles ALTER COLUMN updated_at SET DEFAULT NOW()');
        DB::statement('ALTER TABLE user_profiles ALTER COLUMN updated_at SET NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('user_profiles') || DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE user_profiles ALTER COLUMN created_at DROP DEFAULT');
        DB::statement('ALTER TABLE user_profiles ALTER COLUMN created_at DROP NOT NULL');
        DB::statement('ALTER TABLE user_profiles ALTER COLUMN updated_at DROP DEFAULT');
        DB::statement('ALTER TABLE user_profiles ALTER COLUMN updated_at DROP NOT NULL');
    }
};
