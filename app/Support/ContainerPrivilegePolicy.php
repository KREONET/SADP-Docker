<?php

namespace App\Support;

use App\Models\Application;
use App\Models\Service;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Who may give containers access to the host.
 *
 * Custom Docker run options and Compose files can make a container privileged, add capabilities or
 * devices, share the host's namespaces or bind-mount host paths. Each of these lets the container take
 * over the server (for example by mounting the Docker socket or "/"), so only a platform administrator
 * (admin or owner of the root team) may set them.
 *
 * The check runs when the value is saved. A value that is not changed is accepted as it is, so options an
 * administrator set earlier stay in place and other members can still save unrelated settings. A member
 * cannot save a changed value that still contains (or adds) a dangerous option. A deployment that reads the
 * Compose file from the repository may not add host access to the stored file unless a platform administrator
 * started it (deploymentComposeViolation()). Runtime parsing in
 * bootstrap/helpers/docker.php and bootstrap/helpers/parsers.php is not changed.
 */
class ContainerPrivilegePolicy
{
    /**
     * Docker run options that always give the container host access.
     */
    private const DANGEROUS_RUN_OPTIONS = [
        '--privileged',
        '--cap-add',
        '--device',
        '--device-cgroup-rule',
        '--volumes-from',
    ];

    /**
     * Docker run options that share a host namespace when their value is "host" (or another container's).
     */
    private const NAMESPACE_RUN_OPTIONS = [
        '--pid',
        '--ipc',
        '--uts',
        '--userns',
        '--cgroupns',
        '--network',
        '--net',
    ];

    /**
     * Compose service keys that share a host namespace when their value is "host" (or another container's).
     */
    private const NAMESPACE_COMPOSE_KEYS = [
        'pid',
        'ipc',
        'uts',
        'userns_mode',
        'cgroup',
        'network_mode',
    ];

    /**
     * Compose service keys that always give the container host access when they are set.
     */
    private const DANGEROUS_COMPOSE_LIST_KEYS = [
        'cap_add',
        'devices',
        'device_cgroup_rules',
    ];

    /**
     * Host files that only expose the time zone or random numbers. Many images mount them read-only.
     */
    private const READ_ONLY_HOST_FILES = [
        '/etc/localtime',
        '/etc/timezone',
        '/usr/share/zoneinfo',
        '/dev/urandom',
    ];

    /**
     * security_opt values that only harden the container.
     */
    private const SAFE_SECURITY_OPTIONS = [
        'no-new-privileges',
        'no-new-privileges:true',
        'no-new-privileges=true',
    ];

    public static function canUseHostAccess(?User $user): bool
    {
        return $user?->isInstanceAdmin() === true;
    }

    /**
     * Host directories that a Compose file of this resource may bind-mount: the directory Coolify
     * manages for the resource. Relative sources (./data) resolve inside it as well.
     *
     * @return list<string>
     */
    public static function allowedHostDirectories(Application|Service|null $resource): array
    {
        if ($resource === null || blank($resource->uuid)) {
            return [];
        }

        if ($resource instanceof Application) {
            return [base_configuration_dir().'/applications/'.$resource->uuid];
        }

        return array_values(array_unique([
            $resource->workdir(),
            composeResourceDirectory($resource),
        ]));
    }

