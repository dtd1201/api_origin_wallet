<?php

namespace App\Policies;

use App\Models\Transfer;
use App\Models\User;
use App\Policies\Concerns\ChecksAdminPermissions;

class TransferPolicy
{
    use ChecksAdminPermissions;

    public function viewAny(User $user): bool { return $this->permitted($user, 'transfers.view'); }
    public function view(User $user, Transfer $transfer): bool { return $this->viewAny($user); }
    public function create(User $user): bool { return $this->permitted($user, 'transfers.manage'); }
    public function update(User $user, Transfer $transfer): bool { return $this->create($user); }
    public function delete(User $user, Transfer $transfer): bool { return $this->create($user); }
    public function approve(User $user, Transfer $transfer): bool { return $this->permitted($user, 'transfers.approve'); }
    public function reject(User $user, Transfer $transfer): bool { return $this->permitted($user, 'transfers.reject'); }
}
