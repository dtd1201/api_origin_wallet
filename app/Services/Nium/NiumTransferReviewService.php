<?php

namespace App\Services\Nium;

use App\Models\Balance;
use App\Models\FxQuote;
use App\Models\Transfer;
use App\Services\Integrations\ProviderDataSyncManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Throwable;

/** Preparation and consent only. Remittance dispatch belongs to NiumTransferService. */
class NiumTransferReviewService
{
    public function __construct(
        private readonly NiumPayoutFxLockService $locks,
        private readonly NiumSgdLocalPricing $pricing,
        private readonly ProviderDataSyncManager $sync,
    ) {}

    public static function applies(Transfer $transfer): bool
    {
        return strtolower((string) $transfer->provider?->code) === 'nium'
            && $transfer->source_currency === 'USD' && $transfer->target_currency === 'SGD';
    }

    public function review(Transfer $transfer): array
    {
        if (Transfer::query()->where('user_id', $transfer->user_id)->where('provider_id', $transfer->provider_id)
            ->where('client_reference', $transfer->client_reference)->exists()) {
            throw new RuntimeException('This payment instruction already exists. Check its current status; accepted terms cannot be replaced.');
        }
        if (! (bool) config('wallet.ledger.enabled', true)) {
            throw new RuntimeException('SGD LOCAL requires ledger reservation to be enabled.');
        }
        // Price first: unavailable/unsupported charges must not acquire an FX lock.
        $terms = $this->pricing->forTransfer($transfer);
        $this->assertPricing($terms, $transfer);
        $this->ensureFreshBalance($transfer);
        $quote = $this->locks->createLock($transfer->provider, $transfer->user, 'USD', 'SGD', (string) $transfer->source_amount);
        $transfer->fx_quote_id = $quote->id;
        $transfer->fx_rate = $quote->net_rate;
        $transfer->setRelation('fxQuote', $quote);
        $review = ['instruction' => $this->instruction($transfer), 'terms' => $terms];
        $quote->update(['raw_data' => [...$quote->raw_data, 'payment_review' => $review]]);

        return [
            'provider_id' => $transfer->provider_id, 'beneficiary_id' => $transfer->beneficiary_id,
            'transfer_type' => 'payout', 'target_currency' => 'SGD',
            'purpose_code' => $transfer->purpose_code, 'reference_text' => $transfer->reference_text,
            'client_reference' => $transfer->client_reference,
            'review_token' => Crypt::encryptString((string) $quote->id),
            'source_amount' => $transfer->source_amount, 'source_currency' => 'USD', 'destination_currency' => 'SGD',
            'fx_rate' => $quote->net_rate, 'fx_expires_at' => $quote->expires_at->toISOString(),
            'terms' => $terms,
            'conversion_amount' => $quote->raw_data['payout_quote_contract']['conversion_amount'] ?? null,
            'payout_amounts' => app(NiumSgdLocalPayoutAmounts::class)->forTransfer($transfer),
        ];
    }

    /** Load only server-produced evidence; this never acquires or replaces FX. */
    public function quoteFromToken(string $token, Transfer $transfer): FxQuote
    {
        try {
            $id = Crypt::decryptString($token);
        } catch (Throwable) {
            throw new RuntimeException('Payment review is invalid. Review this payment again.');
        }
        $quote = FxQuote::query()->where('user_id', $transfer->user_id)
            ->where('provider_id', $transfer->provider_id)->find($id);
        if ($quote === null) {
            throw new RuntimeException('Payment review does not belong to this customer and provider.');
        }
        $transfer->fx_quote_id = $quote->id;
        $transfer->fx_rate = $quote->net_rate;
        $transfer->setRelation('fxQuote', $quote);

        return $quote;
    }

    public function assertReview(Transfer $transfer, bool $requireActive = true): void
    {
        $quote = $transfer->fxQuote;
        if ($quote === null) {
            throw new RuntimeException('Review and accept the current FX and financial terms before Submit.');
        }
        NiumPayoutQuoteContract::assertMatchesTransfer($quote, $transfer, $this->locks->context($transfer->provider, $transfer->user), $requireActive);
        $review = $quote->raw_data['payment_review'] ?? [];
        if (($review['instruction'] ?? null) !== $this->instruction($transfer)) {
            throw new RuntimeException('The beneficiary, receiving account or payment instruction changed. A new customer review is required.');
        }
        $this->assertPricing((array) ($review['terms'] ?? []), $transfer, $requireActive);
    }

    public function assertAccepted(Transfer $transfer): void
    {
        if (! (bool) config('wallet.ledger.enabled', true)) {
            throw new RuntimeException('SGD LOCAL requires ledger reservation to be enabled.');
        }
        $this->assertReview($transfer);
        $consent = $transfer->raw_data['payment_consent'] ?? [];
        if (($consent['user_id'] ?? null) !== $transfer->user_id
            || ($consent['fx_quote_id'] ?? null) !== $transfer->fx_quote_id
            || ($consent['purpose_confirmed'] ?? null) !== true
            || ! filled($consent['accepted_at'] ?? null)) {
            throw new RuntimeException('Authenticated customer consent is required before Admin approval.');
        }
    }

