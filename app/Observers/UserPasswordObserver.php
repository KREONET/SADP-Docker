<?php

namespace App\Observers;

use App\Models\PasswordHistory;
use App\Models\User;

/**
 * Tracks when a user's password changes and keeps the previous hashes for the
 * password-reuse check.
 */
class UserPasswordObserver
{
    /**
     * Stamp password_changed_at whenever a password is set, unless the caller set it explicitly.
     */
    public function saving(User $user): void
    {
        if ($user->isDirty('password') && ! $user->isDirty('password_changed_at')) {
            $user->password_changed_at = now();
        }
    }

    /**
     * Remember the replaced hash so it cannot be reused.
     */
    public function updated(User $user): void
    {
        if (! $user->wasChanged('password')) {
            return;
        }

        $previousHash = $user->getOriginal('password');
        if (is_string($previousHash) && $previousHash !== '') {
            PasswordHistory::remember($user->id, $previousHash);
        }
    }
}
