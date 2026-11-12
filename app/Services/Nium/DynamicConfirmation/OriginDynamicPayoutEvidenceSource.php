<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use App\Services\Wallet\Concurrency\OwnerFirst;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use App\Services\Nium\NiumDynamicReviewService;
use App\Services\Nium\NiumDynamicPayoutQuoteService;
use App\Models\NiumDynamicPayoutQuote;
use App\Models\NiumDynamicIntent;
use App\Services\Nium\NiumService;
use App\Services\Nium\NiumDynamicReviewService as Reviews;

/** Locked admission from frozen customer terms and fresh authenticated provider observations. */
final class OriginDynamicPayoutEvidenceSource implements DynamicPayoutEvidenceSource
{
    public function __construct(private readonly NiumDynamicReviewService $reviews,
        private readonly NiumDynamicPayoutQuoteService $quotes,
        private readonly DynamicRegisteredAccountEvidence $registration,
        private readonly NiumService $nium) {}

    public function refreshForExecution(NiumDynamicIntent $intent): void
    {
        OwnerFirst::outside();
        if (DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
            throw new RuntimeException('dynamic_execution_refresh_requires_no_transaction');
        }
        // Reuse a still-fresh observation through authentication preparation. All
        // local identity/compliance and snapshot checks are repeated under locks.
        $refresh = false;
        try {
            $observations = $this->reviews->observations($intent->terms_fingerprint);
            foreach (['execution_eligibility', 'instruction_validation', 'wallet_availability'] as $name) {
                if (new DateTimeImmutable($observations[$name]['expires_at']) <= now()->addSeconds(5)->toDateTimeImmutable()) {
                    $refresh = true;
                }
            }
        } catch (\Throwable) {
            $refresh = true;
        }
        if ($refresh) $this->reviews->refreshExecution($intent);
    }

    public const MISSING_DEPENDENCIES = [
        'dynamic_authoritative_financial_contract_unavailable',
        'dynamic_registered_receiving_account_evidence_unavailable',
        'dynamic_versioned_corridor_evidence_unavailable',
        'dynamic_persisted_quote_review_evidence_unavailable',
        'dynamic_remittance_instruction_evidence_unavailable',
        'dynamic_wallet_balance_freshness_evidence_unavailable',
    ];

    public function revalidate(LockedPayoutRecords $records, DateTimeImmutable $at): DynamicPayoutEvidence
    {
        OwnerFirst::current([$records->user->id]);
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('dynamic_evidence_requires_transaction');
        }
        foreach ([$records->user, $records->provider, $records->account, $records->beneficiary,
            $records->wallet, $records->kyc] as $record) {
            if (! $record->exists) {
                throw new RuntimeException('dynamic_local_identity_unverified');
            }
        }
        foreach ([$records->account, $records->beneficiary, $records->wallet, $records->kyc] as $owned) {
            if ((string) $owned->user_id !== (string) $records->user->id) {
                throw new RuntimeException('dynamic_local_identity_unverified');
            }
        }
        foreach ([$records->account, $records->beneficiary, $records->wallet] as $scoped) {
            if ((string) $scoped->provider_id !== (string) $records->provider->id) {
                throw new RuntimeException('dynamic_local_identity_unverified');
            }
        }
        if ($records->provider->code !== 'nium' || $records->provider->status !== 'active'
            || $records->account->external_account_id !== $records->wallet->external_account_id
            || ! is_string($records->account->external_customer_id) || $records->account->external_customer_id === ''
            || ! is_string($records->wallet->external_account_id) || $records->wallet->external_account_id === ''
            || $records->wallet->currency !== 'USD'
            || $records->account->customer_id_verified_at === null || $records->account->wallet_id_verified_at === null
            || $records->account->customer_id_verified_at->toDateTimeImmutable() > $at
            || $records->account->wallet_id_verified_at->toDateTimeImmutable() > $at) {
            throw new RuntimeException('dynamic_local_identity_unverified');
        }

