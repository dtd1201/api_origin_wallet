<?php

namespace App\Policies;

use App\Models\KycProfile;
use App\Models\User;
use App\Policies\Concerns\ChecksAdminPermissions;

class KycProfilePolicy
{
    use ChecksAdminPermissions;
    public function viewAny(User $user): bool { return $this->permitted($user, 'kyc.view'); }
    public function view(User $user, KycProfile $profile): bool { return $this->viewAny($user); }
    public function approve(User $user, KycProfile $profile): bool { return $this->permitted($user, 'kyc.approve'); }
    public function reject(User $user, KycProfile $profile): bool { return $this->permitted($user, 'kyc.reject'); }
}
