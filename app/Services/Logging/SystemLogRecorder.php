<?php

namespace App\Services\Logging;

use App\Models\AuditEvent;
use App\Models\LogEntry;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Stores Coolify's own warning-and-above log messages while system log collection is on.
 *
 * [한국어] Coolify 가 남기는 경고 이상의 로그를 '시스템 로그 수집' 이 켜져 있을 때 DB 에 저장합니다.
 * 로그를 저장하다 다시 로그가 생기면 무한 반복되므로 `$recording` 으로 중첩 기록을 막습니다.
 */
class SystemLogRecorder
{
    public const RECORDED_LEVELS = ['warning', 'error', 'critical', 'alert', 'emergency'];

    private const MAX_TRACE_LINES = 20;

    /**
     * Writing a row can itself log (a failed query, a deprecation), so nested messages are ignored.
     */
    private static bool $recording = false;

    /**
     * Runs a log call that already has its own sink (such as the audit channel) without copying it here.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public static function withoutRecording(callable $callback): mixed
    {
        $wasRecording = self::$recording;
        self::$recording = true;

        try {
            return $callback();
        } finally {
            self::$recording = $wasRecording;
        }
    }

    public function handle(MessageLogged $event): void
    {
        $level = strtolower($event->level);
        if (self::$recording || ! in_array($level, self::RECORDED_LEVELS, true) || self::isDeprecationNotice()) {
            return;
        }

        self::$recording = true;

        try {
            if (! instanceSettings()->isSystemLogCollectionActive()) {
                return;
            }

            $attributes = [
                'source' => LogEntry::SOURCE_SYSTEM,
                'level' => LogEntry::normalizeLevel($level),
                'message' => LogEntry::truncateMessage((string) $event->message),
                'context' => $this->normalizeContext($event->context),
                'logged_at' => now(),
            ];

            // An error inside a transaction usually rolls it back, so the row is written after it ends.
            if (DB::transactionLevel() === 0) {
                self::persist($attributes);
            } else {
                defer(fn () => self::persist($attributes))->always();
            }
        } catch (Throwable) {
            // Logging must never fail the request that produced the message.
        } finally {
            self::$recording = false;
        }
    }

    /**
     * Laravel logs every PHP deprecation (mostly from vendor code) as a warning on each request.
     * They would bury real problems, so messages written by its deprecation handler are skipped.
     */
    private static function isDeprecationNotice(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            if (($frame['function'] ?? null) === 'handleDeprecationError'
                && is_a($frame['class'] ?? '', HandleExceptions::class, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function persist(array $attributes): void
    {
        $wasRecording = self::$recording;
        self::$recording = true;

        try {
            LogEntry::query()->create($attributes);
        } catch (Throwable) {
            // The database may be the reason for the message; drop the row instead of looping.
        } finally {
            self::$recording = $wasRecording;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function normalizeContext(array $context): array
    {
        $normalized = collect($context)
            ->map(fn (mixed $value): mixed => $this->normalizeValue($value))
            ->all();

        return AuditEvent::redactContext($normalized);
    }

    private function normalizeValue(mixed $value, int $depth = 0): mixed
    {
        if ($value instanceof Throwable) {
            return [
                'class' => $value::class,
                'message' => LogEntry::truncateMessage($value->getMessage()),
                'file' => $value->getFile().':'.$value->getLine(),
                'trace' => array_slice(explode("\n", $value->getTraceAsString()), 0, self::MAX_TRACE_LINES),
            ];
        }

        if (is_array($value)) {
            if ($depth >= 3) {
                return '[array]';
            }

            return collect($value)
                ->map(fn (mixed $item): mixed => $this->normalizeValue($item, $depth + 1))
                ->all();
        }

        if (is_object($value)) {
            return method_exists($value, '__toString') ? LogEntry::truncateMessage((string) $value) : '['.$value::class.']';
        }

        if (is_string($value)) {
            return LogEntry::truncateMessage($value);
        }

        return is_resource($value) ? '[resource]' : $value;
    }
}
