<?php

namespace App\Console\Commands;

use App\Actions\User\DeactivateUserAccount;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Deactivates accounts that have not signed in for `security.accounts.deactivate_inactive_after_days`.
 *
 * Accounts without a recorded sign-in are measured from `updated_at` (password logins have always
 * touched it, so accounts that existed before `last_login_at` was tracked are not deactivated on
 * the first run), falling back to their creation date. The root user is never deactivated, so the
 * instance always keeps a break-glass account.
 */
class DeactivateInactiveUsers extends Command
{
    protected $signature = 'users:deactivate-inactive {--dry-run : List the accounts without deactivating them}';

    protected $description = 'Deactivate user accounts that have not signed in within the configured number of days';

    public function handle(): int
    {
        $days = (int) config('security.accounts.deactivate_inactive_after_days');
        if ($days <= 0) {
            $this->info('Inactive account deactivation is disabled.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');
        $deactivated = 0;
        $failed = 0;

        static::inactiveUsersQuery($days)->chunkById(100, function ($users) use ($days, $cutoff, $dryRun, &$deactivated, &$failed): void {
            foreach ($users as $user) {
                $lastActivity = static::lastActivityAt($user);

                if ($dryRun) {
                    $this->line("{$user->email} (last activity: {$lastActivity?->toDateString()})");
                    $deactivated++;

                    continue;
                }

                try {
                    DeactivateUserAccount::execute($user, 'inactivity');
                    auditLog('user.account.deactivated_inactive', [
                        'resource' => 'user',
                        'target_user_id' => $user->id,
                        'target_user_email' => $user->email,
                        'last_activity_at' => $lastActivity?->toIso8601String(),
                        'inactive_after_days' => $days,
                        'cutoff' => $cutoff->toIso8601String(),
                        'result' => 'success',
                    ], 'warning');
                    $deactivated++;
                } catch (Throwable $e) {
                    auditLog('user.account.deactivated_inactive', [
                        'resource' => 'user',
                        'target_user_id' => $user->id,
                        'target_user_email' => $user->email,
                        'result' => 'failure',
                        'error' => $e->getMessage(),
                    ], 'error');
                    $failed++;
                }
            }
        });

        $this->info($dryRun
            ? "{$deactivated} inactive account(s) would be deactivated."
            : "{$deactivated} inactive account(s) deactivated.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Active, non-root users whose last activity is older than the cutoff.
     *
     * @return Builder<User>
     */
    public static function inactiveUsersQuery(int $days): Builder
    {
        $cutoff = now()->subDays($days);

        return User::query()
            ->where('id', '!=', 0)
            ->whereNull('deactivated_at')
            ->where(function (Builder $query) use ($cutoff): void {
                $query->where('last_login_at', '<', $cutoff)
                    ->orWhere(function (Builder $query) use ($cutoff): void {
                        $query->whereNull('last_login_at')->where('updated_at', '<', $cutoff);
                    })
                    ->orWhere(function (Builder $query) use ($cutoff): void {
                        $query->whereNull('last_login_at')->whereNull('updated_at')->where('created_at', '<', $cutoff);
                    });
            });
    }

    public static function lastActivityAt(User $user): ?CarbonInterface
    {
        return $user->last_login_at ?? $user->updated_at ?? $user->created_at;
    }
}
