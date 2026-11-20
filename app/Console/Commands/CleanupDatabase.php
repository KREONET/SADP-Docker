<?php

namespace App\Console\Commands;

use App\Enums\GithubRunnerStatus;
use App\Models\AuditEvent;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CleanupDatabase extends Command
{
    protected $signature = 'cleanup:database {--yes} {--keep-days=}';

    protected $description = 'Cleanup database';

    private const DELETE_BATCH_SIZE = 1000;

    public function handle()
    {
        if ($this->option('yes')) {
            $this->line('Running database cleanup...');
        } else {
            $this->line('Running database cleanup in dry-run mode...');
        }
        if (isCloud()) {
            // Later on we can increase this to 180 days or dynamically set
            $keep_days = $this->option('keep-days') ?? 60;
        } else {
            $keep_days = $this->option('keep-days') ?? 60;
        }
        $this->line("Keep days: $keep_days");
        $this->cleanupTable('failed_jobs', 'failed_at', now()->subDays(1));

        $this->cleanupTable('sessions', 'last_activity', $this->sessionCutoff((int) $keep_days)->timestamp);

        $this->cleanupTable('activity_log', 'created_at', now()->subDays($keep_days));

        $count = AuditEvent::expiredCount();
        $this->line('Delete '.$count.' entries from audit_events (retention: '.AuditEvent::retentionDays().' days).');
        if ($this->option('yes')) {
            AuditEvent::pruneExpired();
        }

        $this->cleanupTable('application_deployment_queues', 'created_at', now()->subDays($keep_days));

        $this->cleanupTable('scheduled_task_executions', 'created_at', now()->subDays($keep_days));

        // Backup retention removes only successful runs. A failed run has no file, so only its record is left.
        $this->cleanupTable('scheduled_database_backup_executions', 'created_at', now()->subDays($keep_days), fn (Builder $query) => $query
            ->where('status', 'failed'));

        $this->cleanupTable('github_runner_executions', 'created_at', now()->subDays($keep_days), fn (Builder $query) => $query
            ->whereNotIn('status', array_map(fn (GithubRunnerStatus $status): string => $status->value, GithubRunnerStatus::active())));
    }

    /**
     * A session whose last activity is older than the absolute session lifetime can never be valid again.
     */
    private function sessionCutoff(int $keepDays): Carbon
    {
        $absoluteTimeoutMinutes = (int) config('security.session.absolute_timeout_minutes');
        $keepDaysCutoff = now()->subDays($keepDays);

        if ($absoluteTimeoutMinutes <= 0) {
            return $keepDaysCutoff;
        }

        return $keepDaysCutoff->max(now()->subMinutes($absoluteTimeoutMinutes));
    }

    /**
     * Delete the rows older than the given value in batches, so a large table is not locked
     * by one long DELETE.
     *
     * @param  (Closure(Builder): mixed)|null  $constraint  Limits the rows that may be deleted.
     */
    private function cleanupTable(string $table, string $column, mixed $olderThan, ?Closure $constraint = null): void
    {
        $query = fn (): Builder => DB::table($table)->where($column, '<', $olderThan)->when($constraint, $constraint);

        $count = $query()->count();
        $this->line("Delete $count entries from $table.");
        if (! $this->option('yes')) {
            return;
        }

        do {
            $deleted = $query()->limit(self::DELETE_BATCH_SIZE)->delete();
        } while ($deleted > 0);
    }
}
