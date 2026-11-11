<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Nium\DynamicMultiCurrency\CorridorEvidence;
use App\Domain\Nium\DynamicMultiCurrency\CorridorIdentity;
use App\Domain\Nium\DynamicMultiCurrency\EvidenceKind;
use App\Domain\Nium\DynamicMultiCurrency\EvidenceState;
use App\Domain\Nium\DynamicMultiCurrency\Financial\Decimal;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FeeAllocation;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FeePayer;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FinancialTerms;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FxRequirement;
use App\Domain\Nium\DynamicMultiCurrency\Financial\Money;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutAmountMode;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutConfirmation;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutContract;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutQuoteContext;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutQuoteRequest;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutRateQuote;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PricingEvidence;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PricingStage;
use App\Domain\Nium\DynamicMultiCurrency\Options\BeneficiarySnapshot;
use App\Domain\Nium\DynamicMultiCurrency\Options\CustomerAccountScope;
use App\Domain\Nium\DynamicMultiCurrency\Registry\EvidenceSource;
use App\Domain\Nium\DynamicMultiCurrency\Registry\RevocationStatus;
use App\Models\AmlScreening;
use App\Models\Balance;
use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\DynamicConfirmation\DynamicReceivingAccountBinding;
use App\Services\Nium\DynamicConfirmation\DynamicReservationLedger;
use App\Services\Nium\DynamicConfirmation\DynamicTransferConfirmationService;
use DateTimeImmutable;
use Tests\Support\DynamicConfirmationEvidenceStub;

trait CreatesDynamicConfirmationFixtures
{
    protected User $owner;
    protected UserProviderAccount $account;
    protected Beneficiary $beneficiary;
    protected Balance $wallet;
    protected DynamicConfirmationEvidenceStub $source;
    protected DynamicTransferConfirmationService $confirmation;

    protected function createDynamicFixtures(string $available = '250.00'): void
    {
        $this->travelTo(new DateTimeImmutable('2030-01-01T00:10:00Z'));
        config(['nium_dynamic_confirmation.enabled' => true,
            'nium_dynamic_confirmation.automatic_dispatch_enabled' => false,
            'nium_dynamic_quotes.quote_contract_enabled' => true,
            'nium_dynamic_quotes.confirmation_contract_enabled' => true]);
        $provider = IntegrationProvider::query()->create(['code' => 'nium', 'name' => 'Fixture Nium', 'status' => 'active']);
        $this->owner = User::factory()->create(['kyc_status' => 'verified']);
        $kyc = $this->owner->kycProfile()->create([
            'status' => 'approved', 'applicant_type' => 'individual', 'legal_name' => 'Fixture Customer',
            'address_line1' => '1 Fixture Road', 'city' => 'London',
            'postal_code' => 'SW1A 1AA', 'country_code' => 'GB',
        ]);
        AmlScreening::query()->create(['user_id' => $this->owner->id, 'kyc_profile_id' => $kyc->id,
            'subject_type' => 'individual', 'subject_name' => 'Fixture Customer',
            'status' => 'completed', 'compliance_decision' => 'clear', 'completed_at' => now()]);
        $this->account = UserProviderAccount::query()->create([
            'user_id' => $this->owner->id, 'provider_id' => $provider->id,
            'external_customer_id' => 'fixture-customer', 'external_account_id' => 'fixture-wallet',
            'status' => 'active', 'provider_status' => 'clear', 'provider_sub_status' => null,
            'compliance_status' => 'clear', 'rfi_status' => 'clear', 'odd_status' => 'clear',
            'reconciliation_status' => 'reconciled', 'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(), 'provider_ids_verified_at' => now(),
        ]);
        $this->beneficiary = Beneficiary::query()->create([
            'user_id' => $this->owner->id, 'provider_id' => $provider->id,
            'external_beneficiary_id' => 'fixture-registered-payee', 'beneficiary_type' => 'individual',
            'full_name' => 'Fixture Payee', 'country_code' => 'GB', 'currency' => 'GBP',
            'payout_method' => 'LOCAL', 'account_number' => 'FIXTURE-ACCOUNT-ONLY', 'status' => 'active',
        ]);
        $this->wallet = Balance::query()->create([
            'user_id' => $this->owner->id, 'provider_id' => $provider->id, 'external_account_id' => 'fixture-wallet',
            'currency' => 'EUR', 'available_balance' => $available, 'ledger_balance' => $available,
            'reserved_balance' => '0', 'as_of' => now(), 'raw_data' => ['provider_available_balance' => $available],
        ]);
        $this->source = new DynamicConfirmationEvidenceStub($this->dynamicReview(),
            DynamicReceivingAccountBinding::fingerprint($this->beneficiary));
        $this->confirmation = new DynamicTransferConfirmationService(new DynamicReservationLedger, $this->source);
    }

