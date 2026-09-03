<?php

namespace App\Policies;

use App\Models\IntegrationProvider;
use App\Models\User;
use App\Policies\Concerns\ChecksAdminPermissions;

class IntegrationProviderPolicy
{
    use ChecksAdminPermissions;
    public function viewAny(User $user): bool { return $this->permitted($user, 'providers.view'); }
    public function view(User $user, IntegrationProvider $provider): bool { return $this->viewAny($user); }
    public function create(User $user): bool { return $this->permitted($user, 'providers.manage'); }
    public function update(User $user, IntegrationProvider $provider): bool { return $this->create($user); }
    public function delete(User $user, IntegrationProvider $provider): bool { return $this->create($user); }
    public function sync(User $user, IntegrationProvider $provider): bool { return $this->permitted($user, 'providers.sync'); }
}
