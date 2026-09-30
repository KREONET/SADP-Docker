<?php

namespace App\Services\Platform;

use App\Exceptions\DisallowedSourceException;
use App\Models\Application;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Enforces the administrator-defined allow-list of git sources and container registries.
 *
 * Entries and candidate URLs are compared as "host/path" strings (lowercase, without
 * scheme, credentials, port or ".git" suffix), and an entry matches on path-segment
 * boundaries: "git.example.com/team" allows "git.example.com/team/app" but not
 * "git.example.com/teamx/app".
 */
class SourcePolicy
{
    public function __construct(private readonly InstanceSettings $settings) {}

    public static function current(): self
    {
        try {
            return new self(instanceSettings());
        } catch (ModelNotFoundException) {
            return new self(new InstanceSettings);
        }
    }

    public function isEnforced(): bool
    {
        return (bool) $this->settings->is_source_allowlist_enabled;
    }

    /**
     * @return Collection<int, string>
     */
    public function allowedGitSources(): Collection
    {
        return self::parseEntries($this->settings->allowed_git_sources, fn (string $entry) => self::normalizeGitRepository($entry));
    }

    /**
     * @return Collection<int, string>
     */
    public function allowedDockerRegistries(): Collection
    {
        return self::parseEntries($this->settings->allowed_docker_registries, fn (string $entry) => self::normalizeRegistryEntry($entry));
    }

    public function isGitRepositoryAllowed(?string $repositoryUrl): bool
    {
        if (! $this->isEnforced()) {
            return true;
        }

        $normalized = self::normalizeGitRepository((string) $repositoryUrl);
        if ($normalized === null) {
            return false;
        }

        return $this->allowedGitSources()->contains(fn (string $entry) => self::matchesPrefix($normalized, $entry));
    }

    public function isDockerImageAllowed(?string $image): bool
    {
        if (! $this->isEnforced()) {
            return true;
        }

        $normalized = self::normalizeDockerImage((string) $image);
        if ($normalized === null) {
            return false;
        }

        return $this->allowedDockerRegistries()->contains(fn (string $entry) => self::matchesPrefix($normalized, $entry));
    }

    /**
     * @throws DisallowedSourceException
     */
    public function assertApplicationAllowed(Application $application): void
    {
        if (! $this->isEnforced()) {
            return;
        }

        if ($application->build_pack === 'dockerimage') {
            $image = (string) $application->docker_registry_image_name;
            if (! $this->isDockerImageAllowed($image)) {
                throw DisallowedSourceException::forDockerImage($image);
            }

            return;
        }

        $repositoryUrl = self::applicationRepositoryUrl($application);
        if ($repositoryUrl === null) {
            return;
        }

        if (! $this->isGitRepositoryAllowed($repositoryUrl)) {
            throw DisallowedSourceException::forGitRepository($repositoryUrl);
        }
    }

    /**
     * The URL the deployment will clone from, or null for applications that do not clone a repository.
     */
    public static function applicationRepositoryUrl(Application $application): ?string
    {
        if ($application->build_pack === 'dockerimage' || filled($application->dockerfile)) {
            return null;
        }

        $repository = trim((string) $application->git_repository);
        if ($repository === '') {
            return null;
        }

        if (str_contains($repository, '://') || parseScpStyleGitUrl($repository) !== null) {
            return $repository;
        }

        if (in_array($application->source_type, [GithubApp::class, GitlabApp::class], true)) {
            $htmlUrl = data_get($application->source, 'html_url');
            if (filled($htmlUrl)) {
                return rtrim($htmlUrl, '/').'/'.ltrim($repository, '/');
            }
        }

        return $repository;
    }

    /**
     * Normalize https://, ssh://, scp-style (git@host:owner/repo) and bare host/path inputs to "host/path".
     */
    public static function normalizeGitRepository(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (str_contains($url, '://')) {
            $parts = parse_url($url);
            $host = data_get($parts, 'host');
            $path = (string) data_get($parts, 'path', '');
        } elseif (($scp = parseScpStyleGitUrl($url)) !== null) {
            $host = $scp['host'];
            $path = $scp['path'];
        } else {
            $segments = explode('/', ltrim($url, '/'), 2);
            $host = $segments[0];
            $path = $segments[1] ?? '';
        }

        if (! is_string($host) || $host === '' || ! preg_match('/\A[A-Za-z0-9.-]+(?::\d+)?\z/', $host)) {
            return null;
        }

        $host = strtolower(preg_replace('/:\d+\z/', '', $host));
        $path = strtolower(trim($path, '/'));
        $path = preg_replace('/\.git\z/', '', $path) ?? $path;

        if (str_contains($path, '..')) {
            return null;
        }

        return $path === '' ? $host : "{$host}/{$path}";
    }

    /**
     * Normalize a Docker image reference to "registry/repository" without tag or digest.
     * Images without an explicit registry resolve to docker.io, and single-segment
     * names resolve to docker.io/library, matching Docker's own resolution.
     */
    public static function normalizeDockerImage(string $image): ?string
    {
        $image = strtolower(trim($image));
        if ($image === '' || str_contains($image, '..') || preg_match('/\s/', $image)) {
            return null;
        }

        $image = preg_replace('/@.*\z/', '', $image) ?? $image;
        $segments = explode('/', $image);
        $last = array_pop($segments);
        $last = preg_replace('/:[^:\/]*\z/', '', $last) ?? $last;
        $segments[] = $last;

        $first = $segments[0];
        $hasRegistry = count($segments) > 1 && (str_contains($first, '.') || str_contains($first, ':') || $first === 'localhost');

        if ($hasRegistry) {
            $segments[0] = preg_replace('/:\d+\z/', '', $first);
        } elseif (count($segments) === 1) {
            array_unshift($segments, 'docker.io', 'library');
        } else {
            array_unshift($segments, 'docker.io');
        }

        if ($segments[0] === 'index.docker.io' || $segments[0] === 'registry-1.docker.io') {
            $segments[0] = 'docker.io';
        }

        return implode('/', array_filter($segments, fn (string $segment) => $segment !== ''));
    }

    private static function normalizeRegistryEntry(string $entry): ?string
    {
        $entry = strtolower(trim($entry));
        if (str_contains($entry, '://')) {
            $entry = (string) preg_replace('#\A[a-z][a-z0-9+.-]*://#', '', $entry);
        }
        $entry = trim($entry, '/');
        if ($entry === '' || str_contains($entry, '..')) {
            return null;
        }

        $segments = explode('/', $entry);
        $segments[0] = preg_replace('/:\d+\z/', '', $segments[0]);
        if (in_array($segments[0], ['index.docker.io', 'registry-1.docker.io'], true)) {
            $segments[0] = 'docker.io';
        }

        return implode('/', $segments);
    }

    private static function matchesPrefix(string $candidate, string $entry): bool
    {
        return $candidate === $entry || str_starts_with($candidate, rtrim($entry, '/').'/');
    }

    /**
     * @param  callable(string): ?string  $normalizer
     * @return Collection<int, string>
     */
    private static function parseEntries(?string $raw, callable $normalizer): Collection
    {
        return str((string) $raw)
            ->replace(',', "\n")
            ->explode("\n")
            ->map(fn (string $entry) => trim($entry))
            ->reject(fn (string $entry) => $entry === '' || str_starts_with($entry, '#'))
            ->map($normalizer)
            ->filter()
            ->unique()
            ->values();
    }
}