    /**
     * The dangerous options in a custom Docker run options string, as written by the user.
     *
     * @return list<string>
     */
    public static function dangerousRunOptions(?string $options): array
    {
        if (blank($options)) {
            return [];
        }

        $findings = [];
        $seenNames = [];

        foreach (self::runOptionPairs($options) as [$name, $value]) {
            $seenNames[$name] = true;
            $finding = self::runOptionFinding($name, $value);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        // convertDockerRunToCompose() finds options anywhere in the string, even inside quoted values.
        // Mirror that so an option hidden in a quoted value is reported too.
        preg_match_all('/(--\w+(?:-\w+)*)(?:\s|=)?([^\s-]+)?/', $options, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $name = $match[1];
            if (isset($seenNames[$name]) || ! in_array($name, self::DANGEROUS_RUN_OPTIONS, true)) {
                continue;
            }
            $findings[] = $name;
        }

        return array_values(array_unique($findings));
    }

    /**
     * The settings in a Compose file that give containers host access, such as privileged services or
     * bind mounts of host paths outside $allowedHostDirectories.
     *
     * @param  list<string>  $allowedHostDirectories
     * @return list<string>
     */
    public static function dangerousComposeSettings(?string $compose, array $allowedHostDirectories = []): array
    {
        if (blank($compose)) {
            return [];
        }

        try {
            $parsed = Yaml::parse($compose);
        } catch (Throwable) {
            // Invalid YAML is rejected by the Compose validators; there is nothing to inspect here.
            return [];
        }

        if (! is_array($parsed)) {
            return [];
        }

        $findings = [];

        // Included files are read by Docker Compose at deploy time and are never inspected here.
        if (array_key_exists('include', $parsed)) {
            $findings[] = 'include';
        }

        foreach ((array) ($parsed['services'] ?? []) as $serviceName => $service) {
            if (! is_array($service)) {
                continue;
            }
            // Extending a service from another file pulls in settings that are never inspected here.
            if (is_array($service['extends'] ?? null) && isset($service['extends']['file'])) {
                $findings[] = "{$serviceName}: extends.file";
            }
            foreach (self::composeServiceFindings($service, $allowedHostDirectories) as $finding) {
                $findings[] = "{$serviceName}: {$finding}";
            }
        }

        foreach ((array) ($parsed['volumes'] ?? []) as $volumeName => $volume) {
            if (! is_array($volume)) {
                continue;
            }
            $driverOptions = $volume['driver_opts'] ?? null;
            if (! is_array($driverOptions) || ! isset($driverOptions['device']) || ! is_scalar($driverOptions['device'])) {
                continue;
            }
            $isBind = str_contains((string) ($driverOptions['o'] ?? ''), 'bind') || ($driverOptions['type'] ?? null) === 'none';
            $device = (string) $driverOptions['device'];
            if ($isBind && ! self::isAllowedHostPath($device, $allowedHostDirectories)) {
                $findings[] = "volumes.{$volumeName}: device {$device}";
            }
        }

        foreach (['secrets', 'configs'] as $section) {
            foreach ((array) ($parsed[$section] ?? []) as $name => $definition) {
                if (! is_array($definition) || ! isset($definition['file']) || ! is_scalar($definition['file'])) {
                    continue;
                }
                $file = (string) $definition['file'];
                if (! self::isAllowedHostPath($file, $allowedHostDirectories)) {
                    $findings[] = "{$section}.{$name}: file {$file}";
                }
            }
        }

        return array_values(array_unique($findings));
    }

    /**
     * Why the user may not save the requested run options, or null when the change is allowed.
     */
    public static function runOptionsViolation(?string $current, ?string $requested, ?User $user): ?string
    {
        if (self::canUseHostAccess($user) || self::isUnchanged($current, $requested)) {
            return null;
        }

        return self::violationMessage(self::dangerousRunOptions($requested), 'custom_docker_run_options');
    }

    /**
     * Why the user may not save the requested Compose file, or null when the change is allowed.
     *
     * @param  list<string>  $allowedHostDirectories
     */
    public static function composeViolation(?string $current, ?string $requested, ?User $user, array $allowedHostDirectories = []): ?string
    {
        if (self::canUseHostAccess($user) || self::isUnchanged($current, $requested)) {
            return null;
        }

        return self::violationMessage(self::dangerousComposeSettings($requested, $allowedHostDirectories), 'docker_compose_raw');
    }

    /**
     * The host-access findings in $requested that $baseline does not already have. Findings are compared
     * instead of the raw text, so reformatting a Compose file does not count as a change.
     *
     * @param  list<string>  $allowedHostDirectories
     * @return list<string>
     */
    public static function newComposeFindings(?string $baseline, ?string $requested, array $allowedHostDirectories = []): array
    {
        return array_values(array_diff(
            self::dangerousComposeSettings($requested, $allowedHostDirectories),
            self::dangerousComposeSettings($baseline, $allowedHostDirectories),
        ));
    }

    /**
     * Why a deployment may not use the Compose file read from the repository, or null when it may.
     *
     * Deployments run in the queue without a signed-in user, so the stored Compose file is the approved
     * baseline: an administrator saved it, or it was saved before this check existed. The repository file
     * may keep the host access the baseline already has, but only a deployment started by a platform
     * administrator may add more.
     *
     * @param  list<string>  $allowedHostDirectories
     * @param  array<string, mixed>  $auditContext  Non-secret details for the audit event
     */
    public static function deploymentComposeViolation(?string $baseline, ?string $requested, ?User $initiator, array $allowedHostDirectories = [], array $auditContext = []): ?string
    {
        if (self::canUseHostAccess($initiator)) {
            return null;
        }

        return self::violationMessage(
            self::newComposeFindings($baseline, $requested, $allowedHostDirectories),
            'docker_compose_raw',
            ['source' => 'deployment'] + $auditContext,
        );
    }

    /**
     * @throws ValidationException When a non-administrator saves dangerous run options
     */
    public static function ensureRunOptionsAllowed(?string $current, ?string $requested, ?User $user, string $attribute = 'custom_docker_run_options'): void
    {
        $violation = self::runOptionsViolation($current, $requested, $user);
        if ($violation !== null) {
            throw ValidationException::withMessages([$attribute => $violation]);
        }
    }

    /**
     * @param  list<string>  $allowedHostDirectories
     *
     * @throws ValidationException When a non-administrator saves a Compose file with host access
     */
    public static function ensureComposeAllowed(?string $current, ?string $requested, ?User $user, array $allowedHostDirectories = [], string $attribute = 'docker_compose_raw'): void
    {
        $violation = self::composeViolation($current, $requested, $user, $allowedHostDirectories);
        if ($violation !== null) {
            throw ValidationException::withMessages([$attribute => $violation]);
        }
    }

    /**
     * The validation message for $findings, or null when there are none. A denial is written to the audit log.
     *
     * @param  list<string>  $findings
     * @param  array<string, mixed>  $auditContext
     */
    private static function violationMessage(array $findings, string $field, array $auditContext = []): ?string
    {
        if ($findings === []) {
            return null;
        }

        auditLog('container.host_access.change', array_merge($auditContext, [
            'field' => $field,
            'findings' => array_slice($findings, 0, 20),
            'result' => 'denied',
        ]), 'warning');

        return __('Only platform administrators may give containers access to the host. Remove: :options', [
            'options' => implode(', ', $findings),
        ]);
    }

    private static function isUnchanged(?string $current, ?string $requested): bool
    {
        $normalize = fn (?string $value): string => trim(str_replace("\r\n", "\n", (string) $value));

        return $normalize($current) === $normalize($requested);
    }

    /**
     * Split run options into [name, value] pairs, honouring quotes.
     *
     * @return list<array{0: string, 1: ?string}>
     */
    private static function runOptionPairs(string $options): array
    {
        preg_match_all('/"(?:\\\\.|[^"\\\\])*"|\'[^\']*\'|\S+/', $options, $matches);
        $tokens = array_map(function (string $token): string {
            if (strlen($token) >= 2 && ($token[0] === '"' || $token[0] === "'") && $token[-1] === $token[0]) {
                return substr($token, 1, -1);
            }

            return $token;
        }, $matches[0]);

        $pairs = [];
        $count = count($tokens);
        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            $nextValue = ($index + 1 < $count && ! str_starts_with($tokens[$index + 1], '-')) ? $tokens[$index + 1] : null;

            if (str_starts_with($token, '--')) {
                if (str_contains($token, '=')) {
                    $pairs[] = [strstr($token, '=', true), substr($token, strpos($token, '=') + 1)];
                } else {
                    $pairs[] = [$token, $nextValue];
                }

                continue;
            }

            if ($token === '-v') {
                $pairs[] = ['--volume', $nextValue];
            } elseif (str_starts_with($token, '-v')) {
                $pairs[] = ['--volume', ltrim(substr($token, 2), '=')];
            }
        }

        return $pairs;
    }

