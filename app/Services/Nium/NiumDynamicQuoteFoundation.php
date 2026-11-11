<?php

declare(strict_types=1);

namespace App\Services\Nium;

use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutConfirmation;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;

/**
 * Internal-only admission boundary. No HTTP transport, route, persistence, funds or
 * dispatch integration. Factory must load authenticated SERVER evidence, never
 * deserialize client financial terms. No factory is registered in AUTO-02A.
 */
final class NiumDynamicQuoteFoundation
{
    public function review(?Closure $serverEvidenceReview = null): array
    {
        if (config('nium_dynamic_quotes.quote_contract_enabled', false) !== true) {
            return $this->unavailable('dynamic_quote_contract_disabled');
        }
        if ($serverEvidenceReview === null) {
            return $this->unavailable('trusted_payout_evidence_unavailable');
        }
        try {
            $review = $serverEvidenceReview();
            if (! $review instanceof PayoutConfirmation || $review->synthetic || $review->confirmedAt !== null) {
                return $this->unavailable('trusted_payout_evidence_unavailable');
            }

            return ['status' => 'REVIEWABLE', 'contract' => $review, 'dispatch' => 'DENIED'];
        } catch (InvalidArgumentException|JsonException) {
            // No provider response, identifiers or monetary diagnostics are exposed.
            return $this->unavailable('payout_quote_or_terms_unavailable');
        }
    }

    public function confirm(string $actor, string $fingerprint, DateTimeImmutable $asOf, ?Closure $freshServerReview = null): array
    {
        if (config('nium_dynamic_quotes.confirmation_contract_enabled', false) !== true) {
            return $this->unavailable('dynamic_confirmation_contract_disabled');
        }
        $result = $this->review($freshServerReview);
        if ($result['status'] !== 'REVIEWABLE') {
            return $result;
        }
        try {
            return ['status' => 'CONFIRMED_CONTRACT',
                'contract' => $result['contract']->confirm($actor, $fingerprint, $asOf), 'dispatch' => 'DENIED'];
        } catch (InvalidArgumentException) {
            return $this->unavailable('payout_confirmation_unavailable');
        }
    }

    private function unavailable(string $reason): array
    {
        return ['status' => 'UNAVAILABLE', 'reason_codes' => [$reason], 'dispatch' => 'DENIED'];
    }
}
