<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_notification_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_provider_account_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 40);
            $table->string('idempotency_key', 64)->unique();
            $table->json('payload');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('kyc_notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kyc_notification_event_id')->constrained()->cascadeOnDelete();
            $table->string('recipient_type', 20);
            $table->string('email');
            $table->string('normalized_email');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['kyc_notification_event_id', 'normalized_email'], 'kyc_notification_delivery_recipient_unique');
            $table->index(['status', 'attempts']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_notification_deliveries');
        Schema::dropIfExists('kyc_notification_events');
    }
};
