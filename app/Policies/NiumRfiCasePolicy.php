<?php

namespace App\Policies;

use App\Models\NiumRfiCase;
use App\Models\User;
use App\Policies\Concerns\ChecksAdminPermissions;

class NiumRfiCasePolicy
{
    use ChecksAdminPermissions;
    public function viewAny(User $user): bool { return $this->permitted($user, 'rfi.view'); }
    public function view(User $user, NiumRfiCase $case): bool { return $this->viewAny($user); }
    public function manage(User $user, NiumRfiCase $case): bool { return $this->permitted($user, 'rfi.manage'); }
}
