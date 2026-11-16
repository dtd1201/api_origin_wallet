<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nium\DynamicConfirmation\DynamicAccountEligibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NiumDynamicAccountEligibilityTest extends TestCase
{
    public static function permittedObservations(): array
    {
        return [
            'account 6/8-like' => [null, 'unknown'],
            'both absent' => [null, null],
            'both unknown' => ['unknown', 'unknown'],
            'unknown compliance absent ODD' => ['unknown', null],
            'absent compliance clear ODD' => [null, 'clear'],
            'unknown compliance clear ODD' => ['unknown', 'clear'],
            'clear compliance absent ODD' => ['clear', null],
            'clear compliance unknown ODD' => ['clear', 'unknown'],
            'existing clearance' => ['clear', 'clear'],
            'existing approval and ODD exemption' => ['approved', 'not_required'],
        ];
    }

    #[DataProvider('permittedObservations')]
    public function test_missing_observations_are_permitted_without_promoting_them_to_clearance(
        ?string $compliance, ?string $odd): void
    {
        $this->assertTrue(DynamicAccountEligibility::permitsAuxiliaryObservations($compliance, $odd));
    }

    public static function restrictedOrUnsupportedObservations(): array
    {
        return array_map(static fn (string $value): array => [$value], [
            'pending', 'action_required', 'failed', 'rejected', 'suspended', 'blocked',
            'hold', 'on_hold', 'odd_due', 'odd_ineligible', 'odd_not_due', 'completed',
            '', 'unsupported-provider-state',
        ]);
    }

    #[DataProvider('restrictedOrUnsupportedObservations')]
    public function test_missing_observation_cannot_override_a_restriction_in_the_other_field(string $restriction): void
    {
        $this->assertFalse(DynamicAccountEligibility::permitsAuxiliaryObservations($restriction, null));
        $this->assertFalse(DynamicAccountEligibility::permitsAuxiliaryObservations('unknown', $restriction));
    }
}
