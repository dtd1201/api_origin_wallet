<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use App\Models\Balance;
use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\KycProfile;
use App\Models\User;
use App\Models\UserProviderAccount;

/** Transaction-owned records; an adapter must not mutate these objects. */
final readonly class LockedPayoutRecords
{
    public function __construct(
        public User $user,
        public IntegrationProvider $provider,
        public UserProviderAccount $account,
        public Beneficiary $beneficiary,
        public Balance $wallet,
        public KycProfile $kyc,
        public ?string $acceptedFingerprint = null,
        public ?\App\Models\NiumDynamicIntent $consumingIntent = null,
    ) {}
}
