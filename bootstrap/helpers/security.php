<?php

use App\Models\Team;
use App\Notifications\SecurityAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

if (! function_exists('notifySecurityAlert')) {
    /**
     * Alert platform administrators about a security-relevant event.
     *
     * The alert is logged and queued as a notification to the root team (id 0)
     * on every channel where its `security_alert_*_notifications` toggle is on.
     * Identical alerts (same type and identifying context) are sent once per
     * ten minutes. This function never throws.
     *
     * @param  string  $type  Short machine name, e.g. `failed_login_burst`.
     * @param  string  $title  Human readable headline.
     * @param  array<string, mixed>  $context  Non-secret details for the alert.
     */
    function notifySecurityAlert(string $type, string $title, array $context = []): void
    {
        try {
            Log::warning("Security alert: {$title}", ['type' => $type] + $context);
        } catch (Throwable) {
            // Alerting must never break the action that triggered it.
        }

        try {
            if (! Cache::add(securityAlertDeduplicationKey($type, $context), true, now()->addMinutes(10))) {
                return;
            }

            $rootTeam = Team::query()->find(0);
            if ($rootTeam === null) {
                return;
            }

            $rootTeam->notify(new SecurityAlert($type, $title, $context));
        } catch (Throwable $exception) {
            try {
                Log::warning('Security alert notification failed', [
                    'type' => $type,
                    'exception' => $exception::class,
                ]);
            } catch (Throwable) {
                // Alerting must never break the action that triggered it.
            }
        }
    }
}

if (! function_exists('securityAlertDeduplicationKey')) {
    /**
     * Cache key that identifies "the same alert": its type plus the identifying
     * context. Counters and timestamps are ignored so a repeated alert with a new
     * count is still treated as a duplicate.
     *
     * @param  array<string, mixed>  $context
     */
    function securityAlertDeduplicationKey(string $type, array $context): string
    {
        $volatileKeys = ['count', 'attempts', 'failures', 'occurred_at', 'timestamp', 'window_minutes', 'threshold'];
        $identity = array_diff_key($context, array_flip($volatileKeys));
        ksort($identity);

        return 'security-alert:'.$type.':'.hash('sha256', (string) json_encode($identity));
    }
}

if (! function_exists('maskAuditCommand')) {
    /**
     * Prepare a shell command for the audit log: mask `password=`/`token=`-style
     * values and common credential flags, then truncate to 500 characters.
     */
    function maskAuditCommand(?string $command): ?string
    {
        if ($command === null) {
            return null;
        }

        $masked = preg_replace(
            [
                '/\b([A-Za-z0-9_\-]*(?:password|passwd|pwd|secret|token|api[_-]?key|access[_-]?key)[A-Za-z0-9_\-]*)(\s*[=:]\s*)("[^"]*"|\'[^\']*\'|\S+)/i',
                '/(--?(?:password|passwd|token|secret)(?:\s+|=))("[^"]*"|\'[^\']*\'|\S+)/i',
                '/(\bAuthorization:?\s+(?:Bearer\s+|Basic\s+)?|\bBearer\s+)(\S+)/i',
            ],
            [
                '$1$2********',
                '$1********',
                '$1********',
            ],
            $command,
        ) ?? '';

        return mb_strimwidth($masked, 0, 500);
    }
}
