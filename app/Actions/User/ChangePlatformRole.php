<?php

namespace App\Actions\User;

use App\Enums\PlatformRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Set a user's platform role (administrator, sub-administrator or user).
 *
 * Administrators are the admins and owners of the root team, so granting it adds the user to the
 * root team as admin, and every other role demotes an admin or owner there to member. The member
 * keeps the root team membership, like a role change on the Groups page. Sub-administrators are
 * marked with `users.is_sub_admin`, which is cleared for the other two roles.
 *
 * The caller authorizes the actor and audits the change.
 */
class ChangePlatformRole
{
    /**
     * @return PlatformRole The role before the change.
     */
    public static function execute(User $user, PlatformRole $role): PlatformRole
    {
        if ($user->id === 0) {
            throw new RuntimeException(__('The root user account cannot be changed here.'));
        }

        $previousRole = DB::transaction(function () use ($user, $role): PlatformRole {
            $rootTeam = Team::query()->lockForUpdate()->findOrFail(0);
            $user->unsetRelation('teams');
            $previousRole = $user->platformRole();

            $membership = $rootTeam->members()->where('users.id', $user->id)->first();
            $rootTeamRole = $membership?->pivot?->role;
            $isRootTeamAdmin = in_array($rootTeamRole, ['admin', 'owner'], true);

            if ($role === PlatformRole::ADMIN) {
                if ($membership === null) {
                    $rootTeam->members()->attach($user->id, ['role' => 'admin']);
                } elseif (! $isRootTeamAdmin) {
                    $rootTeam->members()->updateExistingPivot($user->id, ['role' => 'admin']);
                }
            } elseif ($isRootTeamAdmin) {
                if ($rootTeamRole === 'owner' && $rootTeam->members()->wherePivot('role', 'owner')->count() <= 1) {
                    throw new RuntimeException(__('A team must keep at least one owner.'));
                }
                $rootTeam->members()->updateExistingPivot($user->id, ['role' => 'member']);
            }

            $user->forceFill(['is_sub_admin' => $role === PlatformRole::SUB_ADMIN])->save();

            return $previousRole;
        });

        Cache::forget("user:{$user->id}:team:0");
        $user->unsetRelation('teams');

        return $previousRole;
    }
}
