<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('auth_epoch')->default(0);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('credentials_changed_at')->nullable();
        });
        Schema::create('user_auth_factors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('totp');
            $table->text('secret_ciphertext');
            $table->string('encryption_key_version', 40)->default('app-v1');
            $table->timestamp('confirmed_at');
            $table->bigInteger('last_accepted_time_step');
            $table->unsignedBigInteger('version')->default(1);
            $table->unsignedInteger('recovery_set_version')->default(1);
            $table->timestamps();
            $table->unique(['user_id', 'type']);
        });
        Schema::create('auth_registration_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->uuid('registration_version')->unique();
            $table->string('email_hash', 64);
            $table->string('password_fingerprint', 64);
            $table->string('client_binding_hash', 64)->nullable();
            $table->string('registration_proof_hash');
            $table->unsignedSmallInteger('verification_attempts')->default(0);
            $table->unsignedBigInteger('auth_epoch');
            $table->timestamp('verified_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
        });
        Schema::create('auth_enrollment_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('audience', 10);
            $table->string('purpose', 30);
            $table->string('approval_digest', 64)->nullable()->unique();
            $table->unsignedBigInteger('auth_epoch');
            $table->string('email_hash', 64);
            $table->string('policy_reference', 255);
            $table->string('evidence_reference', 255);
            $table->json('approver_ids');
            $table->timestamp('approved_at');
            $table->timestamp('proof_sent_at')->nullable();
            $table->unsignedSmallInteger('proof_send_count')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'audience', 'purpose']);
        });
        Schema::create('auth_challenges', function (Blueprint $table): void {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('audience', 10);
            $table->string('purpose', 40);
            $table->string('client_binding_hash', 64);
            $table->unsignedBigInteger('auth_epoch');
            $table->foreignId('factor_id')->nullable()->constrained('user_auth_factors')->cascadeOnDelete();
            $table->unsignedBigInteger('factor_version')->nullable();
            $table->foreignId('grant_id')->nullable()->constrained('auth_enrollment_grants')->cascadeOnDelete();
            $table->foreignId('registration_evidence_id')->nullable()->constrained('auth_registration_evidence')->cascadeOnDelete();
            $table->foreignId('initiating_token_id')->nullable()->constrained('api_tokens')->nullOnDelete();
            $table->string('intent', 40)->nullable();
            $table->string('trusted_identity_method', 40)->nullable();
            $table->timestamp('trusted_identity_verified_at')->nullable();
            $table->text('pending_secret_ciphertext')->nullable();
            $table->string('encryption_key_version', 40)->nullable();
            $table->string('identity_proof_hash')->nullable();
            $table->timestamp('proof_sent_at')->nullable();
            $table->unsignedSmallInteger('proof_send_count')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->timestamp('credential_verified_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('ceremony_deadline');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('mfa_verified_at')->nullable();
            $table->bigInteger('verified_counter')->nullable();
            $table->timestamp('token_issued_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'audience', 'purpose']);
        });
        Schema::create('auth_recovery_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('factor_id')->constrained('user_auth_factors')->cascadeOnDelete();
            $table->unsignedInteger('set_version');
            $table->string('selector', 16)->unique();
            $table->string('code_hash');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['factor_id', 'set_version', 'consumed_at']);
        });
        Schema::table('api_tokens', function (Blueprint $table): void {
            $table->string('audience', 10)->nullable();
            $table->timestamp('mfa_verified_at')->nullable();
            $table->unsignedBigInteger('auth_epoch')->nullable();
            $table->foreignId('factor_id')->nullable()->constrained('user_auth_factors')->cascadeOnDelete();
            $table->unsignedBigInteger('factor_version')->nullable();
            $table->index(['user_id', 'auth_epoch']);
        });
        Schema::table('pending_registrations', function (Blueprint $table): void {
            // New rows use only the hash. Legacy rows are rejected, never used
            // as trusted enrollment evidence. No data backfill is performed.
            $table->string('verification_code', 6)->nullable()->change();
            $table->string('verification_code_hash')->nullable();
            $table->uuid('registration_version')->nullable()->unique();
            $table->string('client_binding_hash', 64)->nullable();
            $table->unsignedSmallInteger('verification_attempts')->default(0);
        });
    }

    public function down(): void
    {
        // Intentionally forward-only. Rolling back schema would erase replay
        // and recovery-consumption history and can reopen authentication.
        throw new LogicException('Mandatory TOTP schema requires an MFA-preserving forward repair.');
    }
};
