<?php

namespace App\Policies;

use App\Models\Beneficiary;
use App\Models\User;
use App\Policies\Concerns\ChecksAdminPermissions;

class BeneficiaryPolicy
{
    use ChecksAdminPermissions;
    public function viewAny(User $user): bool { return $this->permitted($user, 'beneficiaries.view'); }
    public function view(User $user, Beneficiary $beneficiary): bool { return $this->viewAny($user); }
    public function create(User $user): bool { return $this->permitted($user, 'beneficiaries.manage'); }
    public function update(User $user, Beneficiary $beneficiary): bool { return $this->create($user); }
    public function delete(User $user, Beneficiary $beneficiary): bool { return $this->create($user); }
}
