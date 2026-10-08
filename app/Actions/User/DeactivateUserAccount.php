<?php

namespace App\Actions\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Deactivates or reactivates a user account without deleting anything.
 *
 * The identity provider deactivates a user with SCIM `active: false` for a suspension or an app
 * unassignment, which may be undone later, so the account, its teams, and their resources stay.
 * A deactivated account is signed out everywhere and cannot sign in until it is reactivated;
 * only an explicit delete goes through {@see DeleteUserAccount}.
 */
class DeactivateUserAccount
{
    /**
     * @param  'scim'|'admin'  $via
     */
    public static function execute(User $user, string $via): void
    {
        if ($user->id === 0) {
            throw new RuntimeException('The root user account cannot be deactivated.');
        }

        if ($user->isDeactivated()) {
            return;
        }

        DB::transaction(function () use ($user) {
            $user->forceFill([
                'deactivated_at' => now(),
                'remember_token' => Str::random(60),
            ])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            RevokeUserTeamTokens::forUser($user);
        });

        auditLog('user.account.deactivated', static::context($user, $via), 'warning');
    }

    /**
     * @param  'scim'|'admin'  $via
     */
    public static function reactivate(User $user, string $via): void
    {
        if (! $user->isDeactivated()) {
            return;
        }

        $user->forceFill(['deactivated_at' => null])->save();

        auditLog('user.account.reactivated', static::context($user, $via));
    }

    /**
     * @return array<string, mixed>
     */
    private static function context(User $user, string $via): array
    {
        return [
            'resource' => 'user',
            'target_user_id' => $user->id,
            'target_user_email' => $user->email,
            'via' => $via,
        ];
    }
}
