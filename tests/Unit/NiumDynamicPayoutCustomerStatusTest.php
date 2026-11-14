<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Nium\DynamicMultiCurrency\Financial\DocumentaryFeeSchedule;
use App\Models\NiumDynamicIntent;
use App\Models\NiumDynamicPayoutOutbox;
use App\Models\NiumDynamicReservation;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutCustomerStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class NiumDynamicPayoutCustomerStatusTest extends TestCase
{
    public static function states(): array
    {
        return [
            ['held', null, 'held', 'queued', false],
            ['claimed', null, 'held', 'processing', false],
            ['submitted', null, 'held', 'processing', false],
            ['ambiguous', null, 'held', 'pending_provider_confirmation', false],
            ['exception', null, 'held', 'requires_attention', false],
            ['cancelled', null, 'released', 'cancelled', false],
            ['matched', null, 'consumed', 'reconciled', true],
            ['matched', 'PAID', 'consumed', 'paid', true],
            ['submitted', 'PAID', 'held', 'processing', false],
            ['matched', null, 'held', 'processing', false],
            ['matched', 'RETURNED', 'consumed', 'returned', false],
            ['exception', 'REJECTED', 'held', 'failed', false],
            ['exception', 'FAILED', 'held', 'failed', false],
        ];
    }

    #[DataProvider('states')]
    public function test_safe_status_never_treats_provider_acceptance_or_a_return_as_completion(
        string $state, ?string $providerStatus, string $reservationState, string $expected, bool $reconciled): void
    {
        $intent = (new NiumDynamicIntent)->forceFill(['id' => 'intent', 'beneficiary_id' => 7,
            'source_currency' => 'USD', 'destination_currency' => 'GBP', 'amount_mode' => 'SOURCE_AMOUNT',
            'total_debit' => '1000', 'terms_fingerprint' => 'private-fingerprint',
            'admission_evidence' => ['secret' => 'private-evidence'],
            'confirmed_terms' => ['context' => ['corridor' => ['destinationCountry' => 'GB']],
                'financial_terms' => ['principal' => ['currency' => 'USD', 'amount' => '980'],
                    'transaction_fee' => ['currency' => 'USD', 'amount' => '20'],
                    'estimated_recipient' => ['currency' => 'GBP', 'amount' => '784']]],
            'created_at' => '2030-01-01T07:10:00+07:00', 'consented_at' => '2030-01-01T00:10:00Z',
            'quote_expires_at' => '2030-01-01T08:00:00+07:00']);
        $outbox = (new NiumDynamicPayoutOutbox)->forceFill(['intent_id' => 'intent', 'reservation_id' => 'reservation',
            'state' => $state, 'provider_status' => $providerStatus, 'provider_reference' => 'private-provider-reference',
            'financial_state' => $state === 'matched' ? 'matched' : 'pending',
            'financial_risk_state' => 'OBSERVED_PROVIDER_ACCOUNTING_MATCHED',
            'exception_code' => 'private-admin-code', 'claim_token' => 'private-claim']);
        $reservation = (new NiumDynamicReservation)->forceFill(['id' => 'reservation', 'intent_id' => 'intent',
            'state' => $reservationState, 'debit_entry_id' => $reservationState === 'consumed' ? 9 : null]);
        $result = (new DynamicPayoutCustomerStatus)->project($intent, $outbox, $reservation);
        $this->assertSame($expected, $result['status']);
        $this->assertSame($reconciled, $result['financial_reconciled']);
        $this->assertSame('1000.00000000', $result['total_usd_debit']);
        $this->assertSame('980', $result['principal']['amount']);
        $this->assertSame('20', $result['included_fee']['amount']);
        $this->assertNull($result['fee_classification']); // Never relabel historical consent.
        $this->assertSame(['status' => 'UNVERIFIED', 'fees' => null], $result['provider_fee_observation']);
        $this->assertSame('2030-01-01T00:10:00.000000Z', $result['created_at']);
        $this->assertSame($result['created_at'], $result['consented_at']);
        $this->assertSame('2030-01-01T01:00:00.000000Z', $result['quote_expires_at']);
        $this->assertStringNotContainsString('private-', json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertNotSame('completed', $result['status']);
    }

    public function test_contract_fee_is_separate_from_missing_zero_or_different_settlement_fees(): void
    {
        foreach ([null, '0', '20', '7.25'] as $observed) {
            $intent = (new NiumDynamicIntent)->forceFill(['id' => 'intent', 'beneficiary_id' => 7,
                'source_currency' => 'USD', 'destination_currency' => 'GBP', 'amount_mode' => 'SOURCE_AMOUNT', 'total_debit' => '1000',
                'confirmed_terms' => ['context' => ['corridor' => ['destinationCountry' => 'GB']],
                    'finalized_usd_preparation' => ['fee_classification' => DocumentaryFeeSchedule::LOCAL_FEE_CLASSIFICATION],
                    'financial_terms' => ['principal' => ['currency' => 'USD', 'amount' => '980'],
                        'transaction_fee' => ['currency' => 'USD', 'amount' => '20'],
                        'estimated_recipient' => ['currency' => 'GBP', 'amount' => '784']]]]);
            $outbox = (new NiumDynamicPayoutOutbox)->forceFill(['intent_id' => 'intent', 'reservation_id' => 'reservation',
                'state' => 'submitted', 'financial_evidence' => $observed === null ? null : [
                    'fee_evidence' => 'SETTLED_FEE_RECORDS',
                    'provider_fee_records' => [['amount' => $observed, 'currency' => $observed === '7.25' ? 'EUR' : 'USD']]]]);
            $reservation = (new NiumDynamicReservation)->forceFill(['id' => 'reservation', 'intent_id' => 'intent', 'state' => 'held']);
            $result = (new DynamicPayoutCustomerStatus)->project($intent, $outbox, $reservation);
            $this->assertSame('NIUM', $result['fee_classification']['fee_owner']);
            $this->assertSame('20', $result['included_fee']['amount']);
            $this->assertSame('1000.00000000', $result['total_usd_debit']);
            $this->assertFalse($result['financial_reconciled']);
            $this->assertSame($observed === null ? ['status' => 'UNVERIFIED', 'fees' => null] : [
                'status' => 'SETTLED_FEE_RECORDS', 'fees' => [['currency' => $observed === '7.25' ? 'EUR' : 'USD', 'amount' => $observed]],
            ], $result['provider_fee_observation']);
        }
    }

    public function test_mixed_intent_records_cannot_be_projected(): void
    {
        $this->expectExceptionMessage('dynamic_status_binding_unverified');
        (new DynamicPayoutCustomerStatus)->project(
            (new NiumDynamicIntent)->forceFill(['id' => 'owner-intent']),
            (new NiumDynamicPayoutOutbox)->forceFill(['intent_id' => 'other-intent']),
            new NiumDynamicReservation);
    }
}
