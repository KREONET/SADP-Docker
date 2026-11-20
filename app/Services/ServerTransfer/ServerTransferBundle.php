<?php

namespace App\Services\ServerTransfer;

use App\Support\ValidationPatterns;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ServerTransferBundle
{
    public const SCHEMA_VERSION = 1;

    /**
     * Passphrase envelope format written by encryptWithPassphrase().
     */
    public const ENCRYPTION_VERSION = 2;

    public const KDF = 'pbkdf2-sha256';

    /**
     * Floor for the bundle KDF; bundles leave the instance, so the test-time
     * password hashing override never lowers it.
     */
    public const MIN_KDF_ITERATIONS = 600000;

    public const MAX_KDF_ITERATIONS = 10_000_000;

    public const KDF_SALT_BYTES = 32;

    private const IV_BYTES = 16;

    private const CIPHER = 'AES-256-CBC';

    /**
     * Every `uuid`, `*_uuid`, and `*_uuids` value in a bundle. Coolify ids are random lowercase
     * letters and digits (new_public_id) or RFC 4122 UUIDs. The ids go into file paths and local
     * shell commands (SSH key file, SSH mux socket, project icon directory).
     */
    public const ID_PATTERN = '/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}\z/';

    /**
     * The most items a bundle can have for each list key, summed over the whole bundle.
     *
     * @var array<string, int>
     */
    public const MAX_ITEMS = [
        'projects' => 1000,
        'environments' => 5000,
        'applications' => 5000,
        'databases' => 5000,
        'services' => 2000,
        'previews' => 10000,
        'environment_variables' => 50000,
        'environment_variables_preview' => 50000,
        'shared_environment_variables' => 20000,
        'persistent_storages' => 20000,
        'file_storages' => 20000,
        'scheduled_tasks' => 10000,
        'scheduled_backups' => 10000,
        'volume_backups' => 10000,
        'tags' => 20000,
        'resource_links' => 10000,
        'destinations' => 1000,
        'private_keys' => 1000,
        'github_apps' => 1000,
        'gitlab_apps' => 1000,
        'gitea_apps' => 1000,
        's3_storages' => 1000,
        'cloud_provider_tokens' => 1000,
        'integration_tokens' => 1000,
        'dns_zones' => 1000,
        'managed_dns_records' => 10000,
        'ssl_certificates' => 10000,
        'image_scan_policies' => 10000,
        'image_scan_ignores' => 20000,
        'warnings' => 1000,
        'post_transfer_checklist' => 1000,
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function wrap(array $payload): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'coolify_version' => config('constants.coolify.version'),
            'exported_at' => now()->toIso8601String(),
            'export_id' => new_public_id(),
        ], $payload);
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public static function validate(array $bundle): array
    {
        $errors = [];
        $warnings = [];

        if (! isset($bundle['schema_version'])) {
            $errors[] = 'Missing schema_version.';
        } elseif ((int) $bundle['schema_version'] !== self::SCHEMA_VERSION) {
            $errors[] = 'Unsupported schema_version '.$bundle['schema_version'].'. Expected '.self::SCHEMA_VERSION.'.';
        }

        $bundleVersion = data_get($bundle, 'coolify_version');
        $instanceVersion = (string) config('constants.coolify.version');
        if (! is_string($bundleVersion) || $bundleVersion === '') {
            $errors[] = 'Missing coolify_version. Export the server again with Coolify '.$instanceVersion.' or newer.';
        } elseif (version_compare($bundleVersion, $instanceVersion, '>')) {
            $errors[] = "This bundle was exported from Coolify {$bundleVersion}. Upgrade this instance (Coolify {$instanceVersion}) to {$bundleVersion} or newer before you import it.";
        }

        if (! isset($bundle['export_id']) || ! is_string($bundle['export_id']) || $bundle['export_id'] === '') {
            $errors[] = 'Missing export_id.';
        }

        if (! isset($bundle['server']) || ! is_array($bundle['server'])) {
            $errors[] = 'Missing server payload.';
        } else {
            foreach (['uuid', 'name', 'ip', 'port', 'user'] as $field) {
                if (! array_key_exists($field, $bundle['server'])) {
                    $errors[] = "Missing server.{$field}.";
                }
            }
        }

        if (! isset($bundle['private_key']) || ! is_array($bundle['private_key'])) {
            $errors[] = 'Missing private_key payload.';
        } else {
            if (blank(data_get($bundle, 'private_key.private_key'))) {
                $errors[] = 'Missing private_key.private_key material.';
            }
        }

        if (! isset($bundle['destinations']) || ! is_array($bundle['destinations'])) {
            $errors[] = 'Missing destinations array.';
        } elseif (count($bundle['destinations']) === 0) {
            $warnings[] = 'Bundle has no destinations; a default destination will be used.';
        }

        if (! isset($bundle['projects']) || ! is_array($bundle['projects'])) {
            $errors[] = 'Missing projects array.';
        }

        $errors = array_merge($errors, self::contentErrors($bundle));

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $bundle
     */
    public static function assertValid(array $bundle): void
    {
        $result = self::validate($bundle);
        if (! $result['valid']) {
            throw ValidationException::withMessages([
                'bundle' => $result['errors'],
            ]);
        }
    }

    /**
     * Checks the values of a bundle before the import writes anything. A bundle comes from the
     * API, so each value gets the validation that the normal API applies to it.
     *
     * @param  array<string, mixed>  $bundle
     * @return list<string>
     */
    public static function contentErrors(array $bundle): array
    {
        $errors = [];
        $counts = [];
        self::walk($bundle, '', $errors, $counts);

        foreach ($counts as $key => $count) {
            if ($count > self::MAX_ITEMS[$key]) {
                $errors[] = "The bundle has {$count} {$key}. A bundle can have at most ".self::MAX_ITEMS[$key]." {$key}.";
            }
        }

        // Stop before the field checks: a bundle with a bad id or too many items is not imported.
        if ($errors !== []) {
            return $errors;
        }

        foreach (self::items(data_get($bundle, 'destinations')) as $i => $destination) {
            self::check($errors, "destinations.{$i}.network", $destination['network'] ?? null, ['nullable', 'string', 'max:255', 'regex:'.ValidationPatterns::DOCKER_NETWORK_PATTERN]);
        }

        // Only http and https URLs, as for a git source made with the API. The import does not resolve the host:
        // the target may not reach the git host yet, and SafeExternalUrl checks the host on every request.
        $gitSourceUrl = function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && ! isValidDomainUrl($value)) {
                $fail('The :attribute must use the http or https scheme.');
            }
        };
        foreach (['github_apps', 'gitlab_apps', 'gitea_apps'] as $key) {
            foreach (self::items(data_get($bundle, $key)) as $i => $source) {
                foreach (['html_url', 'api_url'] as $field) {
                    self::check($errors, "{$key}.{$i}.{$field}", $source[$field] ?? null, ['nullable', 'string', 'url', $gitSourceUrl]);
                }
            }
        }

        foreach (self::items(data_get($bundle, 'ssl_certificates')) as $i => $certificate) {
            self::checkPath($errors, "ssl_certificates.{$i}.configuration_dir", $certificate['configuration_dir'] ?? null);
            self::checkPath($errors, "ssl_certificates.{$i}.mount_path", $certificate['mount_path'] ?? null);
        }

        foreach (self::items(data_get($bundle, 'volume_backups')) as $i => $backup) {
            self::checkCron($errors, "volume_backups.{$i}.frequency", $backup['frequency'] ?? null);
        }

        foreach (self::items(data_get($bundle, 'projects')) as $p => $project) {
            foreach (self::items($project['environments'] ?? null) as $e => $environment) {
                $at = "projects.{$p}.environments.{$e}";

                foreach (self::items($environment['applications'] ?? null) as $a => $application) {
                    self::checkDomains($errors, "{$at}.applications.{$a}.fqdn", data_get($application, 'attributes.fqdn'));
                    self::checkStorages($errors, "{$at}.applications.{$a}", $application);
                    self::checkScheduledTasks($errors, "{$at}.applications.{$a}", $application);
                    foreach (self::items($application['previews'] ?? null) as $r => $preview) {
                        self::checkDomains($errors, "{$at}.applications.{$a}.previews.{$r}.fqdn", $preview['fqdn'] ?? null);
                        self::checkStorages($errors, "{$at}.applications.{$a}.previews.{$r}", $preview);
                    }
                }

                foreach (self::items($environment['databases'] ?? null) as $d => $database) {
                    self::checkDatabasePorts($errors, "{$at}.databases.{$d}", (array) ($database['attributes'] ?? []));
                    self::checkStorages($errors, "{$at}.databases.{$d}", $database);
                    self::checkScheduledBackups($errors, "{$at}.databases.{$d}", $database);
                }

                foreach (self::items($environment['services'] ?? null) as $s => $service) {
                    self::checkScheduledTasks($errors, "{$at}.services.{$s}", $service);
                    foreach (self::items($service['applications'] ?? null) as $a => $serviceApplication) {
                        self::checkDomains($errors, "{$at}.services.{$s}.applications.{$a}.fqdn", data_get($serviceApplication, 'attributes.fqdn', $serviceApplication['fqdn'] ?? null));
                        self::checkStorages($errors, "{$at}.services.{$s}.applications.{$a}", $serviceApplication);
                    }
                    foreach (self::items($service['databases'] ?? null) as $d => $serviceDatabase) {
                        self::checkStorages($errors, "{$at}.services.{$s}.databases.{$d}", $serviceDatabase);
                        self::checkScheduledBackups($errors, "{$at}.services.{$s}.databases.{$d}", $serviceDatabase);
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Checks every id in the bundle and counts the items of every list key in MAX_ITEMS.
     *
     * @param  list<string>  $errors
     * @param  array<string, int>  $counts
     */
    private static function walk(array $node, string $path, array &$errors, array &$counts): void
    {
        foreach ($node as $key => $value) {
            $at = $path === '' ? (string) $key : "{$path}.{$key}";

            if (is_string($key)) {
                if ($key === 'uuid' || str_ends_with($key, '_uuid')) {
                    self::checkId($errors, $at, $value);
                } elseif (str_ends_with($key, '_uuids')) {
                    foreach (is_array($value) ? $value : [$value] as $i => $id) {
                        self::checkId($errors, "{$at}.{$i}", $id);
                    }

                    continue;
                }

                if (isset(self::MAX_ITEMS[$key]) && is_array($value)) {
                    $counts[$key] = ($counts[$key] ?? 0) + (array_is_list($value)
                        ? count($value)
                        : array_sum(array_map(fn ($list) => is_array($list) ? count($list) : 0, $value)));
                }
            }

            if (is_array($value)) {
                self::walk($value, $at, $errors, $counts);
            }
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private static function checkId(array &$errors, string $at, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || preg_match(self::ID_PATTERN, $value) !== 1) {
            $errors[] = "{$at} is not a valid id. An id may only contain letters, digits, hyphens, and underscores (at most 64 characters).";
        }
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    private static function items(mixed $value): array
    {
        return is_array($value) ? array_filter($value, 'is_array') : [];
    }

    /**
     * @param  list<string>  $errors
     * @param  array<int, mixed>  $rules
     */
    private static function check(array &$errors, string $at, mixed $value, array $rules): void
    {
        $validator = Validator::make(['value' => $value], ['value' => $rules], [], ['value' => $at]);
        foreach ($validator->errors()->all() as $message) {
            $errors[] = $message;
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private static function checkDomains(array &$errors, string $at, mixed $value): void
    {
        foreach (ValidationPatterns::validateApplicationDomains($value) as $message) {
            $errors[] = "{$at}: {$message}";
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private static function checkPath(array &$errors, string $at, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (! is_string($value)) {
            $errors[] = "{$at} must be a string.";

            return;
        }

        try {
            validateShellSafePath($value, $at);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private static function checkCron(array &$errors, string $at, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        try {
            $valid = is_string($value) && validate_cron_expression($value);
        } catch (Throwable) {
            $valid = false;
        }
        if (! $valid) {
            $errors[] = "{$at} is not a valid cron expression or frequency.";
        }
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $resource
     */
    private static function checkStorages(array &$errors, string $at, array $resource): void
    {
        foreach (self::items($resource['persistent_storages'] ?? null) as $i => $volume) {
            self::checkPath($errors, "{$at}.persistent_storages.{$i}.mount_path", $volume['mount_path'] ?? null);
            self::checkPath($errors, "{$at}.persistent_storages.{$i}.host_path", $volume['host_path'] ?? null);
        }
        foreach (self::items($resource['file_storages'] ?? null) as $i => $storage) {
            self::checkPath($errors, "{$at}.file_storages.{$i}.fs_path", $storage['fs_path'] ?? null);
            self::checkPath($errors, "{$at}.file_storages.{$i}.mount_path", $storage['mount_path'] ?? null);
        }
    }

    /**
     * The same checks as the scheduled task API.
     *
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $resource
     */
    private static function checkScheduledTasks(array &$errors, string $at, array $resource): void
    {
        foreach (self::items($resource['scheduled_tasks'] ?? null) as $i => $task) {
            self::check($errors, "{$at}.scheduled_tasks.{$i}.command", $task['command'] ?? null, ['required', 'string']);
            self::check($errors, "{$at}.scheduled_tasks.{$i}.container", $task['container'] ?? null, ['nullable', ...ValidationPatterns::containerNameRules()]);
            self::checkCron($errors, "{$at}.scheduled_tasks.{$i}.frequency", $task['frequency'] ?? '');
            self::check($errors, "{$at}.scheduled_tasks.{$i}.disabled_reason", $task['disabled_reason'] ?? null, ['nullable', 'string', 'max:1000']);
        }
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $database
     */
    private static function checkScheduledBackups(array &$errors, string $at, array $database): void
    {
        foreach (self::items($database['scheduled_backups'] ?? null) as $i => $backup) {
            self::checkCron($errors, "{$at}.scheduled_backups.{$i}.frequency", $backup['frequency'] ?? null);
            self::checkPath($errors, "{$at}.scheduled_backups.{$i}.databases_to_backup", $backup['databases_to_backup'] ?? null);
        }
    }

    /**
     * Checks the port values of a database. Older databases were saved before the UI checked them
     * (March 2026), so this accepts any public port in 1-65535, spaces around mappings, empty entries,
     * and container-only ports. It refuses values that are not ports, because they reach the compose file.
     *
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $attributes
     */
    private static function checkDatabasePorts(array &$errors, string $at, array $attributes): void
    {
        if (array_key_exists('public_port', $attributes)) {
            self::check($errors, "{$at}.public_port", $attributes['public_port'], ['nullable', 'integer', 'between:1,65535']);
        }

        if (! array_key_exists('ports_mappings', $attributes) || $attributes['ports_mappings'] === null) {
            return;
        }
        $mappings = $attributes['ports_mappings'];
        if (! is_string($mappings)) {
            $errors[] = "{$at}.ports_mappings must be a string.";

            return;
        }
        foreach (explode(',', $mappings) as $mapping) {
            $mapping = trim($mapping, " \t");
            if ($mapping === '' || preg_match('/^\d+(?:-\d+)?(?:\/(?:tcp|udp|sctp))?$/', $mapping) === 1 || preg_match(ValidationPatterns::PORT_MAPPINGS_PATTERN, $mapping) === 1) {
                continue;
            }
            $errors[] = "{$at}.ports_mappings must be a comma-separated list of port mappings (e.g. 5432:5432,127.0.0.1:8080:80/udp).";

            return;
        }
    }

    /**
     * Encrypt an entire bundle for mailbox/API transport with a user passphrase.
     *
     * Envelope v2: PBKDF2-HMAC-SHA256 (random salt, >= 600000 iterations) derives a
     * master key; HKDF-SHA256 splits it into separate AES-256-CBC and HMAC-SHA256 keys.
     * The MAC covers version, salt, iterations, IV and ciphertext (encrypt-then-MAC).
     *
     * @param  array<string, mixed>  $bundle
     * @return array{encrypted: true, schema_version: int, encryption_version: int, kdf: string, iterations: int, salt: string, iv: string, cipher: string, mac: string, payload: string}
     */
    public static function encryptWithPassphrase(array $bundle, string $passphrase): array
    {
        if ($passphrase === '') {
            throw new RuntimeException('Passphrase must not be empty.');
        }

        $json = json_encode($bundle, JSON_THROW_ON_ERROR);
        $iterations = self::kdfIterations();
        $salt = random_bytes(self::KDF_SALT_BYTES);
        $iv = random_bytes(self::IV_BYTES);
        ['encryption' => $encryptionKey, 'mac' => $macKey] = self::deriveKeys($passphrase, $salt, $iterations);

        $cipher = openssl_encrypt($json, self::CIPHER, $encryptionKey, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new RuntimeException('Failed to encrypt transfer bundle.');
        }

        $mac = hash_hmac('sha256', self::macInput(self::ENCRYPTION_VERSION, $salt, $iterations, $iv, $cipher), $macKey);

        return [
            'encrypted' => true,
            'schema_version' => self::SCHEMA_VERSION,
            'encryption_version' => self::ENCRYPTION_VERSION,
            'kdf' => self::KDF,
            'iterations' => $iterations,
            'salt' => base64_encode($salt),
            'iv' => base64_encode($iv),
            'cipher' => strtolower(self::CIPHER),
            'mac' => $mac,
            'payload' => base64_encode($cipher),
        ];
    }

    /**
     * Decrypt a passphrase-encrypted bundle. Accepts the current v2 envelope and,
     * read-only, the legacy v1 envelope (unsalted SHA-256 key, no encryption_version).
     *
     * @param  array<string, mixed>  $encrypted
     * @return array<string, mixed>
     */
    public static function decryptWithPassphrase(array $encrypted, string $passphrase): array
    {
        if (! data_get($encrypted, 'encrypted')) {
            throw new RuntimeException('Bundle is not encrypted.');
        }

        $version = data_get($encrypted, 'encryption_version');
        if ($version === null) {
            $json = self::decryptLegacyV1($encrypted, $passphrase);
        } elseif ((int) $version === self::ENCRYPTION_VERSION) {
            $json = self::decryptV2($encrypted, $passphrase);
        } else {
            throw new RuntimeException('Unsupported bundle encryption version.');
        }

        /** @var array<string, mixed> $bundle */
        $bundle = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $bundle;
    }

    /**
     * @param  array<string, mixed>  $encrypted
     */
    private static function decryptV2(array $encrypted, string $passphrase): string
    {
        $iterations = filter_var(data_get($encrypted, 'iterations'), FILTER_VALIDATE_INT);
        $salt = base64_decode((string) data_get($encrypted, 'salt'), true);
        $iv = base64_decode((string) data_get($encrypted, 'iv'), true);
        $cipher = base64_decode((string) data_get($encrypted, 'payload'), true);
        $mac = data_get($encrypted, 'mac');

        if (data_get($encrypted, 'kdf') !== self::KDF
            || strtolower((string) data_get($encrypted, 'cipher', self::CIPHER)) !== strtolower(self::CIPHER)
            || $iterations === false || $iterations < self::MIN_KDF_ITERATIONS || $iterations > self::MAX_KDF_ITERATIONS
            || $salt === false || strlen($salt) !== self::KDF_SALT_BYTES
            || $iv === false || strlen($iv) !== self::IV_BYTES
            || $cipher === false || $cipher === ''
            || ! is_string($mac) || strlen($mac) !== 64) {
            throw new RuntimeException('Invalid encrypted payload.');
        }

        ['encryption' => $encryptionKey, 'mac' => $macKey] = self::deriveKeys($passphrase, $salt, $iterations);

        $expectedMac = hash_hmac('sha256', self::macInput(self::ENCRYPTION_VERSION, $salt, $iterations, $iv, $cipher), $macKey);
        if (! hash_equals($expectedMac, $mac)) {
            throw new RuntimeException('Invalid passphrase or corrupted bundle.');
        }

        $json = openssl_decrypt($cipher, self::CIPHER, $encryptionKey, OPENSSL_RAW_DATA, $iv);
        if ($json === false) {
            throw new RuntimeException('Failed to decrypt transfer bundle.');
        }

        return $json;
    }

    /**
     * Legacy v1 envelope: base64(iv || hex HMAC || ciphertext), key = SHA-256(passphrase).
     * Kept only so bundles exported before v2 can still be imported; never written.
     *
     * @param  array<string, mixed>  $encrypted
     */
    private static function decryptLegacyV1(array $encrypted, string $passphrase): string
    {
        $raw = base64_decode((string) data_get($encrypted, 'payload'), true);
        if ($raw === false || strlen($raw) < 96) {
            throw new RuntimeException('Invalid encrypted payload.');
        }

        $key = hash('sha256', $passphrase, true);
        $iv = substr($raw, 0, 16);
        $mac = substr($raw, 16, 64);
        $cipher = substr($raw, 80);
        $expectedMac = hash_hmac('sha256', $iv.$cipher, $key);
        if (! hash_equals($expectedMac, $mac)) {
            throw new RuntimeException('Invalid passphrase or corrupted bundle.');
        }

        $json = openssl_decrypt($cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if ($json === false) {
            throw new RuntimeException('Failed to decrypt transfer bundle.');
        }

        return $json;
    }

    /**
     * @return array{encryption: string, mac: string}
     */
    private static function deriveKeys(string $passphrase, string $salt, int $iterations): array
    {
        $masterKey = hash_pbkdf2('sha256', $passphrase, $salt, $iterations, 32, true);

        return [
            'encryption' => hash_hkdf('sha256', $masterKey, 32, 'server-transfer-bundle:v2:aes-256-cbc', $salt),
            'mac' => hash_hkdf('sha256', $masterKey, 32, 'server-transfer-bundle:v2:hmac-sha256', $salt),
        ];
    }

    private static function macInput(int $version, string $salt, int $iterations, string $iv, string $cipher): string
    {
        return pack('N', $version).$salt.pack('N', $iterations).$iv.$cipher;
    }

    private static function kdfIterations(): int
    {
        return min(self::MAX_KDF_ITERATIONS, max(self::MIN_KDF_ITERATIONS, (int) config('security.password.pbkdf2_iterations')));
    }
}
