<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 30)->nullable()->after('email');
            }

            if (! Schema::hasColumn('users', 'full_name')) {
                $table->string('full_name')->nullable()->after('phone');
            }

            if (! Schema::hasColumn('users', 'password_hash')) {
                $table->text('password_hash')->nullable()->after('full_name');
            }

            if (! Schema::hasColumn('users', 'status')) {
                $table->string('status', 30)->default('active')->after('password_hash');
            }

            if (! Schema::hasColumn('users', 'kyc_status')) {
                $table->string('kyc_status', 30)->default('unverified')->after('status');
            }
        });

        if (Schema::hasColumn('users', 'name')) {
            DB::statement('UPDATE users SET full_name = COALESCE(full_name, name) WHERE name IS NOT NULL');
        }

        if (Schema::hasColumn('users', 'password')) {
            DB::statement('UPDATE users SET password_hash = COALESCE(password_hash, password) WHERE password IS NOT NULL');
        }

        DB::statement("UPDATE users SET status = COALESCE(status, 'active')");
        DB::statement("UPDATE users SET kyc_status = COALESCE(kyc_status, 'unverified')");

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE users ALTER COLUMN password_hash SET NOT NULL');
            DB::statement("ALTER TABLE users ALTER COLUMN status SET DEFAULT 'active'");
            DB::statement('ALTER TABLE users ALTER COLUMN status SET NOT NULL');
            DB::statement("ALTER TABLE users ALTER COLUMN kyc_status SET DEFAULT 'unverified'");
            DB::statement('ALTER TABLE users ALTER COLUMN kyc_status SET NOT NULL');
            DB::statement('ALTER TABLE users ALTER COLUMN created_at SET DEFAULT NOW()');
            DB::statement('ALTER TABLE users ALTER COLUMN created_at SET NOT NULL');
            DB::statement('ALTER TABLE users ALTER COLUMN updated_at SET DEFAULT NOW()');
            DB::statement('ALTER TABLE users ALTER COLUMN updated_at SET NOT NULL');
        }

        Schema::table('users', function (Blueprint $table) {
            $columnsToDrop = [];

            foreach (['name', 'email_verified_at', 'password', 'remember_token'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $columnsToDrop[] = $column;
                }
            }

            if ($columnsToDrop !== []) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'name')) {
                $table->string('name')->nullable()->after('id');
            }

            if (! Schema::hasColumn('users', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable()->after('email');
            }

            if (! Schema::hasColumn('users', 'password')) {
                $table->string('password')->nullable()->after('email_verified_at');
            }

            if (! Schema::hasColumn('users', 'remember_token')) {
                $table->string('remember_token', 100)->nullable()->after('password');
            }
        });

        if (Schema::hasColumn('users', 'full_name')) {
            DB::statement('UPDATE users SET name = COALESCE(name, full_name) WHERE full_name IS NOT NULL');
        }

        if (Schema::hasColumn('users', 'password_hash')) {
            DB::statement('UPDATE users SET password = COALESCE(password, password_hash) WHERE password_hash IS NOT NULL');
        }

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE users ALTER COLUMN password DROP NOT NULL');
            DB::statement('ALTER TABLE users ALTER COLUMN created_at DROP DEFAULT');
            DB::statement('ALTER TABLE users ALTER COLUMN created_at DROP NOT NULL');
            DB::statement('ALTER TABLE users ALTER COLUMN updated_at DROP DEFAULT');
            DB::statement('ALTER TABLE users ALTER COLUMN updated_at DROP NOT NULL');
        }

        Schema::table('users', function (Blueprint $table) {
            $columnsToDrop = [];

            foreach (['phone', 'full_name', 'password_hash', 'status', 'kyc_status'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $columnsToDrop[] = $column;
                }
            }

            if ($columnsToDrop !== []) {
                $table->dropColumn($columnsToDrop);
            }
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE users ALTER COLUMN name SET NOT NULL');
            DB::statement('ALTER TABLE users ALTER COLUMN password SET NOT NULL');
        }
    }
};