    protected function dynamicReview(string $quoteId = 'fixture-quote-1', string $fee = '2',
        PayoutAmountMode $mode = PayoutAmountMode::Source, string $principal = '100', array $termChanges = [],
        bool $synthetic = false, string $quoteExpiry = '2030-01-01T01:00:00Z'): PayoutConfirmation
    {
        $from = new DateTimeImmutable('2030-01-01T00:00:00Z');
        $expiry = new DateTimeImmutable('2030-01-01T01:00:00Z');
        $c = new CorridorIdentity('nium', 'sandbox', 'fixture-client', 'fixture-entity',
            'fixture-customer', 'fixture-wallet', 'fixture-wallet', 'EUR', 'GB', 'GBP', 'LOCAL',
            'INDIVIDUAL', 'INDIVIDUAL', 'fixture-contract-v1', 'fixture-profile-v1');
        $scope = new CustomerAccountScope((string) $this->owner->id, $c->provider, $c->environment,
            $c->client, $c->legalEntity, $c->customer, $c->account, $c->customerType, $c->profileVersion,
            ['EUR' => 'fixture-wallet'], EvidenceState::Verified, $from, $expiry, RevocationStatus::NotRevoked);
        $beneficiary = new BeneficiarySnapshot($scope, 'GB', 'GBP', 'LOCAL', 'INDIVIDUAL',
            EvidenceState::Verified, true, $from, $expiry, RevocationStatus::NotRevoked);
        $context = new PayoutQuoteContext($c, $beneficiary, (string) $this->account->provider_id,
            (string) $this->account->id, (string) $this->beneficiary->id, 'fixture-registered-payee',
            DynamicReceivingAccountBinding::fingerprint($this->beneficiary));
        $request = new PayoutQuoteRequest($c->customer, 'EUR', 'GBP', $mode,
            $mode === PayoutAmountMode::Source ? $principal : '80', '1_hour');
        // This fixture supplies authoritative-looking classifications to a test stub
        // only; it never fetches evidence or registers the stub in application code.
        $json = '{"id":"'.$quoteId.'","quoteType":"payout","quoteIntent":"EXECUTABLE",'
            .'"customerHashId":"fixture-customer","sourceCurrencyCode":"EUR","destinationCurrencyCode":"GBP",'
            .'"conversionSchedule":"immediate","executionType":"at_conversion_time","lockPeriod":"1_hour",'
            .'"sourceAmount":'.$principal.',"destinationAmount":80,"netExchangeRate":0.8,"expiryTime":"'.$quoteExpiry.'"}';
        $quote = PayoutRateQuote::fromJson($json, $request, $context);
        $money = static fn (string $currency, string $amount): Money => new Money($currency, new Decimal($amount));
        $terms = new FinancialTerms(...array_replace([
            'corridor' => $c, 'principal' => $money('EUR', $principal), 'fxRequirement' => FxRequirement::Required,
            'fxRate' => new Decimal('0.8'), 'quoteId' => $quoteId, 'quoteProvenance' => 'fixture-provider-response',
            'fxMarginPercent' => new Decimal('0'), 'conversionAmount' => $money('GBP', '80'),
            'transactionFee' => $money('EUR', $fee), 'feePayer' => FeePayer::Sender,
            'feeAllocation' => FeeAllocation::SenderAdditional,
            'totalWalletDebit' => $money('EUR', (new Decimal($principal))->plus(new Decimal($fee))->value),
            'estimatedRecipient' => $money('GBP', '80'), 'pricingSource' => 'fixture-pricing',
            'pricingVersion' => 'fixture-pricing-v1', 'feePlanId' => 'fixture-fee-plan',
        ], $termChanges));
        $contract = new PayoutContract($c, 'fixture-contract', EvidenceState::Verified,
            $synthetic ? EvidenceSource::Synthetic : EvidenceSource::LocalAttestation, RevocationStatus::NotRevoked,
            $terms->pricingVersion, $terms->feePlanId, ['LOCAL'], ['EUR' => 8, 'GBP' => 2], 10, 'EXACT',
            'fixture-all-fees-and-debit', 'fixture-fx-calculation', 'EUR', FeePayer::Sender,
            FeeAllocation::SenderAdditional, $from, $expiry);
        $pricing = [];
        foreach ([PricingStage::Effective, PricingStage::ProviderQuote] as $stage) {
            $pricing[] = new PricingEvidence($stage === PricingStage::ProviderQuote ? $quoteId : 'fixture-effective',
                $stage, EvidenceState::Verified, $synthetic ? EvidenceSource::Synthetic :
                    ($stage === PricingStage::ProviderQuote ? EvidenceSource::ProviderResponse : EvidenceSource::LocalAttestation),
                $stage === PricingStage::ProviderQuote ? $terms->quoteProvenance : 'fixture-effective-evidence',
                $terms, $from, $from, $expiry, RevocationStatus::NotRevoked);
        }
        $eligibility = array_map(static fn (EvidenceKind $kind): CorridorEvidence =>
            new CorridorEvidence($c, $kind, EvidenceState::Verified, 'fixture-'.$kind->value, $from, $expiry, $synthetic),
            EvidenceKind::required());

        return PayoutConfirmation::prepare((string) $this->owner->id, $context, $quote, $terms, $contract,
            $pricing, $eligibility, [$mode->value => 'fixture-approved-amount-semantics'],
            new DateTimeImmutable('2030-01-01T00:10:00Z'));
    }

    protected function confirmDynamic(string $reference = 'fixture-client-reference', ?string $fingerprint = null): \App\Models\NiumDynamicIntent
    {
        return $this->confirmation->confirm($this->owner, $this->account->id, $this->beneficiary->id,
            $this->wallet->id, $reference, $fingerprint ?? $this->source->review->fingerprint());
    }
}
