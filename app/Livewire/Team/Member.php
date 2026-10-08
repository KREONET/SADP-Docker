<?php

namespace App\Livewire\Team;

use App\Actions\User\RevokeUserTeamTokens;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Member extends Component
{
    use AuthorizesRequests;

    public User $member;

    public function makeAdmin()
    {
        try {
            $this->authorize('manageMembers', currentTeam());

            if (Role::from(auth()->user()->role())->lt(Role::ADMIN)
                || Role::from($this->getMemberRole())->gt(auth()->user()->role())) {
                throw new \Exception(__('You are not authorized to perform this action.'));
            }
            $teamId = currentTeam()->id;
            $previousRole = $this->getMemberRole();
            DB::transaction(function () use ($teamId): void {
                $this->member->teams()->updateExistingPivot($teamId, ['role' => Role::ADMIN->value]);
                RevokeUserTeamTokens::forUserTeam($this->member, $teamId);
            });
            $this->auditRoleUpdate($teamId, Role::ADMIN, $previousRole);
            $this->dispatch('reloadWindow');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function makeOwner()
    {
        try {
            $this->authorize('manageMembers', currentTeam());

            if (Role::from(auth()->user()->role())->lt(Role::OWNER)
                || Role::from($this->getMemberRole())->gt(auth()->user()->role())) {
                throw new \Exception(__('You are not authorized to perform this action.'));
            }
            $teamId = currentTeam()->id;
            $previousRole = $this->getMemberRole();
            DB::transaction(function () use ($teamId): void {
                $this->member->teams()->updateExistingPivot($teamId, ['role' => Role::OWNER->value]);
                RevokeUserTeamTokens::forUserTeam($this->member, $teamId);
            });
            $this->auditRoleUpdate($teamId, Role::OWNER, $previousRole);
            $this->dispatch('reloadWindow');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function makeReadonly()
    {
        try {
            $this->authorize('manageMembers', currentTeam());

            if (Role::from(auth()->user()->role())->lt(Role::ADMIN)
                || Role::from($this->getMemberRole())->gt(auth()->user()->role())) {
                throw new \Exception(__('You are not authorized to perform this action.'));
            }
            $teamId = currentTeam()->id;
            $previousRole = $this->getMemberRole();
            DB::transaction(function () use ($teamId): void {
                $this->member->teams()->updateExistingPivot($teamId, ['role' => Role::MEMBER->value]);
                RevokeUserTeamTokens::forUserTeam($this->member, $teamId);
            });
            $this->auditRoleUpdate($teamId, Role::MEMBER, $previousRole);
            $this->dispatch('reloadWindow');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function remove()
    {
        try {
            $this->authorize('manageMembers', currentTeam());

            if (Role::from(auth()->user()->role())->lt(Role::ADMIN)
                || Role::from($this->getMemberRole())->gt(auth()->user()->role())) {
                throw new \Exception(__('You are not authorized to perform this action.'));
            }
            $teamId = currentTeam()->id;
            $previousRole = $this->getMemberRole();
            DB::transaction(function () use ($teamId): void {
                $this->member->teams()->detach($teamId);
                RevokeUserTeamTokens::forUserTeam($this->member, $teamId);
                $this->member->clearStoredTeamIfMatches($teamId);
            });
            auditLog('ui.team_member.removed', [
                'team_id' => $teamId,
                'member_id' => $this->member->id,
                'member_name' => $this->member->name,
                'member_email' => $this->member->email,
                'previous_role' => $previousRole,
            ]);
            $this->auditPlatformAdminChange($teamId, $previousRole, null);
            // Clear cache for the removed user - both old and new key formats
            Cache::forget("team:{$this->member->id}");
            Cache::forget("user:{$this->member->id}:team:{$teamId}");
            $this->dispatch('reloadWindow');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    private function getMemberRole()
    {
        return $this->member->teams()->where('teams.id', currentTeam()->id)->first()?->pivot?->role;
    }

    private function auditRoleUpdate(int $teamId, Role $role, ?string $previousRole): void
    {
        auditLog('ui.team_member.role_updated', [
            'team_id' => $teamId,
            'member_id' => $this->member->id,
            'member_name' => $this->member->name,
            'member_email' => $this->member->email,
            'previous_role' => $previousRole,
            'role' => $role->value,
        ]);

        $this->auditPlatformAdminChange($teamId, $previousRole, $role->value);
    }

    /**
     * Admins and owners of the root team are platform administrators. Record and
     * alert when a role change or removal grants or revokes that access.
     */
    private function auditPlatformAdminChange(int $teamId, ?string $previousRole, ?string $newRole): void
    {
        if ($teamId !== 0) {
            return;
        }

        $wasPlatformAdmin = $this->isPlatformAdminRole($previousRole);
        $isPlatformAdmin = $this->isPlatformAdminRole($newRole);

        if ($wasPlatformAdmin === $isPlatformAdmin) {
            return;
        }

        $context = [
            'team_id' => $teamId,
            'resource' => 'user',
            'member_id' => $this->member->id,
            'member_name' => $this->member->name,
            'member_email' => $this->member->email,
            'previous_role' => $previousRole,
            'role' => $newRole,
            'result' => 'success',
        ];

        auditLog($isPlatformAdmin ? 'platform.admin.granted' : 'platform.admin.revoked', $context, 'warning');

        notifySecurityAlert(
            'platform_admin_changed',
            $isPlatformAdmin ? 'Platform administrator access granted' : 'Platform administrator access revoked',
            [
                'action' => $isPlatformAdmin ? 'granted' : 'revoked',
                'member_name' => $this->member->name,
                'member_email' => $this->member->email,
                'previous_role' => $previousRole ?? 'none',
                'role' => $newRole ?? 'removed',
                'changed_by_email' => auth()->user()?->email,
            ],
        );
    }

    private function isPlatformAdminRole(?string $role): bool
    {
        return in_array($role, [Role::ADMIN->value, Role::OWNER->value], true);
    }
}
