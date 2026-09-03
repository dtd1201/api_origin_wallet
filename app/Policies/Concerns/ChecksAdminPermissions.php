<?php

namespace App\Policies\Concerns;

use App\Models\User;

trait ChecksAdminPermissions
{
    protected function permitted(User $user, string $permission): bool
    {
        return $user->isAdmin() && $user->hasPermission($permission);
    }
}
