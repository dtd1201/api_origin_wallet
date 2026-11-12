<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beneficiary_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('provider_id')->constrained('integration_providers')->restrictOnDelete();
            // Intentionally retained after the existing hard-delete beneficiary workflow.
            $table->unsignedBigInteger('beneficiary_id')->index();
            $table->unsignedBigInteger('generation');
            $table->unique(['beneficiary_id', 'generation']);
            $table->unsignedBigInteger('active_beneficiary_id')->nullable()->unique();
            $table->string('create_key', 64)->nullable()->unique();
            $table->string('action', 10);
            $table->string('state', 24);
            $table->uuid('request_id')->unique();
            $table->uuid('verification_request_id')->unique();
            $table->string('baseline_hash', 64);
            $table->string('request_hash', 64);
            $table->string('applied_hash', 64)->nullable();
            $table->string('original_status', 50)->nullable();
            $table->boolean('new_beneficiary')->default(false);
            $table->json('dispatch_steps')->nullable();
            $table->text('receipt')->nullable(); // encrypted:array; never serialized to API/logs
            $table->string('failure_class')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Match the project's forward-only financial/operation evidence migrations.
        // An existence/row-count check would race with a newly committed claim.
        throw new RuntimeException('Beneficiary operation rollback is forbidden; preserve identities, receipts and reconciliation evidence and use forward-only recovery.');
    }
};
