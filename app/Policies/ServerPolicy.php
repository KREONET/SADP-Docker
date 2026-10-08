<?php

namespace App\Policies;

use App\Models\Server;
use App\Models\User;

class ServerPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Server $server): bool
    {
        return $user->teams->contains('id', $server->team_id);
    }

    /**
     * Determine whether the user can open a terminal on the server. Platform
     * administrators reach every team's servers; team admins and owners reach
     * only the servers of their current team.
     */
    public function openTerminal(User $user, Server $server): bool
    {
        if ($user->isInstanceAdmin()) {
            return true;
        }

        return $user->currentTeam()?->id === $server->team_id
            && $user->isAdminOfTeam($server->team_id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Servers are shared infrastructure, so only platform admins change them.
        return $user->isInstanceAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Server $server): bool
    {
        return $this->isPlatformAdminOfTeam($user, $server);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Server $server): bool
    {
        return $this->isPlatformAdminOfTeam($user, $server);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Server $server): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Server $server): bool
    {
        return false;
    }

    /**
     * Determine whether the user can manage proxy (start/stop/restart).
     */
    public function manageProxy(User $user, Server $server): bool
    {
        return $this->isPlatformAdminOfTeam($user, $server);
    }

    /**
     * Determine whether the user can manage sentinel (start/stop).
     */
    public function manageSentinel(User $user, Server $server): bool
    {
        return $this->isPlatformAdminOfTeam($user, $server);
    }

    /**
     * Determine whether the user can view Sentinel configuration and logs.
     */
    public function viewSentinel(User $user, Server $server): bool
    {
        return $this->isPlatformAdminOfTeam($user, $server);
    }

    /**
     * Determine whether the user can manage CA certificates.
     */
    public function manageCaCertificate(User $user, Server $server): bool
    {
        return $this->isPlatformAdminOfTeam($user, $server);
    }

    /**
     * Determine whether the user can view security views.
     */
    public function viewSecurity(User $user, Server $server): bool
    {
        return $this->isPlatformAdminOfTeam($user, $server);
    }

    /**
     * Platform administrators manage the servers of the teams they belong to.
     */
    private function isPlatformAdminOfTeam(User $user, Server $server): bool
    {
        return $user->isInstanceAdmin() && $user->teams->contains('id', $server->team_id);
    }
}
