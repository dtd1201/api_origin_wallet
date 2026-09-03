<?php

namespace App\Policies;

use App\Models\AmlScreening;
use App\Models\User;
use App\Policies\Concerns\ChecksAdminPermissions;

class AmlScreeningPolicy
{
    use ChecksAdminPermissions;
    public function viewAny(User $user): bool { return $this->permitted($user, 'aml.view'); }
    public function view(User $user, AmlScreening $screening): bool { return $this->viewAny($user); }
    public function review(User $user, AmlScreening $screening): bool { return $this->permitted($user, 'aml.review'); }
}
