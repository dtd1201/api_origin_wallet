<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Nium\DynamicMultiCurrency\CorridorIdentity;
use App\Domain\Nium\DynamicMultiCurrency\EvidenceKind;
use App\Domain\Nium\DynamicMultiCurrency\EvidenceState;
use App\Domain\Nium\DynamicMultiCurrency\Financial\Decimal;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FeeAllocation;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FeePayer;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FinancialTerms;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FxRequirement;
use App\Domain\Nium\DynamicMultiCurrency\Financial\Money;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutContract;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PricingEvidence;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PricingStage;
use App\Domain\Nium\DynamicMultiCurrency\Options\BeneficiarySnapshot;
use App\Domain\Nium\DynamicMultiCurrency\Options\CustomerAccountScope;
use App\Domain\Nium\DynamicMultiCurrency\Options\TransferOption;
use App\Domain\Nium\DynamicMultiCurrency\Options\TransferOptionCandidate;
use App\Domain\Nium\DynamicMultiCurrency\Options\TransferOptionsService;
use App\Domain\Nium\DynamicMultiCurrency\Registry\EvidenceRecord;
use App\Domain\Nium\DynamicMultiCurrency\Registry\EvidenceSource;
use App\Domain\Nium\DynamicMultiCurrency\Registry\RevocationStatus;
use Closure;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Synthetic offline snapshots only, including fixtures claiming non-synthetic origins. */
final class NiumDynamicTransferOptionsTest extends TestCase
{
    private Closure $autoloadGuard;

