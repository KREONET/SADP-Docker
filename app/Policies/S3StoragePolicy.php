<?php

namespace App\Policies;

use App\Models\S3Storage;
use App\Models\User;

class S3StoragePolicy
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
    public function view(User $user, S3Storage $storage): bool
    {
        return $this->canManage($user, $storage);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Every user may add their own storage; it stays private to its creator.
        return $user->currentTeam() !== null;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, S3Storage $storage): bool
    {
        return $this->canManage($user, $storage);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, S3Storage $storage): bool
    {
        return $this->canManage($user, $storage);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, S3Storage $storage): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, S3Storage $storage): bool
    {
        return false;
    }

    /**
     * Determine whether the user can validate the connection of the model.
     */
    public function validateConnection(User $user, S3Storage $storage): bool
    {
        return $this->canManage($user, $storage);
    }

    /**
     * Platform administrators manage every storage of their teams; other users
     * only the storages they created.
     */
    private function canManage(User $user, S3Storage $storage): bool
    {
        if (! $user->teams->contains('id', $storage->team_id)) {
            return false;
        }

        return $user->isInstanceAdmin() || $storage->isCreatedBy($user);
    }
}
