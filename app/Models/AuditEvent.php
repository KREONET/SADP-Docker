<?php

namespace App\Models;

use App\Services\Logging\SensitiveDataMasker;
use App\Services\Logging\ThrottledSecurityAlert;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * An append-only audit record. Rows form a hash chain: every row stores the hash of the row before
 * it (by id) and a SHA-256 over that hash plus its own immutable fields, so `php artisan audit:verify`
 * can detect rows that were changed or removed afterwards. Updating or deleting a row through the
 * model throws; only the retention prune (`pruneExpired()`) removes rows, oldest first.
 *
 * [한국어] 감사 로그 한 건입니다. 각 행은 앞 행의 해시를 이어받는 해시 체인을 이루므로 나중에 고치거나 지우면
 * `audit:verify` 명령이 찾아냅니다. 모델로 수정·삭제하면 예외가 나며, 보관 기간이 지난 행만 `pruneExpired()` 로 지웁니다.
 */
class AuditEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const RESULT_SUCCESS = 'success';

    public const RESULT_FAILURE = 'failure';

    public const RESULT_DENIED = 'denied';

    public const RESULTS = [self::RESULT_SUCCESS, self::RESULT_FAILURE, self::RESULT_DENIED];

    /**
     * Fields covered by the row hash, in canonical order. Loki bookkeeping columns stay mutable.
     */
    public const HASHED_COLUMNS = [
        'team_id',
        'event',
        'source',
        'action',
        'level',
        'result',
        'actor_type',
        'actor_id',
        'actor_name',
        'actor_email',
        'actor_token_id',
        'actor_token_name',
        'resource_type',
        'resource_uuid',
        'resource_name',
        'description',
        'metadata',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    /**
     * Columns that may change after the row was written (Loki shipping state only).
     */
    public const MUTABLE_COLUMNS = ['loki_shipped_at', 'loki_attempts'];

    /**
     * Arbitrary constant for the Postgres advisory lock that serializes chain appends.
     */
    private const CHAIN_LOCK_KEY = 7_310_402_617;

    private const PRUNE_BATCH_SIZE = 1000;

    private const WRITE_FAILURE_ALERT = 'audit_write_failed';

    protected $fillable = [
        'team_id',
        'event',
        'source',
        'action',
        'level',
        'actor_type',
        'actor_id',
        'actor_name',
        'actor_email',
        'actor_token_id',
        'actor_token_name',
        'resource_type',
        'resource_uuid',
        'resource_name',
        'description',
        'metadata',
        'ip_address',
        'user_agent',
        'result',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
            'loki_shipped_at' => 'datetime',
            'loki_attempts' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AuditEvent $event): void {
            $event->created_at ??= $event->freshTimestamp();
            $event->previous_hash = static::query()->orderByDesc('id')->value('hash');
            $event->hash = $event->computeHash();
        });

        static::updating(function (AuditEvent $event): void {
            if (array_diff(array_keys($event->getDirty()), self::MUTABLE_COLUMNS) !== []) {
                throw new LogicException('Audit events are append-only and cannot be changed.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('Audit events are append-only; only the retention prune removes them.');
        });
    }

    /**
     * New rows are appended under a lock so concurrent writers cannot link to the same previous row.
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            return parent::save($options);
        }

        return $this->getConnection()->transaction(function () use ($options): bool {
            self::lockChain();

            return parent::save($options);
        });
    }

    /**
     * Mass updates through the model's query builder may only touch the Loki bookkeeping columns.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    public function newEloquentBuilder($query): Builder
    {
        return new class($query) extends Builder
        {
            public function update(array $values)
            {
                if (array_diff(array_keys($values), AuditEvent::MUTABLE_COLUMNS) !== []) {
                    throw new LogicException('Audit events are append-only and cannot be changed.');
                }

                return parent::update($values);
            }
        };
    }

    /**
     * The SHA-256 the row should carry for its stored previous hash and fields.
     */
    public function computeHash(): string
    {
        return hash('sha256', ($this->previous_hash ?? '').self::canonicalJson($this->canonicalFields()));
    }

    /**
     * @return array<string, mixed>
     */
    public function canonicalFields(): array
    {
        $fields = [];
        foreach (self::HASHED_COLUMNS as $column) {
            $value = $this->getAttribute($column);
            $fields[$column] = match (true) {
                $value === null => null,
                $column === 'created_at' => $this->asDateTime($value)->format('Y-m-d H:i:s'),
                $column === 'metadata' => is_array($value) ? $value : (string) $value,
                default => (string) $value,
            };
        }

        return $fields;
    }

    public static function canonicalJson(mixed $value): string
    {
        return json_encode(self::sortKeys($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(fn (mixed $item): mixed => self::sortKeys($item), $value);
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    private static function lockChain(): void
    {
        $connection = (new static)->getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $connection->select('select pg_advisory_xact_lock(?)', [self::CHAIN_LOCK_KEY]);

            return;
        }

        // SQLite serializes writers itself; on MySQL the row lock on the newest row orders appends.
        static::query()->orderByDesc('id')->lockForUpdate()->first(['id']);
    }

    public function scopeVisibleToTeam(Builder $query, int $teamId, bool $includeInstanceEvents = false): Builder
    {
        return $query->where(function (Builder $query) use ($includeInstanceEvents, $teamId): void {
            $query->where('team_id', $teamId)
                ->when($includeInstanceEvents, fn (Builder $query) => $query->orWhereNull('team_id'));
        });
    }

    public function scopeFiltered(
        Builder $query,
        string $search = '',
        string $action = 'all',
        string $source = 'all',
        bool $searchSensitiveFields = true,
    ): Builder {
        return $query
            ->when($action !== 'all', fn (Builder $query) => $query->where('action', $action))
            ->when($source !== 'all', fn (Builder $query) => $query->where('source', $source))
            ->when($search !== '', function (Builder $query) use ($search, $searchSensitiveFields): void {
                $query->where(function (Builder $query) use ($search, $searchSensitiveFields): void {
                    $query->where('event', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('resource_name', 'like', "%{$search}%")
                        ->orWhere('actor_name', 'like', "%{$search}%")
                        ->when($searchSensitiveFields, fn (Builder $query) => $query->orWhere('actor_email', 'like', "%{$search}%"));
                });
            });
    }

    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->latest('created_at')->latest('id');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function record(string $event, array $context = [], string $level = 'info'): void
    {
        try {
            $attributes = self::attributesFor($event, $context, $level);

            DB::afterCommit(function () use ($attributes): void {
                defer(function () use ($attributes): void {
                    try {
                        self::query()->create($attributes);
                    } catch (Throwable $exception) {
                        self::reportWriteFailure('Audit event persistence failed', $attributes['event'], $exception);
                    }
                })->always();
            });
        } catch (Throwable $exception) {
            self::reportWriteFailure('Audit event preparation failed', $event, $exception);
        }
    }

    /**
     * A lost audit record is a security problem, so it is logged as an error and administrators are
     * alerted (at most once per alert window).
     */
    private static function reportWriteFailure(string $message, string $event, Throwable $exception): void
    {
        try {
            Log::error($message, [
                'event' => $event,
                'exception' => $exception::class,
            ]);
        } catch (Throwable) {
            // The alert below is still attempted.
        }

        ThrottledSecurityAlert::send(self::WRITE_FAILURE_ALERT, 'Audit events could not be stored', [
            'event' => $event,
            'exception' => $exception::class,
        ]);
    }

    /**
     * The outcome stored in `result`: the context value when valid, otherwise derived from the
     * event name (failed/denied/blocked/locked) and the level.
     *
     * @param  array<string, mixed>  $context
     */
    public static function resultFor(string $event, array $context = [], string $level = 'info'): string
    {
        $explicit = data_get($context, 'result');
        if (is_string($explicit) && in_array(strtolower($explicit), self::RESULTS, true)) {
            return strtolower($explicit);
        }

        $words = preg_split('/[^a-z]+/', strtolower($event), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return match (true) {
            array_intersect($words, ['denied', 'blocked', 'locked', 'forbidden', 'rejected', 'unauthorized']) !== [] => self::RESULT_DENIED,
            array_intersect($words, ['failed', 'failure', 'error']) !== [] => self::RESULT_FAILURE,
            in_array(self::normalizeLevel($level), ['warning', 'error'], true) => self::RESULT_FAILURE,
            default => self::RESULT_SUCCESS,
        };
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function attributesFor(string $event, array $context, string $level): array
    {
        $teamId = data_get(auth()->user()?->currentAccessToken(), 'team_id')
            ?? data_get($context, 'team_id')
            ?? currentTeam()?->id
            ?? self::teamIdFromContext($context);

        $parts = explode('.', $event);
        $source = $parts[0] ?? 'system';
        $resourceType = data_get($context, 'resource') ?? ($parts[1] ?? null);
        $action = data_get($context, 'action') ?? (end($parts) ?: 'event');
        $resourceUuid = self::firstContextValue($context, $resourceType ? "{$resourceType}_uuid" : null, '_uuid');
        $resourceName = self::firstContextValue($context, $resourceType ? "{$resourceType}_name" : null, '_name');
        $user = auth()->user();
        $token = $user?->currentAccessToken();
        $actorType = match (true) {
            in_array($source, ['mcp', 'webhook', 'system', 'scheduler'], true) => $source,
            $token !== null => 'api_token',
            $user !== null => 'user',
            default => 'system',
        };

        return [
            'team_id' => $teamId,
            'event' => $event,
            'source' => $source,
            'action' => $action,
            'level' => self::normalizeLevel($level),
            'result' => self::resultFor($event, $context, $level),
            'actor_type' => $actorType,
            'actor_id' => data_get($context, 'actor_id', $user?->id),
            'actor_name' => data_get($context, 'actor_name', $user?->name),
            'actor_email' => data_get($context, 'actor_email', $user?->email),
            'actor_token_id' => $token?->id,
            'actor_token_name' => $token?->name,
            'resource_type' => $resourceType,
            'resource_uuid' => $resourceUuid,
            'resource_name' => $resourceName,
            'description' => data_get($context, 'audit_description')
                ?? trim(($resourceName ?? Str::headline((string) $resourceType)).' '.Str::headline($action)),
            'metadata' => self::redact($context),
            'ip_address' => app()->bound('request') ? request()->ip() : null,
            'user_agent' => app()->bound('request') ? Str::limit((string) request()->userAgent(), 200, '') : null,
        ];
    }

    public static function normalizeLevel(string $level): string
    {
        return in_array($level, ['info', 'warning', 'error'], true) ? $level : 'info';
    }

    public static function redactContext(array $context): array
    {
        return self::redact($context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function teamIdFromContext(array $context): ?int
    {
        $applicationUuid = data_get($context, 'application_uuid');
        if (! is_string($applicationUuid) || $applicationUuid === '') {
            return null;
        }

        return Application::query()
            ->where('uuid', $applicationUuid)
            ->first()?->team()?->id;
    }

    /**
     * Days audit events are kept: the instance setting, never less than the policy minimum.
     */
    public static function retentionDays(): int
    {
        $minimum = (int) config('security.audit.min_retention_days', 365);

        try {
            $configured = (int) instanceSettings()->audit_log_retention_days;
        } catch (Throwable) {
            $configured = 0;
        }

        if ($configured <= 0) {
            $configured = (int) config('security.audit.retention_days', 365);
        }

        return max($minimum, $configured);
    }

    /**
     * The only allowed removal of audit events: rows past the retention period, oldest first, through
     * the query builder (model delete events throw). The oldest remaining row becomes the chain anchor.
     */
    public static function pruneExpired(): int
    {
        $cutoff = now()->subDays(self::retentionDays());
        $deleted = 0;

        do {
            $ids = self::query()
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(self::PRUNE_BATCH_SIZE)
                ->pluck('id');

            $batch = $ids->isEmpty() ? 0 : self::query()->toBase()->whereIn('id', $ids->all())->delete();
            $deleted += $batch;
        } while ($batch > 0);

        return $deleted;
    }

    public static function expiredCount(): int
    {
        return self::query()->where('created_at', '<', now()->subDays(self::retentionDays()))->count();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function firstContextValue(array $context, ?string $preferredKey, string $suffix): mixed
    {
        if ($preferredKey !== null && filled(data_get($context, $preferredKey))) {
            return data_get($context, $preferredKey);
        }

        $key = Arr::first(array_keys($context), fn (string $key): bool => str_ends_with($key, $suffix));

        return $key ? data_get($context, $key) : null;
    }

    private static function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && self::isSensitiveKey($key)) {
            return '[REDACTED]';
        }

        if ($key !== null && is_string($value) && self::isAttemptedIdentityKey($key)) {
            return SensitiveDataMasker::maskEmail($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        return collect($value)
            ->mapWithKeys(fn (mixed $item, string|int $itemKey): array => [
                $itemKey => self::redact($item, (string) $itemKey),
            ])
            ->all();
    }

    /**
     * Identifiers typed into a failed login may belong to someone else, so only a hint is kept.
     */
    private static function isAttemptedIdentityKey(string $key): bool
    {
        return (bool) preg_match('/^attempted_(email|login|username)$/i', $key);
    }

    private static function isSensitiveKey(string $key): bool
    {
        if (preg_match('/_(id|uuid|name)$/i', $key)) {
            return false;
        }

        return (bool) preg_match('/password|secret|token|private_key|signature|credential|invitation_email|api_key|access_key|authorization|cookie|license_key/i', $key);
    }
}
