<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksAdminPermissions;

class UserPolicy
{
    use ChecksAdminPermissions;

    public function viewAny(User $actor): bool { return $this->permitted($actor, 'users.view'); }
    public function view(User $actor, User $subject): bool { return $this->permitted($actor, 'users.view') && ! $subject->isAdmin(); }
    public function create(User $actor): bool { return $this->permitted($actor, 'users.manage'); }
    public function update(User $actor, User $subject): bool { return $this->permitted($actor, 'users.manage') && ! $subject->isAdmin(); }
    public function delete(User $actor, User $subject): bool { return $this->update($actor, $subject); }
}
