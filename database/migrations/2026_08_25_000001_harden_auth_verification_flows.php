<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_logins', function (Blueprint $table): void {
            $table->string('verification_code', 6)->nullable()->change();
        });

        Schema::table('pending_logins', function (Blueprint $table): void {
            $table->string('verification_code_hash')->nullable()->after('verification_code');
            $table->unsignedSmallInteger('verification_attempts')->default(0)->after('verification_code_hash');
            $table->timestamp('locked_until')->nullable()->after('verification_attempts');
            $table->timestamp('last_attempt_at')->nullable()->after('locked_until');
        });

        DB::table('pending_logins')
            ->whereNotNull('verification_code')
            ->orderBy('id')
            ->eachById(function (object $pendingLogin): void {
                DB::table('pending_logins')->where('id', $pendingLogin->id)->update([
                    'verification_code_hash' => Hash::make((string) $pendingLogin->verification_code),
                    'verification_code' => null,
                ]);
            });

        Schema::table('password_reset_tokens', function (Blueprint $table): void {
            $table->unsignedSmallInteger('verification_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
        });

        Schema::create('auth_security_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 80)->index();
            $table->string('account_identifier', 64)->nullable()->index();
            $table->string('ip_address', 45)->nullable()->index();
            $table->string('user_agent', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_security_events');

        Schema::table('password_reset_tokens', function (Blueprint $table): void {
            $table->dropColumn(['verification_attempts', 'locked_until', 'last_attempt_at']);
        });

        Schema::table('pending_logins', function (Blueprint $table): void {
            $table->dropColumn([
                'verification_code_hash',
                'verification_attempts',
                'locked_until',
                'last_attempt_at',
            ]);
        });

    }
};
