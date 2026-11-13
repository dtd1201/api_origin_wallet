<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Nium\DynamicMultiCurrency\CorridorEvidence;
use App\Domain\Nium\DynamicMultiCurrency\CorridorIdentity;
use App\Domain\Nium\DynamicMultiCurrency\EligibilityDecision;
use App\Domain\Nium\DynamicMultiCurrency\EligibilityResolver;
use App\Domain\Nium\DynamicMultiCurrency\EvidenceKind;
use App\Domain\Nium\DynamicMultiCurrency\EvidenceState;
use Closure;
use DateTimeImmutable;
use Error;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Synthetic snapshots only: no Laravel application, container or database boot. */
final class NiumDynamicMultiCurrencyTest extends TestCase
{
    private Closure $autoloadGuard;

    /** @var list<string> */
    private array $runtimeLoadAttempts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->autoloadGuard = function (string $class): void {
            if ((str_starts_with($class, 'App\\') && ! str_starts_with($class, 'App\\Domain\\Nium\\DynamicMultiCurrency\\'))
                || str_starts_with($class, 'Illuminate\\') || str_starts_with($class, 'GuzzleHttp\\')) {
                $this->runtimeLoadAttempts[] = $class;
                throw new LogicException('Offline corridor tests must not load runtime dependencies.');
            }
        };
        spl_autoload_register($this->autoloadGuard, true, true);
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->autoloadGuard);
        try {
            $this->assertSame([], $this->runtimeLoadAttempts, 'No HTTP, database, wallet or provider runtime may be loaded.');
        } finally {
            parent::tearDown();
        }
    }

    private function corridor(array $changes = []): CorridorIdentity
    {
        return new CorridorIdentity(...array_replace([
            'provider' => 'nium', 'environment' => 'sandbox',
            'client' => 'synthetic-client-a', 'legalEntity' => 'synthetic-entity-a',
            'customer' => 'synthetic-customer-a', 'account' => 'synthetic-account-a',
            'fundingWallet' => 'synthetic-wallet-a', 'sourceCurrency' => 'EUR',
            'destinationCountry' => 'GB', 'destinationCurrency' => 'GBP',
            'payoutMethod' => 'LOCAL', 'customerType' => 'CORPORATE',
            'beneficiaryType' => 'INDIVIDUAL', 'contractVersion' => 'synthetic-contract-v1',
            'profileVersion' => 'synthetic-profile-v1',
        ], $changes));
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-09T00:30:00Z');
    }

    private function record(
        CorridorIdentity $corridor,
        EvidenceKind $kind,
        EvidenceState $state = EvidenceState::Verified,
        string $observedAt = '2026-11-09T00:00:00Z',
        string $expiresAt = '2026-11-09T01:00:00Z',
        bool $synthetic = true,
    ): CorridorEvidence {
        return new CorridorEvidence($corridor, $kind, $state, 'synthetic-reference-'.$kind->value,
            new DateTimeImmutable($observedAt), new DateTimeImmutable($expiresAt), $synthetic);
    }

    /** @return list<CorridorEvidence> */
    private function complete(CorridorIdentity $corridor, bool $synthetic = true): array
    {
        return array_map(fn (EvidenceKind $kind): CorridorEvidence => $this->record($corridor, $kind, synthetic: $synthetic), EvidenceKind::required());
    }

    /** Replace a category without accidentally retaining an earlier positive assertion. */
    private function replace(array $evidence, CorridorEvidence $replacement): array
    {
        return [...array_values(array_filter($evidence, static fn (CorridorEvidence $record): bool => $record->kind !== $replacement->kind)), $replacement];
    }

    private function assertDeniedWith(EligibilityDecision $decision, string $reason): void
    {
        $this->assertSame('DENIED', $decision->preparation);
        $this->assertContains($reason, $decision->preparationReasons);
        $this->assertSame('DENIED', $decision->dispatch);
        $this->assertContains('phase_one_dispatch_disabled', $decision->dispatchReasons);
    }

    public function test_complete_synthetic_evidence_allows_only_isolated_preparation(): void
    {
        $corridor = $this->corridor();
        $decision = (new EligibilityResolver)->resolve($corridor, $this->now(), ...$this->complete($corridor));

        $this->assertSame('ALLOWED', $decision->preparation);
        $this->assertSame([], $decision->preparationReasons);
        $this->assertSame('DENIED', $decision->dispatch);
        $this->assertSame(['phase_one_dispatch_disabled', 'synthetic_evidence'], $decision->dispatchReasons);
    }

    public static function missingCategories(): array
    {
        return [
            'capability' => [EvidenceKind::ProviderCapability, 'provider_capability_missing'],
            'entitlement' => [EvidenceKind::AccountEntitlement, 'account_entitlement_missing'],
            'operations' => [EvidenceKind::OperationalReadiness, 'operational_readiness_missing'],
            'finances' => [EvidenceKind::FinancialReadiness, 'financial_readiness_missing'],
            'activation' => [EvidenceKind::LocalActivation, 'local_activation_missing'],
            'override clearance' => [EvidenceKind::OverrideClearance, 'override_clearance_missing'],
        ];
    }

    #[DataProvider('missingCategories')]
    public function test_every_required_category_must_be_present(EvidenceKind $missing, string $reason): void
    {
        $corridor = $this->corridor();
        $evidence = array_values(array_filter($this->complete($corridor), static fn (CorridorEvidence $record): bool => $record->kind !== $missing));
        $this->assertDeniedWith((new EligibilityResolver)->resolve($corridor, $this->now(), ...$evidence), $reason);
    }

    public static function deniedCategories(): array
    {
        return [
            'unsupported capability' => [EvidenceKind::ProviderCapability, 'provider_capability_denied'],
            'supported but unentitled' => [EvidenceKind::AccountEntitlement, 'account_entitlement_denied'],
            'operations denied' => [EvidenceKind::OperationalReadiness, 'operational_readiness_denied'],
            'finances denied' => [EvidenceKind::FinancialReadiness, 'financial_readiness_denied'],
            'activation denied' => [EvidenceKind::LocalActivation, 'local_activation_denied'],
            'overrides not clear' => [EvidenceKind::OverrideClearance, 'override_clearance_denied'],
        ];
    }

    #[DataProvider('deniedCategories')]
    public function test_explicit_denial_cannot_be_overridden_by_other_positive_categories(EvidenceKind $kind, string $reason): void
    {
        $corridor = $this->corridor();
        $evidence = $this->replace($this->complete($corridor), $this->record($corridor, $kind, EvidenceState::Denied));
        $this->assertDeniedWith((new EligibilityResolver)->resolve($corridor, $this->now(), ...$evidence), $reason);
    }

    public static function unknownOrHeldCategories(): array
    {
        $cases = [];
        foreach (EvidenceKind::required() as $kind) {
            $cases[$kind->value.' unknown'] = [$kind, EvidenceState::Unknown, 'evidence_unknown'];
            $cases[$kind->value.' held'] = [$kind, EvidenceState::Hold, 'hold_override'];
        }

        return $cases;
    }

    #[DataProvider('unknownOrHeldCategories')]
    public function test_unknown_or_held_assertions_never_count_as_verified(EvidenceKind $kind, EvidenceState $state, string $reason): void
    {
        $corridor = $this->corridor();
        $evidence = $this->replace($this->complete($corridor), $this->record($corridor, $kind, $state));
        $this->assertDeniedWith((new EligibilityResolver)->resolve($corridor, $this->now(), ...$evidence), $reason);
    }

    public static function mismatchedDimensions(): array
    {
        return [
            [['provider' => 'other-provider']], [['environment' => 'production']],
            [['client' => 'synthetic-client-b']], [['legalEntity' => 'synthetic-entity-b']],
            [['customer' => 'synthetic-customer-b']], [['account' => 'synthetic-account-b']],
            [['fundingWallet' => 'synthetic-wallet-b']], [['sourceCurrency' => 'USD']],
            [['destinationCountry' => 'DE']], [['destinationCurrency' => 'EUR']],
            [['payoutMethod' => 'SWIFT']], [['customerType' => 'INDIVIDUAL']],
            [['beneficiaryType' => 'CORPORATE']], [['contractVersion' => 'synthetic-contract-v2']],
            [['profileVersion' => 'synthetic-profile-v2']],
        ];
    }

    #[DataProvider('mismatchedDimensions')]
    public function test_evidence_must_match_every_identity_dimension(array $changes): void
    {
        $corridor = $this->corridor();
        $other = $this->corridor($changes);
        $this->assertFalse($corridor->equals($other));
        $evidence = $this->replace($this->complete($corridor), $this->record($other, EvidenceKind::AccountEntitlement));
        $decision = (new EligibilityResolver)->resolve($corridor, $this->now(), ...$evidence);
        $this->assertDeniedWith($decision, 'evidence_scope_mismatch');
        $this->assertContains('account_entitlement_missing', $decision->preparationReasons);
    }

    public static function invalidWindows(): array
    {
        return [
            'stale' => ['2026-11-08T00:00:00Z', '2026-11-08T01:00:00Z', 'evidence_stale'],
            'expires exactly now' => ['2026-11-09T00:00:00Z', '2026-11-09T00:30:00Z', 'evidence_stale'],
            'future observation' => ['2026-11-09T00:31:00Z', '2026-11-09T01:00:00Z', 'evidence_not_yet_valid'],
            'reversed validity' => ['2026-11-09T00:20:00Z', '2026-11-09T00:10:00Z', 'evidence_invalid_window'],
            'empty validity' => ['2026-11-09T00:00:00Z', '2026-11-09T00:00:00Z', 'evidence_invalid_window'],
        ];
    }

    #[DataProvider('invalidWindows')]
    public function test_invalid_or_stale_evidence_fails_closed(string $observedAt, string $expiresAt, string $reason): void
    {
        $corridor = $this->corridor();
        $evidence = $this->replace($this->complete($corridor), $this->record($corridor, EvidenceKind::ProviderCapability,
            observedAt: $observedAt, expiresAt: $expiresAt));
        $this->assertDeniedWith((new EligibilityResolver)->resolve($corridor, $this->now(), ...$evidence), $reason);
    }

    public function test_contradictory_and_duplicate_evidence_is_order_independent(): void
    {
        $corridor = $this->corridor();
        foreach ([EvidenceState::Denied, EvidenceState::Verified] as $state) {
            $evidence = [...$this->complete($corridor), $this->record($corridor, EvidenceKind::AccountEntitlement, $state)];
            $forward = (new EligibilityResolver)->resolve($corridor, $this->now(), ...$evidence);
            $backward = (new EligibilityResolver)->resolve($corridor, $this->now(), ...array_reverse($evidence));
            $this->assertDeniedWith($forward, 'evidence_conflicting');
            $this->assertEquals($forward, $backward);
        }
    }

    public function test_a_fresh_positive_does_not_replace_stale_evidence(): void
    {
        $corridor = $this->corridor();
        $evidence = [...$this->complete($corridor), $this->record($corridor, EvidenceKind::AccountEntitlement,
            observedAt: '2026-11-08T00:00:00Z', expiresAt: '2026-11-08T01:00:00Z')];
        $decision = (new EligibilityResolver)->resolve($corridor, $this->now(), ...$evidence);
        $this->assertDeniedWith($decision, 'evidence_stale');
        $this->assertContains('evidence_conflicting', $decision->preparationReasons);
    }

    public static function overrideStates(): array
    {
        return [
            [EvidenceState::Hold, 'hold_override'], [EvidenceState::Denied, 'deny_override'],
            [EvidenceState::Unknown, 'override_unresolved'], [EvidenceState::Verified, 'override_unresolved'],
        ];
    }

    #[DataProvider('overrideStates')]
    public function test_override_records_never_grant_eligibility(EvidenceState $state, string $reason): void
    {
        $corridor = $this->corridor();
        $evidence = [...$this->complete($corridor), $this->record($corridor, EvidenceKind::Override, $state)];
        $this->assertDeniedWith((new EligibilityResolver)->resolve($corridor, $this->now(), ...$evidence), $reason);
    }

    public function test_expired_and_mismatched_holds_cannot_silently_open_a_corridor(): void
    {
        $corridor = $this->corridor();
        $expired = $this->record($corridor, EvidenceKind::Override, EvidenceState::Hold,
            '2026-11-08T00:00:00Z', '2026-11-08T01:00:00Z');
        $decision = (new EligibilityResolver)->resolve($corridor, $this->now(), ...[...$this->complete($corridor), $expired]);
        $this->assertDeniedWith($decision, 'hold_override');
        $this->assertContains('evidence_stale', $decision->preparationReasons);

        $other = $this->record($this->corridor(['environment' => 'production']), EvidenceKind::Override, EvidenceState::Hold);
        $this->assertDeniedWith((new EligibilityResolver)->resolve($corridor, $this->now(), ...[...$this->complete($corridor), $other]), 'evidence_scope_mismatch');
    }

    public function test_sgd_local_requires_the_same_complete_scoped_evidence_as_other_corridors(): void
    {
        foreach (['sandbox', 'production'] as $environment) {
            $corridor = $this->corridor(['environment' => $environment, 'destinationCountry' => 'SG', 'destinationCurrency' => 'SGD']);
            $decision = (new EligibilityResolver)->resolve($corridor, $this->now(), ...$this->complete($corridor));
            $this->assertSame('ALLOWED', $decision->preparation);
            $this->assertSame('DENIED', $decision->dispatch);
            $this->assertDeniedWith((new EligibilityResolver)->resolve($corridor, $this->now()), 'account_entitlement_missing');
        }
    }

    public function test_unsupported_payout_method_is_denied_without_falling_back_to_another_rail(): void
    {
        $corridor = $this->corridor(['payoutMethod' => 'CASH']);
        $evidence = $this->replace($this->complete($corridor), $this->record($corridor, EvidenceKind::ProviderCapability, EvidenceState::Denied));
        $this->assertDeniedWith((new EligibilityResolver)->resolve($corridor, $this->now(), ...$evidence), 'provider_capability_denied');
        $this->assertDeniedWith((new EligibilityResolver)->resolve($corridor, $this->now(), ...$this->complete($this->corridor())), 'evidence_scope_mismatch');
    }

    public function test_unknown_corridor_and_other_provider_are_denied(): void
    {
        $this->assertDeniedWith((new EligibilityResolver)->resolve($this->corridor(), $this->now()), 'unknown_corridor');
        $corridor = $this->corridor(['provider' => 'other-provider']);
        $this->assertDeniedWith((new EligibilityResolver)->resolve($corridor, $this->now(), ...$this->complete($corridor)), 'unsupported_provider');
    }

    public function test_synthetic_evidence_never_authorizes_production_dispatch(): void
    {
        foreach ([true, false] as $synthetic) {
            // The false case is still a synthetic fixture: it tests a claimed attestation.
            $corridor = $this->corridor(['environment' => 'production']);
            $decision = (new EligibilityResolver)->resolve($corridor, $this->now(), ...$this->complete($corridor, $synthetic));
            $this->assertSame('ALLOWED', $decision->preparation);
            $this->assertSame('DENIED', $decision->dispatch);
            $this->assertContains('phase_one_dispatch_disabled', $decision->dispatchReasons);
            $this->assertSame($synthetic, in_array('synthetic_evidence', $decision->dispatchReasons, true));
        }
    }

    public function test_mixed_synthetic_and_attested_assertions_fail_closed(): void
    {
        $corridor = $this->corridor();
        $evidence = $this->replace($this->complete($corridor), $this->record($corridor, EvidenceKind::AccountEntitlement, synthetic: false));
        $this->assertDeniedWith((new EligibilityResolver)->resolve($corridor, $this->now(), ...$evidence), 'evidence_origin_mixed');
    }

    public function test_identity_is_exact_and_cannot_be_mutated(): void
    {
        $corridor = $this->corridor();
        $this->assertTrue($corridor->equals($this->corridor()));
        $this->expectException(Error::class);
        $corridor->fundingWallet = 'replacement-wallet';
    }

    public static function incompleteIdentities(): array
    {
        return [
            [['provider' => '']], [['client' => '']], [['legalEntity' => '']],
            [['customer' => '']], [['account' => '']], [['fundingWallet' => '']],
            [['sourceCurrency' => '']], [['destinationCountry' => '']], [['destinationCurrency' => '']],
            [['payoutMethod' => '']], [['customerType' => '']], [['beneficiaryType' => '']],
            [['contractVersion' => '']], [['profileVersion' => '']], [['environment' => '']],
            [['environment' => 'Production']], [['sourceCurrency' => 'eur']],
            [['destinationCountry' => ' GBR']], [['destinationCurrency' => 'gbp']],
            [['payoutMethod' => 'local']], [['customer' => "synthetic\ncustomer"]],
        ];
    }

    #[DataProvider('incompleteIdentities')]
    public function test_incomplete_or_noncanonical_identity_never_gets_defaults(array $changes): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->corridor($changes);
    }

    public function test_unreferenced_assertions_are_not_accepted(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CorridorEvidence($this->corridor(), EvidenceKind::ProviderCapability, EvidenceState::Verified,
            '', $this->now(), new DateTimeImmutable('2026-11-09T01:00:00Z'), true);
    }

    public function test_resolution_is_repeatable_with_no_runtime_loading_or_input_changes(): void
    {
        $corridor = $this->corridor();
        $evidence = $this->complete($corridor);
        $now = $this->now();
        $resolver = new EligibilityResolver;
        $expected = $resolver->resolve($corridor, $now, ...$evidence);
        $before = serialize([$corridor, $evidence, $now, $resolver]);
        $includedBefore = get_included_files();
        $actual = $resolver->resolve($corridor, $now, ...$evidence);
        $includedAfter = get_included_files();

        $this->assertEquals($expected, $actual);
        $this->assertSame($before, serialize([$corridor, $evidence, $now, $resolver]));
        $this->assertSame($includedBefore, $includedAfter);
        $this->assertSame([], $this->runtimeLoadAttempts);
    }
}
