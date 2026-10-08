<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledDatabaseBackupExecution extends BaseModel
{
    protected static function booted(): void
    {
        static::created(function (ScheduledDatabaseBackupExecution $execution): void {
            $execution->scheduledDatabaseBackup()->update(['last_execution_at' => $execution->created_at ?? now()]);
        });
    }

    protected $fillable = [
        'uuid',
        'scheduled_database_backup_id',
        'status',
        'message',
        'size',
        'filename',
        'database_name',
        'finished_at',
        'local_storage_deleted',
        's3_storage_deleted',
        's3_uploaded',
        'is_encrypted',
        'encryption_passphrase',
    ];

    /**
     * The passphrase decrypts the backup file. It is never sent to the browser or the API.
     */
    protected $hidden = [
        'encryption_passphrase',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'finished_at' => 'datetime',
            's3_uploaded' => 'boolean',
            'local_storage_deleted' => 'boolean',
            's3_storage_deleted' => 'boolean',
            'is_encrypted' => 'boolean',
            'encryption_passphrase' => 'encrypted',
        ];
    }

    public function scheduledDatabaseBackup(): BelongsTo
    {
        return $this->belongsTo(ScheduledDatabaseBackup::class);
    }

    /**
     * The encrypted backup of a team at a backup file path. S3 keys have no leading slash,
     * local paths have one; both match the stored file name of the execution.
     */
    public static function encryptedBackupForTeam(string $filename, int $teamId): ?self
    {
        return self::query()
            ->where('filename', '/'.ltrim($filename, '/'))
            ->where('is_encrypted', true)
            ->whereNotNull('encryption_passphrase')
            ->whereHas('scheduledDatabaseBackup', fn ($query) => $query->where('team_id', $teamId))
            ->latest('id')
            ->first();
    }
}
