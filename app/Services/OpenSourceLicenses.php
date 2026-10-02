<?php

namespace App\Services;

use Illuminate\Support\Arr;
use RuntimeException;

/**
 * Inventory of the third-party open source software that ships with Coolify, for the
 * open source notices page. `php artisan generate:open-source-licenses` writes it from the
 * lock files and the license files of the installed packages; the page reads the JSON.
 *
 * Only shipped code is listed: Composer runtime packages, npm packages bundled into the web UI,
 * and the packages of the terminal server. Development tools (packages-dev, test runners) are not.
 */
class OpenSourceLicenses
{
    public const INVENTORY_PATH = 'resources/open-source/licenses.json';

    public const COMPONENT_PANEL = 'panel';

    public const COMPONENT_WEB_UI = 'web-ui';

    public const COMPONENT_TERMINAL = 'terminal';

    public const COMPONENTS = [
        self::COMPONENT_PANEL => 'Coolify panel (PHP)',
        self::COMPONENT_WEB_UI => 'Web interface (JavaScript/CSS)',
        self::COMPONENT_TERMINAL => 'Terminal server (Node.js)',
    ];

    /**
     * Build tools that are npm devDependencies but end up in the shipped CSS.
     */
    public const BUNDLED_BUILD_PACKAGES = ['tailwindcss', 'tailwind-scrollbar'];

    private const LICENSE_FILE_PATTERN = '/^(licen[cs]e|copying|unlicense)([-._][a-z0-9.-]*)?$/i';

    private const MAX_LICENSE_TEXT_BYTES = 65536;

    /**
     * @return array{packages: list<array{key: string, name: string, version: string, component: string, licenses: list<string>, homepage: ?string, license_text: ?string}>}
     */
    public static function generate(string $basePath, ?string $terminalModulesPath = null): array
    {
        $terminalModulesPath ??= $basePath.'/docker/coolify-terminal/node_modules';

        $packages = [
            ...self::composerPackages($basePath),
            ...self::npmPackages($basePath.'/package-lock.json', $basePath.'/node_modules', self::COMPONENT_WEB_UI, self::BUNDLED_BUILD_PACKAGES),
            ...self::npmPackages($basePath.'/docker/coolify-terminal/package-lock.json', $terminalModulesPath, self::COMPONENT_TERMINAL),
        ];

        usort($packages, fn (array $a, array $b): int => [$a['component'], strtolower($a['name'])] <=> [$b['component'], strtolower($b['name'])]);

        return ['packages' => $packages];
    }

    /**
     * @return list<array{key: string, name: string, version: string, component: string, licenses: list<string>, homepage: ?string, license_text: ?string}>
     */
    public static function packages(): array
    {
        return once(function (): array {
            $path = base_path(self::INVENTORY_PATH);
            if (! is_file($path)) {
                return [];
            }

            return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['packages'] ?? [];
        });
    }

    /**
     * The name => version pairs the inventory must contain, read from the lock files only.
     *
     * @return array<string, string> keyed by "component:name"
     */
    public static function expectedVersions(string $basePath): array
    {
        $versions = [];
        foreach (self::composerLock($basePath)['packages'] ?? [] as $package) {
            $versions[self::COMPONENT_PANEL.':'.$package['name']] = self::composerVersion($package);
        }
        foreach ([
            self::COMPONENT_WEB_UI => [$basePath.'/package-lock.json', self::BUNDLED_BUILD_PACKAGES],
            self::COMPONENT_TERMINAL => [$basePath.'/docker/coolify-terminal/package-lock.json', []],
        ] as $component => [$lockFile, $bundled]) {
            foreach (self::shippedNpmEntries($lockFile, $bundled) as $name => $entry) {
                $versions["{$component}:{$name}"] = (string) $entry['version'];
            }
        }
        ksort($versions);

        return $versions;
    }

    /**
     * @return list<array{key: string, name: string, version: string, component: string, licenses: list<string>, homepage: ?string, license_text: ?string}>
     */
    private static function composerPackages(string $basePath): array
    {
        return array_map(fn (array $package): array => [
            'key' => self::COMPONENT_PANEL.':'.$package['name'],
            'name' => $package['name'],
            'version' => self::composerVersion($package),
            'component' => self::COMPONENT_PANEL,
            'licenses' => array_values(Arr::wrap($package['license'] ?? [])),
            'homepage' => self::httpUrl($package['homepage'] ?? data_get($package, 'source.url')),
            'license_text' => self::licenseText($basePath.'/vendor/'.$package['name']),
        ], self::composerLock($basePath)['packages'] ?? []);
    }

