<?php

namespace App\Console\Commands;

use App\Models\GitlabApp;
use App\Models\OauthSetting;
use App\Models\StandaloneMongodb;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;
use Throwable;

class RotateAppKey extends Command
{
    protected $signature = 'security:rotate-app-key
        {--dry-run : Report what would be re-encrypted without changing the database}
        {--chunk=500 : Number of rows read per batch}';

    protected $description = 'Re-encrypt all encrypted database values with the current APP_KEY (put the old key in APP_PREVIOUS_KEYS first)';

    protected $help = <<<'HELP'
    Re-encrypts every encrypted database value (encrypted casts, encrypted array casts and
    values that Coolify encrypts manually) with the current APP_KEY.

    Operator procedure:
      1. Back up the Coolify database and /data/coolify/source/.env.
      2. Create a new key:  php artisan key:generate --show
      3. In /data/coolify/source/.env set APP_PREVIOUS_KEYS to the old APP_KEY value and
         APP_KEY to the new key (several old keys: comma-separated, newest first).
      4. Restart Coolify so it loads both keys. Laravel decrypts with the previous keys
         automatically, so Coolify keeps working during the rotation.
      5. Check first:   php artisan security:rotate-app-key --dry-run
      6. Rotate:        php artisan security:rotate-app-key
         The command is idempotent: run it again until "Failed" is 0.
      7. Clear caches:  php artisan optimize:clear  (cached models keep old ciphertext)
      8. Keep APP_PREVIOUS_KEYS until queued jobs, sessions and Sentinel tokens that were
         encrypted with the old key are gone (regenerate Sentinel tokens), then remove it
         and restart Coolify.
    HELP;

    /**
     * Columns that are encrypted by accessors, mutators or packages instead of an encrypted cast.
     *
     * @var array<class-string<Model>, list<string>>
     */
    private const MANUALLY_ENCRYPTED_COLUMNS = [
        GitlabApp::class => ['webhook_token'],
        OauthSetting::class => ['client_secret'],
        StandaloneMongodb::class => ['mongo_initdb_root_password'],
        User::class => ['two_factor_secret', 'two_factor_recovery_codes'],
    ];