    public function assertReserved(Transfer $transfer): void
    {
        $hold = \App\Models\LedgerEntry::query()->where('reference', "transfer:{$transfer->id}:hold")->first();
        $released = \App\Models\LedgerEntry::query()->where('reference', "transfer:{$transfer->id}:release")->exists();
        $balance = $hold === null ? null : Balance::query()->whereKey($hold->balance_id)->lockForUpdate()->first();
        $context = $this->locks->context($transfer->provider, $transfer->user);
        if ($hold === null || $released || $balance === null
            || $balance->user_id !== $transfer->user_id || $balance->provider_id !== $transfer->provider_id
            || $balance->external_account_id !== $context['wallet'] || $balance->currency !== 'USD'
            || bccomp((string) $balance->reserved_balance, (string) $transfer->source_amount, 8) < 0
            || bccomp((string) ($transfer->raw_data['wallet']['held_amount'] ?? '0'), (string) $transfer->source_amount, 8) !== 0) {
            throw new RuntimeException('An unreleased ledger reservation in the bound USD source wallet is required before dispatch.');
        }
    }

    public function ensureFreshBalance(Transfer $transfer): void
    {
        $context = $this->locks->context($transfer->provider, $transfer->user);
        $query = fn () => Balance::query()->where('user_id', $transfer->user_id)
            ->where('provider_id', $transfer->provider_id)->where('currency', 'USD')
            ->where('external_account_id', $context['wallet'])->latest('as_of')->latest('id')->first();
        $balance = $query();
        if (! $this->fresh($balance)) {
            $this->sync->syncBalances($transfer->provider, $transfer->user);
            $balance = $query();
        }
        if (! $this->fresh($balance)) {
            throw new RuntimeException('A fresh balance for the bound NIUM USD source wallet is required. Automatic synchronization did not provide one.');
        }
        // Conservative coverage: provider balance snapshots can precede local holds.
        $available = (string) $balance->available_balance;
        if (isset($balance->raw_data['provider_available_balance'])) {
            $providerAvailable = bcsub((string) $balance->raw_data['provider_available_balance'], (string) $balance->reserved_balance, 8);
            if (bccomp($providerAvailable, $available, 8) < 0) {
                $available = $providerAvailable;
            }
        }
        $alreadyHeld = $transfer->raw_data['wallet']['held_amount'] ?? '0';
        if (bccomp(bcadd($available, (string) $alreadyHeld, 8), (string) $transfer->source_amount, 8) < 0) {
            throw new RuntimeException('Insufficient available USD source-wallet balance for this payment.');
        }
    }

    private function fresh(?Balance $balance): bool
    {
        $asOf = $balance?->raw_data['provider_balance_as_of'] ?? $balance?->as_of;
        if ($asOf === null) {
            return false;
        }
        $time = Carbon::parse($asOf);

        return $time->greaterThan(now()->subMinutes(5)) && $time->lessThanOrEqualTo(now());
    }

    private function assertPricing(array $terms, Transfer $transfer, bool $requireActive = true): void
    {
        // The existing ledger reserves principal. Additional debits require a
        // reviewed fee-aware ledger contract; do not silently under-reserve them.
        if (($terms['provider_verified'] ?? null) !== true || ! filled($terms['reference'] ?? null)
            || ($terms['currency'] ?? null) !== 'USD'
            || NiumPayoutFxLockContract::decimal($terms['fee_amount'] ?? '') !== '0'
            || NiumPayoutFxLockContract::decimal($terms['total_wallet_debit'] ?? '') !== NiumPayoutFxLockContract::decimal($transfer->source_amount)
            || ! filled($terms['expires_at'] ?? null)
            || ($requireActive && Carbon::parse($terms['expires_at'])->lessThanOrEqualTo(now()))) {
            throw new RuntimeException('Verified pricing must cover the full USD debit within the principal reservation. Unknown, additional or expired charges block this payment.');
        }
    }

    private function instruction(Transfer $transfer): array
    {
        $beneficiary = $transfer->beneficiary;

        return [
            'user_id' => $transfer->user_id, 'provider_id' => $transfer->provider_id,
            'beneficiary_id' => $transfer->beneficiary_id,
            'beneficiary_fingerprint' => hash('sha256', json_encode($beneficiary?->only([
                'external_beneficiary_id', 'beneficiary_type', 'country_code', 'currency', 'payout_method',
                'full_name', 'account_number', 'iban', 'bank_name', 'bank_code', 'branch_code', 'swift_bic', 'raw_data',
            ]), JSON_THROW_ON_ERROR)),
            'source_amount' => NiumPayoutFxLockContract::decimal($transfer->source_amount),
            'source_currency' => $transfer->source_currency, 'target_currency' => $transfer->target_currency,
            'purpose_code' => $transfer->purpose_code, 'reference_text' => $transfer->reference_text,
            'client_reference' => $transfer->client_reference,
            'fx_quote_id' => $transfer->fx_quote_id,
        ];
    }
}