    private static function runOptionFinding(string $name, ?string $value): ?string
    {
        $display = $value !== null && $value !== '' ? "{$name} {$value}" : $name;

        if (in_array($name, self::DANGEROUS_RUN_OPTIONS, true)) {
            return $display;
        }

        if ($name === '--security-opt') {
            return in_array((string) $value, self::SAFE_SECURITY_OPTIONS, true) ? null : $display;
        }

        if (in_array($name, self::NAMESPACE_RUN_OPTIONS, true)) {
            return self::sharesHostNamespace($value) ? $display : null;
        }

        if ($name === '--volume') {
            $source = explode(':', (string) $value)[0];

            return self::looksLikeHostPath($source) ? $display : null;
        }

        if ($name === '--mount') {
            $type = preg_match('/(?:^|,)type=([^,]+)/', (string) $value, $typeMatch) ? $typeMatch[1] : 'volume';

            return in_array($type, ['volume', 'tmpfs'], true) ? null : $display;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $service
     * @param  list<string>  $allowedHostDirectories
     * @return list<string>
     */
    private static function composeServiceFindings(array $service, array $allowedHostDirectories): array
    {
        $findings = [];

        if (filter_var($service['privileged'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $findings[] = 'privileged';
        }

        foreach (self::DANGEROUS_COMPOSE_LIST_KEYS as $key) {
            foreach ((array) ($service[$key] ?? []) as $entry) {
                $findings[] = "{$key} ".(is_scalar($entry) ? $entry : json_encode($entry));
            }
        }

        foreach ((array) ($service['security_opt'] ?? []) as $option) {
            if (! in_array((string) (is_scalar($option) ? $option : ''), self::SAFE_SECURITY_OPTIONS, true)) {
                $findings[] = 'security_opt '.(is_scalar($option) ? $option : json_encode($option));
            }
        }

        foreach (self::NAMESPACE_COMPOSE_KEYS as $key) {
            $value = $service[$key] ?? null;
            if (is_scalar($value) && self::sharesHostNamespace((string) $value)) {
                $findings[] = "{$key}: {$value}";
            }
        }

        foreach ((array) ($service['volumes_from'] ?? []) as $source) {
            if (is_string($source) && str_starts_with($source, 'container:')) {
                $findings[] = "volumes_from {$source}";
            }
        }

        foreach ((array) ($service['volumes'] ?? []) as $volume) {
            $bind = self::bindSource($volume);
            if ($bind === null) {
                continue;
            }
            [$source, $isReadOnly] = $bind;
            if ($isReadOnly && in_array(rtrim($source, '/'), self::READ_ONLY_HOST_FILES, true)) {
                continue;
            }
            if (! self::isAllowedHostPath($source, $allowedHostDirectories, isVolumeSource: true)) {
                $findings[] = "volume {$source}";
            }
        }

        $envFiles = $service['env_file'] ?? [];
        foreach (is_array($envFiles) ? $envFiles : [$envFiles] as $envFile) {
            $path = is_array($envFile) ? ($envFile['path'] ?? null) : $envFile;
            if (is_scalar($path) && (string) $path !== '' && ! self::isAllowedHostPath((string) $path, $allowedHostDirectories)) {
                $findings[] = "env_file {$path}";
            }
        }

        return $findings;
    }

    /**
     * The host source of a bind mount and whether it is mounted read-only, or null when the volume is
     * not a bind mount.
     *
     * @return array{0: string, 1: bool}|null
     */
    private static function bindSource(mixed $volume): ?array
    {
        if (is_string($volume)) {
            try {
                $parsed = parseDockerVolumeString($volume);
            } catch (Throwable) {
                // Unsafe volume strings are rejected by validateDockerComposeForInjection().
                return null;
            }
            $source = (string) $parsed['source'];

            return self::looksLikeHostPath($source) ? [$source, (string) ($parsed['mode'] ?? '') === 'ro'] : null;
        }

        if (! is_array($volume) || ! isset($volume['source']) || ! is_scalar($volume['source'])) {
            return null;
        }

        $type = $volume['type'] ?? null;
        $source = (string) $volume['source'];
        if ($type === 'bind' || ($type === null && self::looksLikeHostPath($source))) {
            return [$source, filter_var($volume['read_only'] ?? false, FILTER_VALIDATE_BOOLEAN)];
        }

        return null;
    }

    /**
     * Whether Coolify treats the source as a host path (see sourceIsLocal()).
     */
    private static function looksLikeHostPath(string $source): bool
    {
        return str_starts_with($source, '/') || str_starts_with($source, '.') || str_starts_with($source, '~');
    }

    private static function sharesHostNamespace(?string $value): bool
    {
        $value = strtolower(trim((string) $value));

        return $value === 'host' || str_starts_with($value, 'container:');
    }

    /**
     * Relative paths resolve inside the resource directory and are allowed unless they climb out of it.
     * Absolute paths must be inside one of $allowedHostDirectories. Paths with variables are not allowed
     * because their host path is unknown when the file is saved.
     *
     * Coolify rewrites the leading "." or "~" of a bind-mount source to the resource directory, so only
     * "./x" and "~/x" stay inside it. Other files (env_file, secrets, configs) are read by Docker Compose
     * relative to the project directory, where "~" is the SSH user's home directory.
     *
     * @param  list<string>  $allowedHostDirectories
     */
    private static function isAllowedHostPath(string $path, array $allowedHostDirectories, bool $isVolumeSource = false): bool
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, '$') || str_contains($path, '\\')) {
            return false;
        }

        if (! str_starts_with($path, '/')) {
            if (in_array('..', explode('/', $path), true)) {
                return false;
            }
            if ($isVolumeSource) {
                return in_array($path, ['.', '~'], true) || str_starts_with($path, './') || str_starts_with($path, '~/');
            }

            return ! str_starts_with($path, '~');
        }

        $normalized = self::normalizeAbsolutePath($path);
        foreach ($allowedHostDirectories as $directory) {
            $directory = rtrim(self::normalizeAbsolutePath($directory), '/');
            if ($directory !== '' && ($normalized === $directory || str_starts_with($normalized, $directory.'/'))) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeAbsolutePath(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }
}