    private Encrypter $currentKeyEncrypter;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));

        $key = config('app.key');
        if (blank($key)) {
            $this->error('APP_KEY is not set.');

            return self::FAILURE;
        }
        $this->currentKeyEncrypter = new Encrypter($this->parseKey((string) $key), (string) config('app.cipher'));

        if (blank(config('app.previous_keys'))) {
            $this->warn('APP_PREVIOUS_KEYS is empty. Values encrypted with an old key cannot be decrypted and are reported as failed.');
        }
        $this->line($dryRun ? 'Dry run: no values are changed.' : 'Re-encrypting values with the current APP_KEY...');

        $rows = [];
        $totals = ['rotated' => 0, 'current' => 0, 'plain' => 0, 'failed' => 0];

        foreach ($this->encryptedColumns() as ['model' => $model, 'columns' => $columns]) {
            $counts = $this->rotateTable($model, $columns, $dryRun, $chunkSize);
            foreach ($counts as $column => $columnCounts) {
                $rows[] = [class_basename($model), $model->getTable(), $column, ...array_values($columnCounts)];
                foreach ($columnCounts as $type => $count) {
                    $totals[$type] += $count;
                }
            }
        }

        $this->table(['Model', 'Table', 'Column', $dryRun ? 'To re-encrypt' : 'Re-encrypted', 'Already current', 'Not encrypted', 'Failed'], $rows);
        $this->line(sprintf(
            '%s: %d, already current: %d, not encrypted: %d, failed: %d.',
            $dryRun ? 'To re-encrypt' : 'Re-encrypted',
            $totals['rotated'],
            $totals['current'],
            $totals['plain'],
            $totals['failed'],
        ));

        if (! $dryRun) {
            auditLog('system.app_key.rotated', [
                'rotated' => $totals['rotated'],
                'failed' => $totals['failed'],
                'result' => $totals['failed'] === 0 ? 'success' : 'failure',
            ], $totals['failed'] === 0 ? 'info' : 'warning');
        }

        if ($totals['failed'] > 0) {
            $this->error('Some values could not be decrypted with APP_KEY or APP_PREVIOUS_KEYS. Keep APP_PREVIOUS_KEYS and check the keys before you remove the old key.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Every model table with encrypted columns that exist in the database, once per table.
     *
     * @return list<array{model: Model, columns: list<string>}>
     */
    public function encryptedColumns(): array
    {
        $tables = [];

        foreach ($this->modelClasses() as $class) {
            try {
                $model = new $class;
            } catch (Throwable) {
                continue;
            }
            $columns = collect($model->getCasts())
                ->filter(fn (mixed $cast): bool => is_string($cast) && $this->isEncryptedCast($cast))
                ->keys()
                ->merge(self::MANUALLY_ENCRYPTED_COLUMNS[$class] ?? [])
                ->unique()
                ->values();
            if ($columns->isEmpty()) {
                continue;
            }

            try {
                $existing = $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable());
            } catch (Throwable) {
                continue;
            }
            $columns = $columns->intersect($existing)->values()->all();
            if ($columns === [] || ! in_array($model->getKeyName(), $existing, true)) {
                continue;
            }

            $tableKey = ($model->getConnectionName() ?? 'default').'|'.$model->getTable();
            $tables[$tableKey] = [
                'model' => $tables[$tableKey]['model'] ?? $model,
                'columns' => array_values(array_unique([...($tables[$tableKey]['columns'] ?? []), ...$columns])),
            ];
        }

        return array_values($tables);
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, array{rotated: int, current: int, plain: int, failed: int}>
     */
    private function rotateTable(Model $model, array $columns, bool $dryRun, int $chunkSize): array
    {
        $keyName = $model->getKeyName();
        $counts = array_fill_keys($columns, ['rotated' => 0, 'current' => 0, 'plain' => 0, 'failed' => 0]);

        // Raw queries without global scopes: soft-deleted and scoped rows are rotated too, and model
        // events do not run. chunkById() starts without a lower bound, so rows with id 0 are included.
        $model->newQueryWithoutScopes()
            ->toBase()
            ->select([$keyName, ...$columns])
            ->chunkById($chunkSize, function ($rows) use ($model, $keyName, $columns, $dryRun, &$counts): void {
                foreach ($rows as $row) {
                    $changes = [];
                    foreach ($columns as $column) {
                        [$type, $value] = $this->reencrypt($row->{$column} ?? null);
                        if ($type === null) {
                            continue;
                        }
                        $counts[$column][$type]++;
                        if ($type === 'rotated') {
                            $changes[$column] = $value;
                        }
                    }

                    if ($changes !== [] && ! $dryRun) {
                        $model->newQueryWithoutScopes()->toBase()->where($keyName, $row->{$keyName})->update($changes);
                    }
                }
            }, $keyName);

        return $counts;
    }

    /**
     * The payload is decrypted and encrypted again without unserializing, so values that were
     * stored with serialize (encrypt()) or without it (encryptString()) keep their format.
     *
     * @return array{0: 'rotated'|'current'|'plain'|'failed'|null, 1: string|null}
     */
    private function reencrypt(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [null, null];
        }
        if (! $this->isEncryptedPayload($value)) {
            return ['plain', null];
        }

        try {
            $this->currentKeyEncrypter->decrypt($value, false);

            return ['current', null];
        } catch (DecryptException) {
        }

        try {
            $plain = app('encrypter')->decrypt($value, false);
        } catch (DecryptException) {
            return ['failed', null];
        }

        return ['rotated', $this->currentKeyEncrypter->encrypt($plain, false)];
    }

    private function isEncryptedPayload(string $value): bool
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return false;
        }
        $payload = json_decode($decoded, true);

        return is_array($payload) && isset($payload['iv'], $payload['value']) && (isset($payload['mac']) || isset($payload['tag']));
    }

    private function isEncryptedCast(string $cast): bool
    {
        $type = Str::before($cast, ':');

        return str_starts_with($cast, 'encrypted') || str_contains(class_basename($type), 'Encrypted');
    }

    /**
     * @return list<class-string<Model>>
     */
    private function modelClasses(): array
    {
        $classes = [];
        foreach (File::allFiles(app_path('Models')) as $file) {
            $class = 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }
            if (! (new ReflectionClass($class))->isInstantiable()) {
                continue;
            }
            $classes[] = $class;
        }
        sort($classes);

        return $classes;
    }

    private function parseKey(string $key): string
    {
        if (str_starts_with($key, 'base64:')) {
            return (string) base64_decode(substr($key, 7), true);
        }

        return $key;
    }
}
