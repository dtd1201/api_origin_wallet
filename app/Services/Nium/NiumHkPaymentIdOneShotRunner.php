<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\NiumRfiCase;
use App\Models\UserProviderAccount;
use RuntimeException;

final class NiumHkPaymentIdOneShotRunner
{
    public function preflight(UserProviderAccount $account, bool $humanApproved = false): array
    {
        if ((int) $account->id === 4) {
            throw new RuntimeException('Account 4 is protected and must remain byte-identical.');
        }
        if ((int) $account->id !== 7) {
            throw new RuntimeException('HK Assign Payment ID scaffold is restricted to Account 7.');
        }
        $account->loadMissing(['user', 'niumVirtualAccounts']);
        if (! filled($account->external_customer_id) || $account->customer_id_verified_at === null
            || ! filled($account->external_account_id) || $account->wallet_id_verified_at === null
            || $account->provider_ids_verified_at === null) {
            throw new RuntimeException('VAN HOLD: verified customer and wallet identifiers are required.');
        }
        if ($account->user?->kyc_status !== 'verified' || $account->status !== 'active'
            || $account->provider_status !== 'clear' || $account->provider_sub_status !== null) {
            throw new RuntimeException('VAN HOLD: customer onboarding is not conclusively complete or is awaiting KYC/RFI.');
        }
        if (strtolower((string) $account->compliance_status) !== 'completed') {
            throw new RuntimeException('VAN HOLD: customer compliance is not explicitly clear.');
        }
        if (NiumRfiCase::query()->where('user_provider_account_id', $account->id)->whereNotIn('status', ['resolved', 'closed'])->exists()) {
            throw new RuntimeException('VAN HOLD: an onboarding RFI remains outstanding.');
        }
        $mode = strtolower(trim((string) config('services.nium.hk_van_allocation_mode')));
        if ($mode === '') {
            throw new RuntimeException('VAN HOLD: provider-confirmed allocation mode is not configured.');
        }
        if ($mode === 'automatic') {
            throw new RuntimeException('VAN HOLD: automatic allocation mode never permits an Assign Payment ID POST.');
        }
        if ($mode !== 'request_based') {
            throw new RuntimeException('VAN HOLD: unknown allocation mode.');
        }
        foreach (['hk_payment_id_currency', 'hk_payment_id_bank_name', 'hk_payment_id_account_category'] as $key) {
            if (blank(config("services.nium.{$key}"))) {
                throw new RuntimeException("VAN HOLD: provider-confirmed {$key} is not configured.");
            }
        }
        if ($account->niumVirtualAccounts->isNotEmpty()) {
            throw new RuntimeException('VAN HOLD: payment IDs are already present.');
        }
        if (ApiRequestLog::query()->where('user_id', $account->user_id)->where('operation', 'assign_payment_id')->exists()) {
            throw new RuntimeException('VAN HOLD: a prior one-shot Assign Payment ID attempt exists.');
        }
        if (ApiRequestLog::query()->where('user_id', $account->user_id)->whereNull('operation')->where('endpoint_path', 'like', '%/paymentId')->exists()) {
            throw new RuntimeException('VAN HOLD: historical Assign Payment ID evidence is ambiguous.');
        }
        if (! $humanApproved) {
            throw new RuntimeException('VAN HOLD: request-based allocation requires separate human approval.');
        }

        return ['status' => 'PASS', 'post_permitted' => false, 'account_id' => 7, 'postcondition' => 'provider_response_plus_va_assigned_webhook'];
    }

    public function run(): never
    {
        throw new RuntimeException('VAN_RUNNER_DISABLED_OFFLINE: this scaffold cannot execute Assign Payment ID.');
    }
}
