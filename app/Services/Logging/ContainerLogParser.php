<?php

namespace App\Services\Logging;

use App\Models\LogEntry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Parses `docker logs --timestamps` output into log lines.
 */
class ContainerLogParser
{
    private const LINE_PATTERN = '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d+))?(Z|[+-]\d{2}:\d{2}) ?(.*)$/';

    /**
     * Lines at or before $after were stored by the previous run (`docker logs --since` is inclusive).
     *
     * @return list<array{logged_at: CarbonImmutable, level: string, message: string}>
     */
    public static function parse(?string $output, ?CarbonInterface $after = null): array
    {
        if ($output === null || trim($output) === '') {
            return [];
        }

        $lines = [];
        foreach (preg_split('/\r\n|\n|\r/', $output) ?: [] as $line) {
            if (! preg_match(self::LINE_PATTERN, $line, $matches)) {
                continue;
            }

            try {
                $fraction = str_pad(substr($matches[2], 0, 6), 6, '0');
                $loggedAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i:s.uP', "{$matches[1]}.{$fraction}".($matches[3] === 'Z' ? '+00:00' : $matches[3]));
            } catch (Throwable) {
                continue;
            }
            if (! $loggedAt instanceof CarbonImmutable) {
                continue;
            }
            $loggedAt = $loggedAt->utc();

            if ($after !== null && $loggedAt->lessThanOrEqualTo($after)) {
                continue;
            }

            $message = trim((string) removeAnsiColors($matches[4]));
            if ($message === '') {
                continue;
            }

            $lines[] = [
                'logged_at' => $loggedAt,
                'level' => self::detectLevel($message),
                'message' => LogEntry::truncateMessage($message),
            ];
        }

        return $lines;
    }

    public static function detectLevel(string $message): string
    {
        return match (true) {
            (bool) preg_match('/\b(fatal|panic|critical|crit|emerg(ency)?)\b/i', $message) => 'critical',
            (bool) preg_match('/\b(error|err|exception|failed|failure)\b/i', $message) => 'error',
            (bool) preg_match('/\b(warn|warning)\b/i', $message) => 'warning',
            (bool) preg_match('/\bdebug\b/i', $message) => 'debug',
            default => 'info',
        };
    }
}