        $review = $records->acceptedFingerprint === null ? null : $this->reviews->load($records->acceptedFingerprint);
        if ($review !== null) {
            if ($review['owner'] !== (string) $records->user->id
                || $review['beneficiary_id'] !== $records->beneficiary->id
                || $review['provider_account_id'] !== $records->account->id
                || $review['balance_id'] !== $records->wallet->id) {
                throw new RuntimeException('dynamic_review_binding_changed');
            }
            $quote = NiumDynamicPayoutQuote::query()->lockForUpdate()->findOrFail($review['dynamic_quote_id']);
            $this->quotes->assertCurrent($quote, $records->user, $records->beneficiary, $records->wallet, $records->consumingIntent);
            if (! hash_equals($review['response_sha256'], hash('sha256', $quote->response_json))
                || ! hash_equals($review['binding']['current_receiving_record_sha256'],
                    DynamicReceivingAccountBinding::fingerprint($records->beneficiary))) {
                throw new RuntimeException('dynamic_review_binding_changed');
            }
            if ($review['reason_codes'] !== []) throw new RuntimeException($review['reason_codes'][0]);
            $registered = $this->registration->inspect($records->beneficiary, $records->account, $quote->binding);
            if ($registered !== $review['evidence']['registered_receiving_account']
                || Reviews::profileVersion($records->user->load('kycProfile')) !== $review['profile_version']
                || rtrim((string) config('services.nium.base_url'), '/') !== $review['binding']['base_url']
                || $this->nium->clientId() !== $review['binding']['client']) {
                throw new RuntimeException('dynamic_review_binding_changed');
            }
            $live = $this->reviews->observations($records->acceptedFingerprint);
            $until = $quote->expires_at->toDateTimeImmutable();
            foreach (['execution_eligibility', 'instruction_validation', 'wallet_availability'] as $name) {
                $observation = $live[$name];
                $expiry = new DateTimeImmutable($observation['expires_at']);
                if (new DateTimeImmutable($observation['observed_at']) > $at || $expiry <= $at) {
                    throw new RuntimeException('dynamic_confirmation_evidence_expired');
                }
                if ($expiry < $until) $until = $expiry;
            }
            $old = $review['evidence'];
            $observedWallet = $live['wallet_availability'];
            $storedWallet = $records->wallet->raw_data['dynamic_authenticated_availability'] ?? null;
            if (! is_array($observedWallet) || ! is_array($storedWallet)) {
                throw new RuntimeException('dynamic_review_binding_changed');
            }
            // This flat snapshot contains scalar values. PostgreSQL JSONB does
            // not preserve object key order; retain strict key/value/type checks.
            ksort($observedWallet, SORT_STRING);
            ksort($storedWallet, SORT_STRING);
            if ($live['registered_receiving_account'] !== $registered
                || $live['execution_eligibility']['selection'] !== $old['execution_eligibility']['selection']
                || $live['execution_eligibility']['scope'] !== $old['execution_eligibility']['scope']
                || $live['execution_eligibility']['payout_schema_sha256'] !== $old['execution_eligibility']['payout_schema_sha256']
                || $live['instruction_validation']['request_sha256'] !== $old['instruction_validation']['request_sha256']
                || $observedWallet !== $storedWallet
                || $live['wallet_availability']['available'] !== ($records->wallet->raw_data['provider_available_balance'] ?? null)) {
                throw new RuntimeException('dynamic_review_binding_changed');
            }
            $assembled = $this->reviews->assemble($review, $quote);
            if (! hash_equals($records->acceptedFingerprint, $assembled->fingerprint())) {
                throw new RuntimeException('dynamic_review_binding_changed');
            }
            return new DynamicPayoutEvidence($assembled, $registered['current_record_sha256'], 'LOCAL_SINGLE_INTENT_PER_QUOTE',
                ['fees' => Reviews::CUSTOMER_CONTRACT, 'wallet_debit' => Reviews::CUSTOMER_CONTRACT,
                    'quote_consumption' => 'origin-permanent-single-intent-v1',
                    'corridor' => $live['execution_eligibility']['customer_corridors_sha256'],
                    'account_eligibility' => $review['profile_version'], 'compliance' => 'origin-locked-aml-rfi-controls-v1',
                    'registered_receiving_account' => $registered['registered_request_sha256'],
                    'wallet_availability' => $live['wallet_availability']['response_sha256'],
                    'remittance_instruction' => $live['instruction_validation']['request_sha256']], $at, $until);
        }

        // Unknown fingerprints never become authority, even if a client hashes a quote.
        throw new RuntimeException(self::MISSING_DEPENDENCIES[0]);
    }
}
