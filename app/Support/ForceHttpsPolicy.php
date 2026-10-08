<?php

namespace App\Support;

use App\Models\User;

/**
 * Who may change the per-resource "Redirect HTTP to HTTPS" (force HTTPS) setting.
 *
 * Anyone who can update the resource may turn the redirect on. Turning it off sends traffic over
 * plain HTTP, so only a platform administrator (admin or owner of the root team) may do that.
 */
class ForceHttpsPolicy
{
    public static function canDisable(?User $user): bool
    {
        return $user?->isInstanceAdmin() === true;
    }

    /**
     * Whether the user may change the setting from $current to $requested.
     */
    public static function allowsChange(bool $current, bool $requested, ?User $user): bool
    {
        if ($requested || ! $current) {
            return true;
        }

        return self::canDisable($user);
    }
}
