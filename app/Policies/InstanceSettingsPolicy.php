<?php

namespace App\Policies;

use App\Models\InstanceSettings;
use App\Models\User;

class InstanceSettingsPolicy
{
    /**
     * Instance settings belong to platform admins (admins and owners of the root team),
     * the same rule as the accessPlatformAdmin gate. The checked user is the given one,
     * not whoever is signed in, so Gate::forUser() and API tokens are evaluated correctly.
     */
    public function view(User $user, InstanceSettings $settings): bool
    {
        return $user->isInstanceAdmin();
    }

    public function update(User $user, InstanceSettings $settings): bool
    {
        return $user->isInstanceAdmin();
    }
}