    /**
     * @param  list<string>  $bundledBuildPackages
     * @return list<array{key: string, name: string, version: string, component: string, licenses: list<string>, homepage: ?string, license_text: ?string}>
     */
    private static function npmPackages(string $lockFile, string $modulesPath, string $component, array $bundledBuildPackages = []): array
    {
        $packages = [];
        foreach (self::shippedNpmEntries($lockFile, $bundledBuildPackages) as $name => $entry) {
            $directory = $modulesPath.'/'.$name;
            $manifest = is_file($directory.'/package.json')
                ? (json_decode((string) file_get_contents($directory.'/package.json'), true) ?: [])
                : [];

            $packages[] = [
                'key' => "{$component}:{$name}",
                'name' => $name,
                'version' => (string) $entry['version'],
                'component' => $component,
                'licenses' => self::npmLicenses($entry['license'] ?? $manifest['license'] ?? null),
                'homepage' => self::httpUrl($manifest['homepage'] ?? data_get($manifest, 'repository.url') ?? (is_string($manifest['repository'] ?? null) ? $manifest['repository'] : null))
                    ?? 'https://www.npmjs.com/package/'.$name,
                'license_text' => self::licenseText($directory),
            ];
        }

        return $packages;
    }

    /**
     * Top-level and nested lock entries that are not dev-only, keyed by package name.
     *
     * @param  list<string>  $bundledBuildPackages
     * @return array<string, array<string, mixed>>
     */
    private static function shippedNpmEntries(string $lockFile, array $bundledBuildPackages): array
    {
        if (! is_file($lockFile)) {
            throw new RuntimeException("Missing {$lockFile}.");
        }

        $entries = [];
        foreach (json_decode((string) file_get_contents($lockFile), true, flags: JSON_THROW_ON_ERROR)['packages'] ?? [] as $path => $entry) {
            if ($path === '' || ! isset($entry['version'])) {
                continue;
            }

            $name = (string) str($path)->afterLast('node_modules/');
            if (($entry['dev'] ?? false) && ! in_array($name, $bundledBuildPackages, true)) {
                continue;
            }

            // The same package can be installed at several paths; list the hoisted one.
            $entries[$name] ??= $entry;
        }
        ksort($entries);

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    private static function composerLock(string $basePath): array
    {
        $lockFile = $basePath.'/composer.lock';
        if (! is_file($lockFile)) {
            throw new RuntimeException("Missing {$lockFile}.");
        }

        return json_decode((string) file_get_contents($lockFile), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private static function composerVersion(array $package): string
    {
        return ltrim((string) $package['version'], 'v');
    }

    /**
     * @return list<string>
     */
    private static function npmLicenses(mixed $license): array
    {
        if (is_array($license)) {
            $license = $license['type'] ?? null;
        }
        if (! is_string($license) || $license === '') {
            return [];
        }

        // SPDX expressions such as "(MIT OR Apache-2.0)" list each choice.
        return array_values(array_filter(
            preg_split('/\s+(?:OR|AND)\s+/', trim($license, '() ')) ?: [],
            fn (string $id): bool => $id !== '',
        ));
    }

    private static function licenseText(string $directory): ?string
    {
        if (! is_dir($directory)) {
            return null;
        }

        $files = array_values(array_filter(
            scandir($directory) ?: [],
            fn (string $file): bool => preg_match(self::LICENSE_FILE_PATTERN, $file) === 1 && is_file($directory.'/'.$file),
        ));
        sort($files);
        if ($files === []) {
            return null;
        }

        $text = trim((string) file_get_contents($directory.'/'.$files[0], length: self::MAX_LICENSE_TEXT_BYTES));

        return $text !== '' ? mb_convert_encoding($text, 'UTF-8', 'UTF-8') : null;
    }

    private static function httpUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $url = preg_replace(['/^git\+/', '/^git:\/\//', '/\.git$/'], ['', 'https://', ''], trim($url));

        return preg_match('#^https?://#i', (string) $url) ? $url : null;
    }
}
