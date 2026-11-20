<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * A previous password hash of a user, kept so that recently used passwords
 * cannot be chosen again.
 */
class PasswordHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'password',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Number of previous passwords that may not be reused.
     */
    public static function retainedCount(): int
    {
        return max(0, (int) config('security.password.history'));
    }

    /**
     * Store a previous password hash and drop entries beyond the configured history size.
     */
    public static function remember(int $userId, string $passwordHash): void
    {
        $keep = self::retainedCount();
        if ($keep === 0 || $passwordHash === '') {
            return;
        }

        self::query()->create([
            'user_id' => $userId,
            'password' => $passwordHash,
        ]);

        self::prune($userId, $keep);
    }

    /**
     * Delete all but the newest $keep entries of a user.
     */
    public static function prune(int $userId, int $keep): void
    {
        $retainedIds = self::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit($keep)
            ->pluck('id');

        self::query()
            ->where('user_id', $userId)
            ->whereNotIn('id', $retainedIds)
            ->delete();
    }

    /**
     * The newest stored hashes of a user, limited to the configured history size.
     *
     * @return Collection<int, string>
     */
    public static function recentHashesFor(int $userId): Collection
    {
        $keep = self::retainedCount();
        if ($keep === 0) {
            return collect();
        }

        return self::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit($keep)
            ->pluck('password');
    }
}
