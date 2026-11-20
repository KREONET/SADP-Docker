<?php

namespace App\Policies;

use App\Models\ForgejoApp;
use App\Models\User;

/**
 * Forgejo sources are instance-wide: every team can use them, only platform admins
 * (admins and owners of the root team) register, change or remove them.
 */
class ForgejoAppPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ForgejoApp $forgejoApp): bool
    {
        return $forgejoApp->is_system_wide || $user->teams->contains('id', $forgejoApp->team_id);
    }

    public function create(User $user): bool
    {
        return $user->isInstanceAdmin();
    }

    public function update(User $user, ForgejoApp $forgejoApp): bool
    {
        return $user->isInstanceAdmin();
    }

    public function delete(User $user, ForgejoApp $forgejoApp): bool
    {
        return $user->isInstanceAdmin();
    }

    public function restore(User $user, ForgejoApp $forgejoApp): bool
    {
        return false;
    }

    public function forceDelete(User $user, ForgejoApp $forgejoApp): bool
    {
        return false;
    }
}
