<?php

namespace App\Policies;

use App\Models\BankAccount;
use App\Models\User;
use App\Policies\Concerns\ChecksAdminPermissions;

class BankAccountPolicy
{
    use ChecksAdminPermissions;
    public function viewAny(User $user): bool { return $this->permitted($user, 'bank_accounts.view'); }
    public function view(User $user, BankAccount $account): bool { return $this->viewAny($user); }
    public function create(User $user): bool { return $this->permitted($user, 'bank_accounts.manage'); }
    public function update(User $user, BankAccount $account): bool { return $this->create($user); }
    public function delete(User $user, BankAccount $account): bool { return $this->create($user); }
}
