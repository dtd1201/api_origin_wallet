<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nium_compliance_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained('integration_providers')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('transfer_id')->nullable()->constrained('transfers')->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->string('event_id')->unique();
            $table->string('request_id')->nullable()->index();
            $table->string('reference')->nullable()->index();
            $table->string('customer_reference')->nullable()->index();
            $table->string('event_type', 100)->nullable();
            $table->string('compliance_status', 100)->nullable();
            $table->string('match_status', 30)->default('unmatched')->index();
            $table->string('review_status', 30)->default('pending')->index();
            $table->boolean('requires_action')->default(false)->index();
            $table->string('processing_status', 30)->default('received')->index();
            $table->jsonb('payload');
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('last_received_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->text('error_message')->nullable();
        });

        Schema::table('transfers', function (Blueprint $table): void {
            $table->boolean('compliance_review_required')->default(false)->index();
            $table->string('compliance_status', 100)->nullable();
            $table->timestamp('compliance_reviewed_at')->nullable();
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->boolean('compliance_review_required')->default(false)->index();
            $table->string('compliance_status', 100)->nullable();
            $table->timestamp('compliance_reviewed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn([
                'compliance_review_required',
                'compliance_status',
                'compliance_reviewed_at',
            ]);
        });

        Schema::table('transfers', function (Blueprint $table): void {
            $table->dropColumn([
                'compliance_review_required',
                'compliance_status',
                'compliance_reviewed_at',
            ]);
        });

        Schema::dropIfExists('nium_compliance_events');
    }
};
