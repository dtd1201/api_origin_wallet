<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('auth_legacy_email_hash', 64)->nullable();
            $table->string('auth_legacy_password_fingerprint', 64)->nullable();
            $table->timestamp('auth_legacy_not_before')->nullable();
        });
        // Compatibility snapshot of the legacy LOGIN DESTINATION, not a claim
        // of historical email verification. Normal registration and profile/
        // Admin update APIs cannot set or replace these internal fields.
        // Run only during the coordinated write-paused authentication cutover.
        DB::table('users')->select(['id', 'email', 'password_hash', 'updated_at'])->orderBy('id')
            ->chunkById(250, function ($users): void {
                foreach ($users as $user) {
                    if (filter_var($user->email, FILTER_VALIDATE_EMAIL) === false
                        || ! is_string($user->password_hash) || $user->password_hash === ''
                        || $user->updated_at === null) continue;
                    DB::table('users')->where('id', $user->id)->update([
                        'auth_legacy_email_hash' => hash('sha256', $user->email),
                        'auth_legacy_password_fingerprint' => hash('sha256', $user->password_hash),
                        // Legacy changes lack a dedicated credential timestamp.
                        // Last account mutation is the conservative lower bound.
                        'auth_legacy_not_before' => CarbonImmutable::parse($user->updated_at)->addHours(72),
                    ]);
                }
            });
        Schema::table('auth_challenges', function (Blueprint $table): void {
            // Preserve the verified recipient across identity → setup, so an
            // address change cannot transfer a prior enrollment authorization.
            $table->string('identity_email_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        throw new LogicException('Email-bound enrollment requires an authentication-preserving forward repair.');
    }
};
