<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->index(['status', 'kyc_status'], 'ow_users_status_kyc_idx');
        });

        Schema::table('integration_providers', function (Blueprint $table): void {
            $table->index(['status', 'name'], 'ow_providers_status_name_idx');
        });

        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->index(['user_id', 'id'], 'ow_bank_accounts_user_id_id_idx');
        });

        Schema::table('balances', function (Blueprint $table): void {
            $table->index(['user_id', 'id'], 'ow_balances_user_id_id_idx');
        });

        Schema::table('beneficiaries', function (Blueprint $table): void {
            $table->index(['user_id', 'id'], 'ow_beneficiaries_user_id_id_idx');
            $table->index(['user_id', 'status'], 'ow_beneficiaries_user_status_idx');
        });

        Schema::table('fx_quotes', function (Blueprint $table): void {
            $table->index(['user_id', 'id'], 'ow_fx_quotes_user_id_id_idx');
            $table->index(['user_id', 'provider_id', 'id'], 'ow_fx_quotes_user_provider_id_idx');
        });

        Schema::table('transfers', function (Blueprint $table): void {
            $table->index(['user_id', 'id'], 'ow_transfers_user_id_id_idx');
            $table->index(['user_id', 'status', 'id'], 'ow_transfers_user_status_id_idx');
            $table->index(['provider_id', 'status', 'id'], 'ow_transfers_provider_status_id_idx');
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->index(['user_id', 'id'], 'ow_transactions_user_id_id_idx');
            $table->index(['user_id', 'booked_at'], 'ow_transactions_user_booked_at_idx');
        });

        Schema::table('user_provider_accounts', function (Blueprint $table): void {
            $table->index(['user_id', 'provider_id', 'id'], 'ow_provider_accounts_user_provider_id_idx');
        });

        Schema::table('user_integration_requests', function (Blueprint $table): void {
            $table->index(['status', 'requested_at'], 'ow_integration_requests_status_requested_idx');
        });

        Schema::table('kyc_provider_submissions', function (Blueprint $table): void {
            $table->index(['user_id', 'status'], 'ow_kyc_provider_user_status_idx');
        });

        Schema::table('api_request_logs', function (Blueprint $table): void {
            $table->index(['user_id', 'created_at'], 'ow_api_logs_user_created_at_idx');
            $table->index(['provider_id', 'created_at'], 'ow_api_logs_provider_created_at_idx');
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index(['entity_type', 'entity_id'], 'ow_audit_logs_entity_idx');
            $table->index(['created_at'], 'ow_audit_logs_created_at_idx');
        });

        Schema::table('webhook_events', function (Blueprint $table): void {
            $table->index(['processing_status', 'id'], 'ow_webhook_events_status_id_idx');
            $table->index(['provider_id', 'processing_status'], 'ow_webhook_events_provider_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table): void {
            $table->dropIndex('ow_webhook_events_provider_status_idx');
            $table->dropIndex('ow_webhook_events_status_id_idx');
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('ow_audit_logs_created_at_idx');
            $table->dropIndex('ow_audit_logs_entity_idx');
        });

        Schema::table('api_request_logs', function (Blueprint $table): void {
            $table->dropIndex('ow_api_logs_provider_created_at_idx');
            $table->dropIndex('ow_api_logs_user_created_at_idx');
        });

        Schema::table('kyc_provider_submissions', function (Blueprint $table): void {
            $table->dropIndex('ow_kyc_provider_user_status_idx');
        });

        Schema::table('user_integration_requests', function (Blueprint $table): void {
            $table->dropIndex('ow_integration_requests_status_requested_idx');
        });

        Schema::table('user_provider_accounts', function (Blueprint $table): void {
            $table->dropIndex('ow_provider_accounts_user_provider_id_idx');
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex('ow_transactions_user_booked_at_idx');
            $table->dropIndex('ow_transactions_user_id_id_idx');
        });

        Schema::table('transfers', function (Blueprint $table): void {
            $table->dropIndex('ow_transfers_provider_status_id_idx');
            $table->dropIndex('ow_transfers_user_status_id_idx');
            $table->dropIndex('ow_transfers_user_id_id_idx');
        });

        Schema::table('fx_quotes', function (Blueprint $table): void {
            $table->dropIndex('ow_fx_quotes_user_provider_id_idx');
            $table->dropIndex('ow_fx_quotes_user_id_id_idx');
        });

        Schema::table('beneficiaries', function (Blueprint $table): void {
            $table->dropIndex('ow_beneficiaries_user_status_idx');
            $table->dropIndex('ow_beneficiaries_user_id_id_idx');
        });

        Schema::table('balances', function (Blueprint $table): void {
            $table->dropIndex('ow_balances_user_id_id_idx');
        });

        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->dropIndex('ow_bank_accounts_user_id_id_idx');
        });

        Schema::table('integration_providers', function (Blueprint $table): void {
            $table->dropIndex('ow_providers_status_name_idx');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('ow_users_status_kyc_idx');
        });
    }
};
