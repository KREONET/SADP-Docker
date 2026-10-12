<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Rules\NotRecentlyUsedPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    /**
     * Validate and update the user's password.
     *
     * @param  array<string, string>  $input
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ], [
            'current_password.current_password' => __('The provided password does not match your current password.'),
        ])->validateWithBag('updatePassword');

        // Checked only after the current password, so the reuse message cannot be
        // used to test guesses of the current password.
        Validator::make($input, [
            'password' => [new NotRecentlyUsedPassword($user)],
        ])->validateWithBag('updatePassword');

        $user->forceFill([
            'password' => Hash::make($input['password']),
            'local_password_set_at' => now(),
        ])->save();
        auditLog('ui.user.password_changed', [
            'team_id' => $user->currentTeam()?->id,
            'resource' => 'user',
            'user_name' => $user->name,
        ]);
    }
}
