<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Nium\DynamicMultiCurrency\CorridorIdentity;
use App\Domain\Nium\DynamicMultiCurrency\EvidenceKind;
use App\Domain\Nium\DynamicMultiCurrency\EvidenceState;
use App\Domain\Nium\DynamicMultiCurrency\Registry\EvidenceRecord;
use App\Domain\Nium\DynamicMultiCurrency\Registry\EvidenceSnapshot;
use App\Domain\Nium\DynamicMultiCurrency\Registry\EvidenceSource;
use App\Domain\Nium\DynamicMultiCurrency\Registry\RegistryEligibilityAdapter;
use App\Domain\Nium\DynamicMultiCurrency\Registry\RegistryEvaluation;
use App\Domain\Nium\DynamicMultiCurrency\Registry\RevocationStatus;
use Closure;
use DateTimeImmutable;
use Error;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Synthetic fixtures only. No Laravel application, configuration or database boot. */
final class NiumDynamicMultiCurrencyRegistryTest extends TestCase
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
                throw new LogicException('Offline registry tests must not load runtime dependencies.');
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

    private function time(string $value = '2026-11-09T00:30:00Z'): DateTimeImmutable
    {
        return new DateTimeImmutable($value);
    }

    private function record(CorridorIdentity $corridor, EvidenceKind $kind, array $changes = []): EvidenceRecord
    {
        return new EvidenceRecord(...array_replace([
            'id' => 'synthetic-a-'.$kind->value, 'corridor' => $corridor, 'kind' => $kind,
            'state' => EvidenceState::Verified, 'source' => EvidenceSource::Synthetic,
            'provenance' => 'synthetic-document/'.$kind->value,
            'issuedAt' => $this->time('2026-11-09T00:00:00Z'),
            'collectedAt' => $this->time('2026-11-09T00:05:00Z'),
            'validFrom' => $this->time('2026-11-09T00:10:00Z'),
            'expiresAt' => $this->time('2026-11-09T01:00:00Z'),
            'revocation' => RevocationStatus::NotRevoked,
        ], $changes));
    }

    /** @return list<EvidenceRecord> */
    private function complete(CorridorIdentity $corridor): array
    {
        return array_map(fn (EvidenceKind $kind): EvidenceRecord => $this->record($corridor, $kind), EvidenceKind::required());
    }

    private function without(array $records, EvidenceKind $kind): array
    {
        return array_values(array_filter($records, static fn (EvidenceRecord $record): bool => $record->kind !== $kind));
    }

    private function replace(array $records, EvidenceRecord $replacement): array
    {
        return [...$this->without($records, $replacement->kind), $replacement];
    }

    private function evaluate(CorridorIdentity $corridor, array $records): RegistryEvaluation
    {
        return (new RegistryEligibilityAdapter)->evaluate(new EvidenceSnapshot(...$records), $corridor, $this->time());
    }

    private function assertRejected(RegistryEvaluation $evaluation, string $reason): void
    {
        $this->assertFalse($evaluation->lookup->accepted);
        $this->assertContains($reason, $evaluation->lookup->reasons);
        $this->assertSame([], $evaluation->lookup->records, 'No partial usable set may escape a rejected lookup.');
        $this->assertSame('DENIED', $evaluation->decision->preparation);
        $this->assertSame('DENIED', $evaluation->decision->dispatch);
        $this->assertContains('phase_one_dispatch_disabled', $evaluation->decision->dispatchReasons);
    }

    public function test_valid_lookup_preserves_full_records_and_allows_only_isolated_preparation(): void
    {
        $corridor = $this->corridor();
        $records = $this->complete($corridor);
        $evaluation = $this->evaluate($this->corridor(), $records);
        $this->assertTrue($evaluation->lookup->accepted);
        $this->assertSame([], $evaluation->lookup->reasons);
        $this->assertCount(6, $evaluation->lookup->records);
        foreach ($records as $record) {
            $found = array_values(array_filter($evaluation->lookup->records, static fn (EvidenceRecord $candidate): bool => $candidate->id === $record->id));
            $this->assertSame([$record], $found, 'Provenance, timestamps, revocation and full identity must survive lookup intact.');
        }
        $this->assertSame('ALLOWED', $evaluation->decision->preparation);
        $this->assertSame('DENIED', $evaluation->decision->dispatch);
        $this->assertSame(['phase_one_dispatch_disabled', 'synthetic_evidence'], $evaluation->decision->dispatchReasons);
    }

    public static function foreignScopes(): array
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

    #[DataProvider('foreignScopes')]
    public function test_no_identity_dimension_can_be_borrowed_from_foreign_scope(array $changes): void
    {
        $corridor = $this->corridor();
        $foreign = $this->record($this->corridor($changes), EvidenceKind::AccountEntitlement, ['id' => 'synthetic-foreign-entitlement']);
        $records = [...$this->without($this->complete($corridor), EvidenceKind::AccountEntitlement), $foreign];
        $evaluation = $this->evaluate($corridor, $records);
        $this->assertRejected($evaluation, 'registry_evidence_incomplete');
        $this->assertSame(['account_entitlement'], $evaluation->lookup->missingKinds);
    }

    public function test_foreign_scopes_are_excluded_without_poisoning_a_complete_exact_match(): void
    {
        $corridor = $this->corridor();
        $foreign = $this->record($this->corridor(['customer' => 'synthetic-customer-b']), EvidenceKind::Override, [
            'id' => 'synthetic-foreign-hold', 'state' => EvidenceState::Hold,
            'revocation' => RevocationStatus::Revoked, 'expiresAt' => $this->time('2026-11-09T00:20:00Z'),
        ]);
        $evaluation = $this->evaluate($corridor, [...$this->complete($corridor), $foreign]);
        $this->assertTrue($evaluation->lookup->accepted);
        $this->assertCount(6, $evaluation->lookup->records);
        $this->assertSame('ALLOWED', $evaluation->decision->preparation);
        $this->assertSame('DENIED', $evaluation->decision->dispatch);
    }

    public function test_unknown_exact_scope_is_rejected_even_when_other_scopes_exist(): void
    {
        $evaluation = $this->evaluate($this->corridor(['account' => 'synthetic-account-b']), $this->complete($this->corridor()));
        $this->assertRejected($evaluation, 'registry_scope_not_found');
        $this->assertCount(6, $evaluation->lookup->missingKinds);
        $this->assertSame([], $evaluation->lookup->rejectedIds);
    }

    public static function invalidTimes(): array
    {
        return [
            'expired' => [['expiresAt' => '2026-11-09T00:20:00Z'], 'registry_evidence_expired'],
            'expiry boundary' => [['expiresAt' => '2026-11-09T00:30:00Z'], 'registry_evidence_expired'],
            'future issuance' => [['issuedAt' => '2026-11-09T00:31:00Z', 'collectedAt' => '2026-11-09T00:32:00Z'], 'registry_evidence_future_dated'],
            'future collection' => [['collectedAt' => '2026-11-09T00:31:00Z'], 'registry_evidence_future_dated'],
            'future validity' => [['validFrom' => '2026-11-09T00:31:00Z'], 'registry_evidence_future_dated'],
            'empty window' => [['expiresAt' => '2026-11-09T00:10:00Z'], 'registry_invalid_validity_window'],
            'reverse window' => [['validFrom' => '2026-11-09T02:00:00Z'], 'registry_invalid_validity_window'],
            'collected before issued' => [['issuedAt' => '2026-11-09T00:06:00Z'], 'registry_invalid_validity_window'],
            'issued after expiry' => [['issuedAt' => '2026-11-09T02:00:00Z', 'collectedAt' => '2026-11-09T02:05:00Z'], 'registry_invalid_validity_window'],
        ];
    }

    #[DataProvider('invalidTimes')]
    public function test_invalid_times_quarantine_the_whole_matching_set(array $changes, string $reason): void
    {
        $corridor = $this->corridor();
        $dates = array_map(fn (string $value): DateTimeImmutable => $this->time($value), $changes);
        $records = $this->replace($this->complete($corridor), $this->record($corridor, EvidenceKind::AccountEntitlement, $dates));
        $evaluation = $this->evaluate($corridor, $records);
        $this->assertRejected($evaluation, $reason);
        $this->assertCount(6, $evaluation->lookup->rejectedIds);
    }

    public static function invalidRevocation(): array
    {
        return [[RevocationStatus::Revoked, 'registry_evidence_revoked'], [RevocationStatus::Unknown, 'registry_revocation_unknown']];
    }

    #[DataProvider('invalidRevocation')]
    public function test_revoked_or_unknown_revocation_never_falls_back_to_positive_evidence(RevocationStatus $status, string $reason): void
    {
        $corridor = $this->corridor();
        $records = $this->replace($this->complete($corridor), $this->record($corridor, EvidenceKind::AccountEntitlement, ['revocation' => $status]));
        $this->assertRejected($this->evaluate($corridor, $records), $reason);
    }

    public function test_duplicate_identifiers_are_rejected_across_different_categories(): void
    {
        $corridor = $this->corridor();
        $duplicate = $this->record($corridor, EvidenceKind::AccountEntitlement, ['id' => 'synthetic-a-provider_capability']);
        $evaluation = $this->evaluate($corridor, $this->replace($this->complete($corridor), $duplicate));
        $this->assertRejected($evaluation, 'registry_duplicate_evidence_id');
        $this->assertSame(['synthetic-a-provider_capability'], $evaluation->lookup->duplicateIds);
    }

    public function test_duplicate_identifiers_in_different_scopes_invalidate_the_snapshot(): void
    {
        $corridor = $this->corridor();
        $duplicate = $this->record($this->corridor(['environment' => 'production']), EvidenceKind::AccountEntitlement);
        $this->assertRejected($this->evaluate($corridor, [...$this->complete($corridor), $duplicate]), 'registry_duplicate_evidence_id');
    }

    public function test_repeated_identical_record_is_rejected_instead_of_deduplicated(): void
    {
        $corridor = $this->corridor();
        $records = $this->complete($corridor);
        $evaluation = $this->evaluate($corridor, [...$records, $records[0]]);
        $this->assertRejected($evaluation, 'registry_duplicate_evidence_id');
        $this->assertContains('registry_conflicting_assertions', $evaluation->lookup->reasons);
    }

    public static function competingAssertions(): array
    {
        $cases = [];
        foreach (EvidenceKind::required() as $kind) {
            $cases[$kind->value.' contradictory'] = [$kind, EvidenceState::Denied];
            $cases[$kind->value.' ambiguous positive'] = [$kind, EvidenceState::Verified];
        }

        return $cases;
    }

    #[DataProvider('competingAssertions')]
    public function test_competing_assertions_never_select_a_newest_or_positive_winner(EvidenceKind $kind, EvidenceState $state): void
    {
        $corridor = $this->corridor();
        $extra = $this->record($corridor, $kind, [
            'id' => 'synthetic-competing-'.$kind->value, 'state' => $state,
            'issuedAt' => $this->time('2026-11-09T00:15:00Z'),
            'collectedAt' => $this->time('2026-11-09T00:20:00Z'),
        ]);
        $evaluation = $this->evaluate($corridor, [...$this->complete($corridor), $extra]);
        $this->assertRejected($evaluation, 'registry_conflicting_assertions');
        $this->assertSame([$kind->value], $evaluation->lookup->conflictingKinds);
    }

    public static function requiredKinds(): array
    {
        return array_map(static fn (EvidenceKind $kind): array => [$kind], EvidenceKind::required());
    }

    #[DataProvider('requiredKinds')]
    public function test_each_missing_category_fails_closed(EvidenceKind $kind): void
    {
        $corridor = $this->corridor();
        $evaluation = $this->evaluate($corridor, $this->without($this->complete($corridor), $kind));
        $this->assertRejected($evaluation, 'registry_evidence_incomplete');
        $this->assertSame([$kind->value], $evaluation->lookup->missingKinds);
    }

    public function test_capability_does_not_create_entitlement_and_entitlement_does_not_create_activation(): void
    {
        $corridor = $this->corridor();
        $capabilityOnly = $this->evaluate($corridor, [$this->record($corridor, EvidenceKind::ProviderCapability)]);
        $this->assertRejected($capabilityOnly, 'registry_evidence_incomplete');
        $this->assertContains('account_entitlement', $capabilityOnly->lookup->missingKinds);
        $withoutActivation = $this->evaluate($corridor, $this->without($this->complete($corridor), EvidenceKind::LocalActivation));
        $this->assertRejected($withoutActivation, 'registry_evidence_incomplete');
        $this->assertSame(['local_activation'], $withoutActivation->lookup->missingKinds);
    }

    public static function localDecisionKinds(): array
    {
        return [[EvidenceKind::LocalActivation], [EvidenceKind::OverrideClearance]];
    }

    #[DataProvider('localDecisionKinds')]
    public function test_provider_responses_cannot_establish_local_decisions(EvidenceKind $kind): void
    {
        $corridor = $this->corridor();
        $records = $this->replace($this->complete($corridor), $this->record($corridor, $kind, ['source' => EvidenceSource::ProviderResponse]));
        $this->assertRejected($this->evaluate($corridor, $records), 'registry_local_attestation_required');
    }

    public function test_valid_hold_and_deny_overrides_are_preserved_for_phase_one(): void
    {
        $corridor = $this->corridor();
        $records = [...$this->complete($corridor),
            $this->record($corridor, EvidenceKind::Override, ['id' => 'synthetic-hold', 'state' => EvidenceState::Hold]),
            $this->record($corridor, EvidenceKind::Override, ['id' => 'synthetic-deny', 'state' => EvidenceState::Denied])];
        $evaluation = $this->evaluate($corridor, $records);
        $this->assertTrue($evaluation->lookup->accepted, 'Blocking assertions are valid evidence, not permission.');
        $this->assertSame('DENIED', $evaluation->decision->preparation);
        $this->assertContains('hold_override', $evaluation->decision->preparationReasons);
        $this->assertContains('deny_override', $evaluation->decision->preparationReasons);
        $this->assertSame('DENIED', $evaluation->decision->dispatch);
    }

    public function test_an_invalid_hold_cannot_be_filtered_out_to_open_a_corridor(): void
    {
        $corridor = $this->corridor();
        foreach ([['expiresAt' => $this->time('2026-11-09T00:20:00Z')], ['revocation' => RevocationStatus::Revoked]] as $changes) {
            $hold = $this->record($corridor, EvidenceKind::Override, array_replace(['id' => 'synthetic-hold', 'state' => EvidenceState::Hold], $changes));
            $evaluation = $this->evaluate($corridor, [...$this->complete($corridor), $hold]);
            $this->assertFalse($evaluation->lookup->accepted);
            $this->assertSame([], $evaluation->lookup->records);
            $this->assertSame('DENIED', $evaluation->decision->preparation);
            $this->assertSame('DENIED', $evaluation->decision->dispatch);
        }
    }

    public function test_negative_and_unknown_assertions_remain_negative_in_phase_one(): void
    {
        $corridor = $this->corridor();
        foreach ([EvidenceState::Denied, EvidenceState::Unknown, EvidenceState::Hold] as $state) {
            $records = $this->replace($this->complete($corridor), $this->record($corridor, EvidenceKind::AccountEntitlement, ['state' => $state]));
            $evaluation = $this->evaluate($corridor, $records);
            $this->assertTrue($evaluation->lookup->accepted);
            $this->assertSame('DENIED', $evaluation->decision->preparation);
            $this->assertSame('DENIED', $evaluation->decision->dispatch);
        }
    }

    public function test_record_order_and_rejection_diagnostics_are_stable(): void
    {
        $corridor = $this->corridor();
        $records = $this->complete($corridor);
        $forward = $this->evaluate($corridor, $records);
        $reverse = $this->evaluate($corridor, array_reverse($records));
        $this->assertEquals($forward, $reverse);
        $ids = array_map(static fn (EvidenceRecord $record): string => $record->id, $forward->lookup->records);
        $sorted = $ids;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $ids);

        $invalid = [...$records, $records[0], $this->record($corridor, EvidenceKind::AccountEntitlement, [
            'id' => 'synthetic-z-revoked', 'revocation' => RevocationStatus::Revoked,
            'expiresAt' => $this->time('2026-11-09T00:20:00Z'),
        ])];
        $a = $this->evaluate($corridor, $invalid);
        $b = $this->evaluate($corridor, array_reverse($invalid));
        $this->assertEquals($a, $b);
        $this->assertSame(['registry_conflicting_assertions', 'registry_duplicate_evidence_id',
            'registry_evidence_expired', 'registry_evidence_revoked'], $a->lookup->reasons);
        $this->assertSame(['account_entitlement', 'provider_capability'], $a->lookup->conflictingKinds);
    }

    public function test_validity_boundaries_and_timezones_use_only_the_supplied_instant(): void
    {
        $corridor = $this->corridor();
        $snapshot = new EvidenceSnapshot(...$this->complete($corridor));
        $this->assertTrue($snapshot->lookup($corridor, $this->time('2026-11-09T00:10:00Z'))->accepted);
        $this->assertTrue($snapshot->lookup($corridor, $this->time('2026-11-09T07:30:00+07:00'))->accepted);
        $this->assertFalse($snapshot->lookup($corridor, $this->time('2026-11-09T01:00:00Z'))->accepted);
        $this->assertTrue($snapshot->lookup($corridor, $this->time())->accepted, 'Lookup must not mutate or cache an expiry decision.');
    }

    public function test_claimed_non_synthetic_evidence_still_cannot_dispatch_in_production(): void
    {
        // All data remain synthetic fixtures; these source labels test asserted provenance.
        $corridor = $this->corridor(['environment' => 'production']);
        $records = array_map(fn (EvidenceKind $kind): EvidenceRecord => $this->record($corridor, $kind, [
            'source' => in_array($kind, [EvidenceKind::ProviderCapability, EvidenceKind::AccountEntitlement], true)
                ? EvidenceSource::ProviderResponse : EvidenceSource::LocalAttestation,
        ]), EvidenceKind::required());
        $evaluation = $this->evaluate($corridor, $records);
        $this->assertTrue($evaluation->lookup->accepted);
        $this->assertSame('ALLOWED', $evaluation->decision->preparation);
        $this->assertSame('DENIED', $evaluation->decision->dispatch);
        $this->assertSame(['phase_one_dispatch_disabled'], $evaluation->decision->dispatchReasons);
    }

    public function test_synthetic_production_evidence_including_sgd_local_cannot_dispatch(): void
    {
        $corridor = $this->corridor(['environment' => 'production']);
        $evaluation = $this->evaluate($corridor, $this->complete($corridor));
        $this->assertSame('ALLOWED', $evaluation->decision->preparation);
        $this->assertSame('DENIED', $evaluation->decision->dispatch);
        $this->assertContains('synthetic_evidence', $evaluation->decision->dispatchReasons);

        $sgd = $this->corridor(['environment' => 'production', 'destinationCountry' => 'SG', 'destinationCurrency' => 'SGD']);
        $held = $this->evaluate($sgd, $this->complete($sgd));
        $this->assertTrue($held->lookup->accepted);
        $this->assertSame('ALLOWED', $held->decision->preparation);
        $this->assertContains('synthetic_evidence', $held->decision->dispatchReasons);
        $this->assertSame('DENIED', $held->decision->dispatch);
    }

    public function test_adapter_preserves_mixed_origin_denial_instead_of_relabelling_evidence(): void
    {
        $corridor = $this->corridor();
        $records = $this->replace($this->complete($corridor), $this->record($corridor, EvidenceKind::ProviderCapability, [
            'source' => EvidenceSource::ProviderResponse,
        ]));
        $evaluation = $this->evaluate($corridor, $records);
        $this->assertTrue($evaluation->lookup->accepted);
        $this->assertSame('DENIED', $evaluation->decision->preparation);
        $this->assertContains('evidence_origin_mixed', $evaluation->decision->preparationReasons);
        $this->assertSame('DENIED', $evaluation->decision->dispatch);
        $this->assertContains('synthetic_evidence', $evaluation->decision->dispatchReasons);
    }

    public static function missingMetadata(): array
    {
        return [[['id' => '']], [['id' => ' ']], [['id' => "synthetic\nid"]], [['provenance' => '']], [['provenance' => ' ']]];
    }

    #[DataProvider('missingMetadata')]
    public function test_identifiers_and_provenance_require_explicit_metadata(array $changes): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('registry_metadata_invalid');
        $this->record($this->corridor(), EvidenceKind::ProviderCapability, $changes);
    }

    public function test_evidence_record_cannot_be_mutated(): void
    {
        $record = $this->record($this->corridor(), EvidenceKind::ProviderCapability);
        $this->expectException(Error::class);
        $record->revocation = RevocationStatus::NotRevoked;
    }

    public function test_lookup_collection_cannot_be_mutated(): void
    {
        $corridor = $this->corridor();
        $lookup = (new EvidenceSnapshot(...$this->complete($corridor)))->lookup($corridor, $this->time());
        $this->expectException(Error::class);
        $lookup->records[] = $this->record($corridor, EvidenceKind::Override);
    }

    public function test_lookup_and_adapter_are_repeatable_without_input_or_runtime_side_effects(): void
    {
        $corridor = $this->corridor();
        $records = $this->complete($corridor);
        $snapshot = new EvidenceSnapshot(...$records);
        $adapter = new RegistryEligibilityAdapter;
        $now = $this->time();
        $expected = $adapter->evaluate($snapshot, $corridor, $now);
        $before = serialize([$records, $snapshot, $corridor, $now, $adapter]);
        $includedBefore = get_included_files();
        $actual = $adapter->evaluate($snapshot, $corridor, $now);
        $includedAfter = get_included_files();

        $this->assertEquals($expected, $actual);
        $this->assertSame($before, serialize([$records, $snapshot, $corridor, $now, $adapter]));
        $this->assertSame($includedBefore, $includedAfter);
        $this->assertSame([], $this->runtimeLoadAttempts);
    }
}
