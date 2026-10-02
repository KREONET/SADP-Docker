<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A collected Coolify system log line or application container log line.
 * Rows are kept for InstanceSettings::logRetentionDays() and only shown to instance admins.
 */
class LogEntry extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const SOURCE_SYSTEM = 'system';

    public const SOURCE_CONTAINER = 'container';

    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    public const MAX_MESSAGE_LENGTH = 8192;

    protected $fillable = [
        'source',
        'level',
        'message',
        'context',
        'team_id',
        'server_id',
        'resource_type',
        'resource_uuid',
        'resource_name',
        'container_name',
        'logged_at',
        'loki_shipped_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'logged_at' => 'datetime',
            'loki_shipped_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public static function normalizeLevel(string $level): string
    {
        $level = strtolower($level);

        return in_array($level, self::LEVELS, true) ? $level : 'info';
    }

    public static function truncateMessage(string $message): string
    {
        return mb_strcut($message, 0, self::MAX_MESSAGE_LENGTH, 'UTF-8');
    }

    public function scopeFiltered(Builder $query, string $source = 'all', string $level = 'all', string $search = ''): Builder
    {
        return $query
            ->when($source !== 'all', fn (Builder $query) => $query->where('source', $source))
            ->when($level !== 'all', fn (Builder $query) => $query->where('level', $level))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('message', 'like', "%{$search}%")
                        ->orWhere('resource_name', 'like', "%{$search}%")
                        ->orWhere('container_name', 'like', "%{$search}%");
                });
            });
    }

    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->latest('logged_at')->latest('id');
    }

    public static function pruneOlderThan(int $days): int
    {
        return self::query()
            ->where('created_at', '<', Carbon::now()->subDays($days))
            ->delete();
    }
}
