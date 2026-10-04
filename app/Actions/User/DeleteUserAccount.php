<?php

namespace App\Actions\User;

use App\Jobs\DeleteResourceJob;
use App\Models\Team;
use App\Models\User;
use App\Services\AvatarStorageService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Permanently deletes a user account and everything that belongs only to it.
 *
 * Every deletion path (self withdrawal, SCIM deprovisioning, platform admin, `admin:delete-user`) goes through here:
 * applications, databases, and services of the teams the user is alone in are deleted like a
 * normal resource deletion (containers stopped, volumes and networks removed), then the avatar,
 * sessions, API tokens, and the account itself. The User `deleting` hook deletes those teams with
 * their servers and projects, and moves ownership of shared teams to another member.
 */
class DeleteUserAccount
{
    /**
     * @param  'self'|'scim'|'admin'|'cli'  $via
     */
    public static function execute(User $user, string $via): void
    {
        if ($user->id === 0) {
            throw new RuntimeException('The root user account cannot be deleted.');
        }

        $context = [
            'deleted_user_id' => $user->id,
            'deleted_user_email' => $user->email,
            'via' => $via,
            'resource' => 'user',
            'deleted_resources' => static::deleteResourcesOfOwnTeams($user),
        ];

        static::deleteAvatar($user);

        DB::transaction(function () use ($user) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
            RevokeUserTeamTokens::forUser($user);
            $user->delete();
        });

        auditLog('user.account.deleted', $context, 'warning');
    }

    /**
     * Teams that are deleted together with the account because the user is their only member.
     *
     * @return Collection<int, Team>
     */
    public static function teamsDeletedWithAccount(User $user): Collection
    {
        return $user->teams()->withCount('members')->get()
            ->filter(fn (Team $team) => $team->id !== 0 && $team->members_count === 1)
            ->values();
    }

    /**
     * Runs the regular resource deletion for every resource on the servers of those teams, so no
     * container keeps running after its owner is gone. A resource whose remote cleanup cannot
     * finish is still removed from the database by the User `deleting` hook.
     */
    private static function deleteResourcesOfOwnTeams(User $user): int
    {
        $deleted = 0;

        foreach (static::teamsDeletedWithAccount($user) as $team) {
            foreach ($team->servers as $server) {
                foreach ($server->definedResources() as $resource) {
                    try {
                        DeleteResourceJob::dispatchSync($resource);
                        $deleted++;
                    } catch (Throwable $e) {
                        Log::warning('Resource cleanup failed while deleting a user account; the record is removed with the team.', [
                            'user_id' => $user->id,
                            'resource_id' => $resource->id,
                            'resource_type' => $resource->getMorphClass(),
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        return $deleted;
    }

    private static function deleteAvatar(User $user): void
    {
        try {
            app(AvatarStorageService::class)->delete($user);
        } catch (Throwable $e) {
            Log::warning('Avatar cleanup failed while deleting a user account.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
