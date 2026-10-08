<?php

namespace App\Models;

use App\Traits\HasNextRunAt;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ScheduledDatabaseBackup extends BaseModel
{
    use HasNextRunAt;

    protected function casts(): array
    {
        return [
            'dump_all' => 'boolean',
            'encrypt_backup' => 'boolean',
            'database_backup_retention_max_storage_locally' => 'float',
            'database_backup_retention_max_storage_s3' => 'float',
            'missing_backup_notification_days' => 'integer',
            'last_execution_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    protected $fillable = [
        'uuid',
        'team_id',
        'description',
        'enabled',
        'save_s3',
        'frequency',
        'database_backup_retention_amount_locally',
        'database_type',
        'database_id',
        's3_storage_id',
        'databases_to_backup',
        'dump_all',
        'database_backup_retention_days_locally',
        'database_backup_retention_max_storage_locally',
        'database_backup_retention_amount_s3',
        'database_backup_retention_days_s3',
        'database_backup_retention_max_storage_s3',
        'timeout',
        'disable_local_backup',
        'missing_backup_notification_days',
        'encrypt_backup',
    ];

    public static function ownedByCurrentTeam()
    {
        return ScheduledDatabaseBackup::whereRelation('team', 'id', currentTeam()->id)->orderBy('created_at', 'desc');
    }

    public static function ownedByCurrentTeamAPI(int $teamId)
    {
        return ScheduledDatabaseBackup::whereRelation('team', 'id', $teamId)->orderBy('created_at', 'desc');
    }

    /**
     * Coolify's own database cannot be encrypted: the passphrases are stored in that database,
     * so an encrypted copy could not be restored after the database is lost.
     */
    public function supportsEncryption(): bool
    {
        return ! ($this->database_type === StandalonePostgresql::class && (int) $this->database_id === 0);
    }

    public function shouldEncryptBackups(): bool
    {
        return (bool) $this->encrypt_backup && $this->supportsEncryption();
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function database(): MorphTo
    {
        return $this->morphTo();
    }

    public function latest_log(): HasOne
    {
        return $this->hasOne(ScheduledDatabaseBackupExecution::class)->latest();
    }

    public function executions(): HasMany
    {
        // Last execution first
        return $this->hasMany(ScheduledDatabaseBackupExecution::class)->orderBy('created_at', 'desc');
    }

    public function s3()
    {
        return $this->belongsTo(S3Storage::class, 's3_storage_id');
    }

    public function get_last_days_backup_status($days = 7)
    {
        return $this->hasMany(ScheduledDatabaseBackupExecution::class)->where('created_at', '>=', now()->subDays($days))->get();
    }

    public function executionsPaginated(int $skip = 0, int $take = 10)
    {
        $executions = $this->hasMany(ScheduledDatabaseBackupExecution::class)->orderBy('created_at', 'desc');
        $count = $executions->count();
        $executions = $executions->skip($skip)->take($take)->get();

        return [
            'count' => $count,
            'executions' => $executions,
        ];
    }

    public function server()
    {
        if ($this->database) {
            if ($this->database instanceof ServiceDatabase) {
                $destination = data_get($this->database->service, 'destination');
                $server = data_get($destination, 'server');
            } else {
                $destination = data_get($this->database, 'destination');
                $server = data_get($destination, 'server');
            }
            if ($server) {
                return $server;
            }
        }

        return null;
    }
}