    private array $runtimeLoadAttempts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->autoloadGuard = function (string $class): void {
            if ((str_starts_with($class, 'App\\') && ! str_starts_with($class, 'App\\Domain\\Nium\\DynamicMultiCurrency\\'))
                || str_starts_with($class, 'Illuminate\\') || str_starts_with($class, 'GuzzleHttp\\')) {
                $this->runtimeLoadAttempts[] = $class;
                throw new LogicException('Options must not load HTTP, database, wallet or provider runtime.');
            }
        };
        spl_autoload_register($this->autoloadGuard, true, true);
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->autoloadGuard);
        try {
            $this->assertSame([], $this->runtimeLoadAttempts);
        } finally {
            parent::tearDown();
        }
    }

    private function time(string $value = '2026-11-09T00:30:00Z'): DateTimeImmutable
    {
        return new DateTimeImmutable($value);
    }

    private function scope(array $changes = []): CustomerAccountScope
    {
        return new CustomerAccountScope(...array_replace([
            'ownerUserId' => 'fixture-user', 'provider' => 'nium', 'environment' => 'sandbox',
            'client' => 'fixture-client', 'legalEntity' => 'fixture-entity', 'customer' => 'fixture-customer',
            'account' => 'fixture-account', 'customerType' => 'CORPORATE', 'profileVersion' => 'fixture-profile-v1',
            'fundingWallets' => ['EUR' => 'fixture-wallet'], 'ownership' => EvidenceState::Verified,
            'validFrom' => $this->time('2026-11-09T00:00:00Z'), 'expiresAt' => $this->time('2026-11-09T01:00:00Z'),
            'revocation' => RevocationStatus::NotRevoked,
        ], $changes));
    }

    private function corridor(array $changes = []): CorridorIdentity
    {
        return new CorridorIdentity(...array_replace([
            'provider' => 'nium', 'environment' => 'sandbox', 'client' => 'fixture-client',
            'legalEntity' => 'fixture-entity', 'customer' => 'fixture-customer', 'account' => 'fixture-account',
            'fundingWallet' => 'fixture-wallet', 'sourceCurrency' => 'EUR', 'destinationCountry' => 'DE',
            'destinationCurrency' => 'EUR', 'payoutMethod' => 'LOCAL', 'customerType' => 'CORPORATE',
            'beneficiaryType' => 'INDIVIDUAL', 'contractVersion' => 'fixture-contract-v1', 'profileVersion' => 'fixture-profile-v1',
        ], $changes));
    }

    private function record(CorridorIdentity $corridor, EvidenceKind $kind, array $changes = []): EvidenceRecord
    {
        return new EvidenceRecord(...array_replace([
            'id' => 'fixture-record-'.$kind->value, 'corridor' => $corridor, 'kind' => $kind,
            'state' => EvidenceState::Verified, 'source' => EvidenceSource::Synthetic,
            'provenance' => 'private-fixture-document', 'issuedAt' => $this->time('2026-11-09T00:00:00Z'),
            'collectedAt' => $this->time('2026-11-09T00:00:00Z'), 'validFrom' => $this->time('2026-11-09T00:00:00Z'),
            'expiresAt' => $this->time('2026-11-09T01:00:00Z'), 'revocation' => RevocationStatus::NotRevoked,
        ], $changes));
    }

    private function records(CorridorIdentity $corridor, bool $synthetic = true): array
    {
        return array_map(fn (EvidenceKind $kind): EvidenceRecord => $this->record($corridor, $kind,
            ['source' => $synthetic ? EvidenceSource::Synthetic : EvidenceSource::LocalAttestation]), EvidenceKind::required());
    }

    private function beneficiary(CustomerAccountScope $scope, CorridorIdentity $corridor, array $changes = []): BeneficiarySnapshot
    {
        return new BeneficiarySnapshot(...array_replace([
            'scope' => $scope, 'destinationCountry' => $corridor->destinationCountry,
            'destinationCurrency' => $corridor->destinationCurrency, 'payoutMethod' => $corridor->payoutMethod,
            'beneficiaryType' => $corridor->beneficiaryType, 'state' => EvidenceState::Verified, 'registered' => true,
            'observedAt' => $this->time('2026-11-09T00:00:00Z'), 'expiresAt' => $this->time('2026-11-09T01:00:00Z'),
            'revocation' => RevocationStatus::NotRevoked,
        ], $changes));
    }

    private function terms(CorridorIdentity $corridor): FinancialTerms
    {
        $money = static fn (string $amount): Money => new Money($corridor->sourceCurrency, new Decimal($amount));

        return new FinancialTerms(
            corridor: $corridor, principal: $money('100'), fxRequirement: FxRequirement::None,
            fxRate: null, quoteId: null, quoteProvenance: null, fxMarginPercent: null, conversionAmount: null,
            transactionFee: $money('2'), feePayer: FeePayer::Sender, feeAllocation: FeeAllocation::SenderAdditional,
            totalWalletDebit: $money('102'), estimatedRecipient: $money('100'),
            pricingSource: 'private-fixture-pricing-document', pricingVersion: 'fixture-pricing-v1', feePlanId: 'private-fixture-plan',
        );
    }

    private function contract(FinancialTerms $terms, bool $synthetic): PayoutContract
    {
        return new PayoutContract(
            corridor: $terms->corridor, reference: 'private-fixture-contract', state: EvidenceState::Verified,
            source: $synthetic ? EvidenceSource::Synthetic : EvidenceSource::LocalAttestation,
            revocation: RevocationStatus::NotRevoked, pricingVersion: $terms->pricingVersion, feePlanId: $terms->feePlanId,
            supportedMethods: [$terms->corridor->payoutMethod], currencyScales: [$terms->corridor->sourceCurrency => 2],
            rateScale: 6, roundingRule: 'EXACT', calculationReference: 'fixture-equation', fxCalculationReference: null,
            feeCurrency: $terms->corridor->sourceCurrency, feePayer: FeePayer::Sender, feeAllocation: FeeAllocation::SenderAdditional,
            validFrom: $this->time('2026-11-09T00:00:00Z'), expiresAt: $this->time('2026-11-09T01:00:00Z'),
        );
    }

    private function pricing(FinancialTerms $terms, bool $synthetic = true): array
    {
        return array_map(fn (PricingStage $stage): PricingEvidence => new PricingEvidence(
            id: 'private-fixture-price-'.$stage->value, stage: $stage, state: EvidenceState::Verified,
            source: $synthetic ? EvidenceSource::Synthetic : EvidenceSource::LocalAttestation,
            provenance: 'private-fixture-price-document', terms: $terms,
            issuedAt: $this->time('2026-11-09T00:00:00Z'), validFrom: $this->time('2026-11-09T00:00:00Z'),
            expiresAt: $this->time('2026-11-09T01:00:00Z'), revocation: RevocationStatus::NotRevoked,
        ), [PricingStage::Effective, PricingStage::CustomerConfirmed]);
    }

    private function candidate(array $changes = [], bool $synthetic = true): TransferOptionCandidate
    {
        $scope = $changes['scope'] ?? $this->scope();
        $corridor = $changes['corridor'] ?? $this->corridor();
        $terms = $this->terms($corridor);
        $pricing = $changes['pricing'] ?? $this->pricing($terms, $synthetic);
        unset($changes['pricing']);

        return new TransferOptionCandidate(...array_values(array_replace([
            'scope' => $scope, 'corridor' => $corridor, 'corridorEvidence' => $this->records($corridor, $synthetic),
            'beneficiary' => $this->beneficiary($scope, $corridor), 'financialTerms' => $terms,
            'financialContract' => $this->contract($terms, $synthetic),
        ], $changes)), ...$pricing);
    }

    private function response(TransferOptionCandidate ...$candidates): array
    {
        return (new TransferOptionsService)->options('fixture-user', 'fixture-user', $this->scope(), $this->time(), ...$candidates)->toArray();
    }

    private function assertClosed(array $option): void
    {
        $this->assertFalse($option['enabled']);
        $this->assertSame('DENIED', $option['preparation_eligibility']);
        $this->assertSame('DENIED', $option['dispatch_eligibility']);
        $this->assertContains('dynamic_preparation_closed', $option['reason_codes']);
        $this->assertContains('dynamic_dispatch_disabled', $option['reason_codes']);
    }

    public function test_complete_synthetic_assertions_do_not_activate_or_verify_live_financial_readiness(): void
    {
        $option = $this->response($this->candidate())['options'][0];
        $this->assertSame('COMPATIBLE', $option['beneficiary_compatibility']);
        $this->assertSame('UNKNOWN', $option['financial_readiness']);
        $this->assertContains('synthetic_evidence', $option['reason_codes']);
        $this->assertClosed($option);
    }

    public function test_asserted_financial_consistency_is_separate_from_activation_and_entitlement(): void
    {
        $records = $this->records($this->corridor(), false);
        foreach ($records as $index => $record) {
            if ($record->kind === EvidenceKind::AccountEntitlement) {
                $records[$index] = $this->record($this->corridor(), $record->kind,
                    ['state' => EvidenceState::Denied, 'source' => EvidenceSource::LocalAttestation]);
            }
        }
        $option = $this->response($this->candidate(['corridorEvidence' => $records], false))['options'][0];
        $this->assertSame('VERIFIED', $option['financial_readiness']);
        $this->assertContains('account_entitlement_denied', $option['reason_codes']);
        $this->assertClosed($option);
        $this->assertClosed($this->response($this->candidate([], false))['options'][0]);
    }

    public function test_capability_and_pricing_without_entitlement_or_activation_stay_closed(): void
    {
        foreach ([EvidenceKind::AccountEntitlement, EvidenceKind::LocalActivation, EvidenceKind::FinancialReadiness] as $missing) {
            $records = array_values(array_filter($this->records($this->corridor(), false), static fn (EvidenceRecord $r): bool => $r->kind !== $missing));
            $option = $this->response($this->candidate(['corridorEvidence' => $records], false))['options'][0];
            $this->assertSame('UNKNOWN', $option['financial_readiness']);
            $this->assertContains('registry_evidence_incomplete', $option['reason_codes']);
            $this->assertClosed($option);
        }
    }

    public function test_actor_route_and_owner_must_match_exactly(): void
    {
        foreach ([['foreign-user', 'fixture-user'], ['fixture-user', 'foreign-user'], ['', 'fixture-user'], ['FIXTURE-USER', 'fixture-user']] as [$actor, $route]) {
            $response = (new TransferOptionsService)->options($actor, $route, $this->scope(), $this->time(), $this->candidate())->toArray();
            $this->assertSame(['options' => [], 'reason_codes' => ['options_scope_not_authorized']], $response);
        }
    }

    public function test_unknown_expired_future_or_revoked_ownership_discloses_no_options(): void
    {
        foreach ([['ownership' => EvidenceState::Unknown], ['ownership' => EvidenceState::Hold],
            ['revocation' => RevocationStatus::Revoked], ['revocation' => RevocationStatus::Unknown],
            ['expiresAt' => $this->time()], ['validFrom' => $this->time('2026-11-09T00:31:00Z')]] as $changes) {
            $scope = $this->scope($changes);
            $response = (new TransferOptionsService)->options('fixture-user', 'fixture-user', $scope, $this->time(), $this->candidate(['scope' => $scope]))->toArray();
            $this->assertSame(['options' => [], 'reason_codes' => ['options_scope_not_authorized']], $response);
        }
    }

    public static function foreignScopes(): array
    {
        return [
            'provider' => [['provider' => 'other']], 'environment' => [['environment' => 'production']],
            'client' => [['client' => 'foreign-client']], 'entity' => [['legalEntity' => 'foreign-entity']],
            'customer' => [['customer' => 'foreign-customer']], 'account' => [['account' => 'foreign-account']],
            'type' => [['customerType' => 'INDIVIDUAL']], 'profile' => [['profileVersion' => 'foreign-profile']],
            'wallet' => [['fundingWallet' => 'foreign-wallet']], 'currency' => [['sourceCurrency' => 'USD']],
        ];
    }

    #[DataProvider('foreignScopes')]
    public function test_foreign_corridor_never_discloses_an_option(array $changes): void
    {
        $foreign = $this->candidate(['corridor' => $this->corridor($changes)]);
        $this->assertSame(['options' => [], 'reason_codes' => []], $this->response($foreign));
        $this->assertSame($this->response($this->candidate()), $this->response($foreign, $this->candidate()));
    }

    public function test_foreign_ownership_projection_is_ignored_even_for_identical_corridor(): void
    {
        foreach ([['ownerUserId' => 'foreign-user'], ['account' => 'foreign-account'], ['customer' => 'foreign-customer']] as $change) {
            $this->assertSame([], $this->response($this->candidate(['scope' => $this->scope($change)]))['options']);
        }
    }

    public function test_foreign_evidence_and_duplicate_identifiers_cannot_influence_own_response(): void
    {
        $own = $this->candidate([], false);
        $foreign = $this->corridor(['customer' => 'foreign-customer']);
        $foreignRecord = $this->record($foreign, EvidenceKind::ProviderCapability, ['id' => 'fixture-record-'.EvidenceKind::ProviderCapability->value]);
        $candidate = $this->candidate([
            'corridorEvidence' => [...$this->records($this->corridor(), false), $foreignRecord, $foreignRecord],
            'pricing' => [...$this->pricing($this->terms($this->corridor()), false), ...$this->pricing($this->terms($foreign))],
        ], false);
        $this->assertSame($this->response($own), $this->response($candidate));
    }

    public static function invalidEvidence(): array
    {
        return [
            'expired' => ['expiresAt', '2026-11-09T00:30:00Z', 'registry_evidence_expired'],
            'future issued' => ['issuedAt', '2026-11-09T00:31:00Z', 'registry_evidence_future_dated'],
            'future collected' => ['collectedAt', '2026-11-09T00:31:00Z', 'registry_evidence_future_dated'],
            'future validity' => ['validFrom', '2026-11-09T00:31:00Z', 'registry_evidence_future_dated'],
            'revoked' => ['revocation', RevocationStatus::Revoked, 'registry_evidence_revoked'],
            'unknown revocation' => ['revocation', RevocationStatus::Unknown, 'registry_revocation_unknown'],
        ];
    }

    #[DataProvider('invalidEvidence')]
    public function test_invalid_evidence_quarantines_financial_readiness(string $field, mixed $value, string $reason): void
    {
        $records = $this->records($this->corridor(), false);
        $records[0] = $this->record($this->corridor(), $records[0]->kind, [$field => is_string($value) ? $this->time($value) : $value]);
        $option = $this->response($this->candidate(['corridorEvidence' => $records], false))['options'][0];
        $this->assertContains($reason, $option['reason_codes']);
        $this->assertSame('UNKNOWN', $option['financial_readiness']);
        $this->assertClosed($option);
    }

    public function test_missing_duplicate_and_conflicting_evidence_fail_closed(): void
    {
        $records = $this->records($this->corridor(), false);
        foreach ([
            [[], 'registry_scope_not_found'],
            [[...$records, $records[0]], 'registry_duplicate_evidence_id'],
            [[...$records, $this->record($this->corridor(), $records[0]->kind, ['id' => 'conflicting-id', 'state' => EvidenceState::Denied])], 'registry_conflicting_assertions'],
        ] as [$set, $reason]) {
            $option = $this->response($this->candidate(['corridorEvidence' => $set], false))['options'][0];
            $this->assertContains($reason, $option['reason_codes']);
            $this->assertSame('UNKNOWN', $option['financial_readiness']);
            $this->assertClosed($option);
        }
    }

    public function test_beneficiary_absence_staleness_revocation_and_foreign_scope_are_unknown(): void
    {
        $scope = $this->scope();
        $corridor = $this->corridor();
        $snapshots = [null];
        foreach ([['scope' => $this->scope(['ownerUserId' => 'foreign-user'])], ['scope' => $this->scope(['account' => 'foreign-account'])],
            ['expiresAt' => $this->time()], ['observedAt' => $this->time('2026-11-09T00:31:00Z')],
            ['revocation' => RevocationStatus::Revoked], ['state' => EvidenceState::Unknown]] as $change) {
            $snapshots[] = $this->beneficiary($scope, $corridor, $change);
        }
        foreach ($snapshots as $snapshot) {
            $option = $this->response($this->candidate(['beneficiary' => $snapshot]))['options'][0];
            $this->assertSame('UNKNOWN', $option['beneficiary_compatibility']);
            $this->assertClosed($option);
        }
    }

    public function test_beneficiary_dimensions_registration_and_state_must_be_compatible(): void
    {
        foreach ([['destinationCountry' => 'GB'], ['destinationCurrency' => 'GBP'], ['payoutMethod' => 'SWIFT'],
            ['beneficiaryType' => 'CORPORATE'], ['registered' => false], ['state' => EvidenceState::Hold], ['state' => EvidenceState::Denied]] as $change) {
            $option = $this->response($this->candidate(['beneficiary' => $this->beneficiary($this->scope(), $this->corridor(), $change)]))['options'][0];
            $this->assertSame('INCOMPATIBLE', $option['beneficiary_compatibility']);
            $this->assertContains('beneficiary_incompatible', $option['reason_codes']);
            $this->assertClosed($option);
        }
    }

    public function test_missing_financial_terms_or_pricing_and_foreign_terms_cannot_verify_readiness(): void
    {
        foreach ([['financialTerms' => null], ['financialContract' => null], ['pricing' => []],
            ['financialTerms' => $this->terms($this->corridor(['account' => 'foreign-account']))]] as $change) {
            $option = $this->response($this->candidate($change, false))['options'][0];
            $this->assertNotSame('VERIFIED', $option['financial_readiness']);
            $this->assertClosed($option);
        }
    }

    public function test_expired_conflicting_and_foreign_contract_pricing_cannot_verify_readiness(): void
    {
        $terms = $this->terms($this->corridor());
        $pricing = $this->pricing($terms, false);
        $expired = new PricingEvidence(...array_replace(get_object_vars($pricing[0]), ['expiresAt' => $this->time()]));
        foreach ([
            ['pricing' => [$expired, $pricing[1]]],
            ['pricing' => [...$pricing, $pricing[0]]],
            ['financialContract' => $this->contract($this->terms($this->corridor(['account' => 'foreign-account'])), false)],
        ] as $change) {
            $option = $this->response($this->candidate($change, false))['options'][0];
            $this->assertNotSame('VERIFIED', $option['financial_readiness']);
            $this->assertClosed($option);
        }
    }

    public function test_hold_overrides_and_financial_denials_are_preserved(): void
    {
        $records = $this->records($this->corridor(), false);
        $records[] = $this->record($this->corridor(), EvidenceKind::Override, ['state' => EvidenceState::Hold, 'source' => EvidenceSource::LocalAttestation]);
        $option = $this->response($this->candidate(['corridorEvidence' => $records], false))['options'][0];
        $this->assertContains('hold_override', $option['reason_codes']);
        $this->assertClosed($option);
        foreach ($records as $index => $record) {
            if ($record->kind === EvidenceKind::FinancialReadiness) {
                $records[$index] = $this->record($this->corridor(), $record->kind, ['state' => EvidenceState::Denied]);
            }
        }
        $option = $this->response($this->candidate(['corridorEvidence' => $records]))['options'][0];
        $this->assertSame('DENIED', $option['financial_readiness']);
        $this->assertContains('financial_readiness_denied', $option['reason_codes']);
    }

    public function test_sgd_local_remains_unavailable_without_financial_terms(): void
    {
        $scope = $this->scope(['fundingWallets' => ['SGD' => 'fixture-wallet']]);
        $corridor = $this->corridor(['sourceCurrency' => 'SGD', 'destinationCurrency' => 'SGD', 'destinationCountry' => 'SG']);
        $candidate = $this->candidate(['scope' => $scope, 'corridor' => $corridor, 'financialTerms' => null], false);
        $option = (new TransferOptionsService)->options('fixture-user', 'fixture-user', $scope, $this->time(), $candidate)->toArray()['options'][0];
        $this->assertContains('financial_readiness_unverified', $option['reason_codes']);
        $this->assertNotSame('VERIFIED', $option['financial_readiness']);
        $this->assertClosed($option);
    }

    public function test_hk_swift_projection_requires_no_legacy_runtime_policy_or_submission_service(): void
    {
        // This isolated projection cannot certify or gate the existing HK workflow.
        $scope = $this->scope(['fundingWallets' => ['USD' => 'fixture-wallet']]);
        $corridor = $this->corridor(['sourceCurrency' => 'USD', 'destinationCountry' => 'HK', 'destinationCurrency' => 'USD', 'payoutMethod' => 'SWIFT']);
        $candidate = $this->candidate(['scope' => $scope, 'corridor' => $corridor, 'corridorEvidence' => [], 'financialTerms' => null]);
        $option = (new TransferOptionsService)->options('fixture-user', 'fixture-user', $scope, $this->time(), $candidate)->toArray()['options'][0];
        $this->assertSame('SWIFT', $option['payout_method']);
        $this->assertContains('registry_scope_not_found', $option['reason_codes']);
        $this->assertClosed($option);
    }

    public function test_ordering_is_stable_and_ambiguous_public_rows_never_select_a_winner(): void
    {
        $first = $this->candidate();
        $second = $this->candidate(['corridor' => $this->corridor(['destinationCountry' => 'FR'])]);
        $this->assertSame($this->response($first, $second), $this->response($second, $first));
        $reordered = $this->candidate(['corridorEvidence' => array_reverse($this->records($this->corridor()))]);
        $this->assertSame($this->response($first), $this->response($reordered));
        $conflicting = $this->candidate(['corridor' => $this->corridor(['contractVersion' => 'fixture-contract-v2'])]);
        $response = $this->response($first, $conflicting);
        $this->assertSame($response, $this->response($conflicting, $first));
        $this->assertCount(1, $response['options']);
        $this->assertContains('option_assertions_conflicting', $response['options'][0]['reason_codes']);
        $this->assertSame('UNKNOWN', $response['options'][0]['financial_readiness']);
        $this->assertClosed($response['options'][0]);
    }

    public function test_public_serialization_has_only_safe_fields_and_machine_codes(): void
    {
        $response = (new TransferOptionsService)->options('fixture-user', 'fixture-user', $this->scope(), $this->time(), $this->candidate());
        $array = $response->toArray();
        $json = json_encode($response, JSON_THROW_ON_ERROR);
        $this->assertSame($array, json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame(['options', 'reason_codes'], array_keys($array));
        $this->assertSame(['funding_currency', 'destination_country', 'destination_currency', 'payout_method',
            'beneficiary_compatibility', 'preparation_eligibility', 'financial_readiness', 'dispatch_eligibility', 'enabled', 'reason_codes'], array_keys($array['options'][0]));
        foreach (['fixture-user', 'fixture-client', 'fixture-customer', 'fixture-account', 'fixture-wallet', 'private-fixture', 'fixture-record', '100', '102'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $option = new TransferOption($this->corridor(), 'UNKNOWN', 'UNKNOWN', 'private-provider-secret', 'hold_override', 'hold_override');
        $codes = $option->toArray()['reason_codes'];
        $this->assertContains('option_evidence_unavailable', $codes);
        $this->assertNotContains('private-provider-secret', $codes);
        $sorted = array_values(array_unique($codes));
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $codes);
    }

    public function test_evaluation_is_repeatable_without_mutation_or_loading_side_effect_dependencies(): void
    {
        $candidate = $this->candidate();
        $before = serialize($candidate);
        $first = $this->response($candidate);
        $loaded = get_included_files();
        $second = $this->response($candidate);
        $after = get_included_files();
        $this->assertSame($first, $second);
        $this->assertSame($before, serialize($candidate));
        $this->assertSame($loaded, $after);
        $this->assertSame([], $this->runtimeLoadAttempts);
    }
}
