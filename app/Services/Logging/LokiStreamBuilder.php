<?php

namespace App\Services\Logging;

use App\Models\AuditEvent;
use App\Models\LogEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Groups audit events and collected log lines into Loki streams.
 *
 * Labels stay low-cardinality (source, level, instance, app); everything else goes into the JSON line,
 * which Grafana reads with `| json`.
 */
class LokiStreamBuilder
{
    public const SERVICE_NAME = 'coolify';

    /**
     * @param  Collection<int, AuditEvent>  $events
     * @return list<array{stream: array<string, string>, values: list<array{0: string, 1: string}>}>
     */
    public static function fromAuditEvents(Collection $events, string $instance): array
    {
        return self::group($events, fn (AuditEvent $event): array => [
            'labels' => ['source' => 'audit', 'level' => $event->level ?: 'info', 'instance' => $instance],
            'timestamp' => $event->created_at,
            'line' => [
                'msg' => $event->description,
                'event' => $event->event,
                'action' => $event->action,
                'audit_source' => $event->source,
                'actor_type' => $event->actor_type,
                'actor_id' => $event->actor_id,
                'actor_name' => $event->actor_name,
                'actor_email' => $event->actor_email,
                'actor_token_name' => $event->actor_token_name,
                'team_id' => $event->team_id,
                'resource_type' => $event->resource_type,
                'resource_uuid' => $event->resource_uuid,
                'resource_name' => $event->resource_name,
                'ip' => $event->ip_address,
                'user_agent' => $event->user_agent,
                'metadata' => $event->metadata,
            ],
        ]);
    }

    /**
     * @param  Collection<int, LogEntry>  $entries
     * @return list<array{stream: array<string, string>, values: list<array{0: string, 1: string}>}>
     */
    public static function fromLogEntries(Collection $entries, string $instance): array
    {
        return self::group($entries, fn (LogEntry $entry): array => [
            'labels' => array_filter([
                'source' => $entry->source,
                'level' => $entry->level,
                'instance' => $instance,
                'app' => $entry->source === LogEntry::SOURCE_CONTAINER ? (string) $entry->resource_name : null,
            ], fn (?string $value): bool => filled($value)),
            'timestamp' => $entry->logged_at,
            'line' => array_filter([
                'msg' => $entry->message,
                'context' => $entry->context ?: null,
                'team_id' => $entry->team_id,
                'server_id' => $entry->server_id,
                'resource_type' => $entry->resource_type,
                'resource_uuid' => $entry->resource_uuid,
                'container' => $entry->container_name,
            ], fn (mixed $value): bool => $value !== null),
        ]);
    }

    /**
     * @param  array<string, string>  $labels
     * @param  list<array{0: string, 1: string}>  $values
     * @return array{stream: array<string, string>, values: list<array{0: string, 1: string}>}
     */
    public static function stream(array $labels, array $values): array
    {
        return [
            'stream' => ['service_name' => self::SERVICE_NAME, ...$labels],
            'values' => $values,
        ];
    }

    public static function nanoseconds(CarbonInterface $time): string
    {
        return (string) ($time->getTimestamp() * 1_000_000_000 + (int) $time->format('u') * 1_000);
    }

    /**
     * @template TItem
     *
     * @param  Collection<int, TItem>  $items
     * @param  callable(TItem): array{labels: array<string, string>, timestamp: ?CarbonInterface, line: array<string, mixed>}  $describe
     * @return list<array{stream: array<string, string>, values: list<array{0: string, 1: string}>}>
     */
    private static function group(Collection $items, callable $describe): array
    {
        $streams = [];

        foreach ($items as $item) {
            $description = $describe($item);
            $labels = $description['labels'];
            ksort($labels);
            $key = json_encode($labels);

            $streams[$key] ??= self::stream($labels, []);
            $streams[$key]['values'][] = [
                self::nanoseconds($description['timestamp'] ?? now()),
                json_encode($description['line'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ];
        }

        return array_values(array_map(function (array $stream): array {
            usort($stream['values'], fn (array $a, array $b): int => strcmp(str_pad($a[0], 20, '0', STR_PAD_LEFT), str_pad($b[0], 20, '0', STR_PAD_LEFT)));

            return $stream;
        }, $streams));
    }
}
