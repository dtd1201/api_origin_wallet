<?php

namespace App\Policies;

use App\Models\Balance;
use App\Models\User;
use App\Policies\Concerns\ChecksAdminPermissions;

class BalancePolicy
{
    use ChecksAdminPermissions;
    public function viewAny(User $user): bool { return $this->permitted($user, 'wallet.view'); }
    public function view(User $user, Balance $balance): bool { return $this->viewAny($user); }
    public function sync(User $user, Balance $balance): bool { return $this->permitted($user, 'wallet.sync'); }
}
