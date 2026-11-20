<?php

namespace App\Services\Forgejo;

use App\Models\ForgejoApp;
use App\Models\InstanceSettings;
use App\Services\Platform\SourcePolicy;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal client for the Forgejo (Gitea-compatible) REST API, authenticated as the
 * administrator-configured bot account.
 *
 * [한국어] Forgejo REST API 를 부르는 작은 클라이언트입니다. 관리자가 등록한 봇/서비스 계정 토큰으로 인증합니다.
 * 필요한 토큰 권한: read:user, read:repository (이슈 작성은 write:issue).
 */
class ForgejoClient
{
    /** Forgejo caps pages at 50 items by default. */
    public const PAGE_SIZE = 50;

    private readonly string $baseUrl;

    public function __construct(string $baseUrl, private readonly ?string $token)
    {
        $this->baseUrl = rtrim(trim($baseUrl), '/');
    }

    public static function fromSettings(InstanceSettings $settings): ?self
    {
        if (blank($settings->forgejo_base_url)) {
            return null;
        }

        return new self($settings->forgejo_base_url, $settings->forgejo_bot_token);
    }

    public static function forSource(ForgejoApp $source): self
    {
        return new self($source->html_url, $source->access_token);
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function host(): ?string
    {
        $host = parse_url($this->baseUrl, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : null;
    }

    public function hasToken(): bool
    {
        return filled($this->token);
    }

    /**
     * Resolve "owner/name" for a repository URL hosted on this Forgejo instance.
     *
     * @return array{owner: string, name: string}|null
     */
    public function repositoryFromUrl(?string $repositoryUrl): ?array
    {
        $normalized = SourcePolicy::normalizeGitRepository((string) $repositoryUrl);
        $host = $this->host();
        if ($normalized === null || $host === null) {
            return null;
        }

        $basePath = trim((string) parse_url($this->baseUrl, PHP_URL_PATH), '/');
        $prefix = $basePath === '' ? $host : strtolower("{$host}/{$basePath}");
        if (! str_starts_with($normalized, $prefix.'/')) {
            return null;
        }

        $segments = explode('/', substr($normalized, strlen($prefix) + 1));
        if (count($segments) !== 2 || in_array('', $segments, true)) {
            return null;
        }

        return ['owner' => $segments[0], 'name' => $segments[1]];
    }

    /**
     * @return array<string, mixed> The authenticated bot user.
     */
    public function currentUser(): array
    {
        $response = $this->request()->get('/user');
        if ($response->failed()) {
            throw new RuntimeException("Forgejo API request failed ({$response->status()}). Check the base URL and bot token.");
        }

        return $response->json() ?? [];
    }

    /**
     * One page of the repositories the token's user can read (own, organization and shared).
     *
     * @return array{repositories: list<array{id: int, name: string, full_name: string, default_branch: string, private: bool, html_url: ?string}>, has_more: bool}
     */
    public function repositories(int $page = 1, int $limit = self::PAGE_SIZE): array
    {
        $response = $this->request()->get('/user/repos', ['page' => $page, 'limit' => $limit]);
        $this->assertSuccessful($response, 'load the repositories');

        $items = collect($response->json() ?? [])->filter(fn ($repository) => is_array($repository) && filled(data_get($repository, 'full_name')));

        return [
            'repositories' => $items->map(fn (array $repository): array => [
                'id' => (int) data_get($repository, 'id'),
                'name' => (string) data_get($repository, 'name'),
                'full_name' => (string) data_get($repository, 'full_name'),
                'default_branch' => (string) (data_get($repository, 'default_branch') ?: 'main'),
                'private' => (bool) data_get($repository, 'private', false),
                'html_url' => data_get($repository, 'html_url'),
            ])->values()->all(),
            'has_more' => count($response->json() ?? []) >= $limit,
        ];
    }

    /**
     * One page of branches of a repository.
     *
     * @return list<array{name: string}>
     */
    public function branches(string $owner, string $name, int $page = 1, int $limit = self::PAGE_SIZE): array
    {
        $response = $this->request()->get('/repos/'.rawurlencode($owner).'/'.rawurlencode($name).'/branches', ['page' => $page, 'limit' => $limit]);
        $this->assertSuccessful($response, 'load the branches');

        return collect($response->json() ?? [])
            ->filter(fn ($branch) => is_array($branch) && filled(data_get($branch, 'name')))
            ->map(fn (array $branch): array => ['name' => (string) data_get($branch, 'name')])
            ->values()
            ->all();
    }

    /**
     * Open an issue, or comment on the open issue with the same title so repeated
     * failing deployments do not flood the repository. Returns the issue or comment URL.
     */
    public function reportIssue(string $owner, string $name, string $title, string $body): string
    {
        $repositoryPath = '/repos/'.rawurlencode($owner).'/'.rawurlencode($name);

        $existing = $this->request()->get("{$repositoryPath}/issues", [
            'state' => 'open',
            'type' => 'issues',
            'q' => $title,
            'limit' => 50,
        ]);

        $issue = $existing->successful()
            ? collect($existing->json() ?? [])->first(fn ($issue) => is_array($issue) && data_get($issue, 'title') === $title)
            : null;

        if ($issue !== null) {
            $response = $this->request()->post("{$repositoryPath}/issues/".(int) data_get($issue, 'number').'/comments', [
                'body' => $body,
            ]);
        } else {
            $response = $this->request()->post("{$repositoryPath}/issues", [
                'title' => $title,
                'body' => $body,
            ]);
        }

        if ($response->failed()) {
            throw new RuntimeException("Forgejo rejected the vulnerability report ({$response->status()}): ".str($response->body())->limit(300));
        }

        return (string) (data_get($response->json(), 'html_url') ?? data_get($issue, 'html_url', ''));
    }

    private function assertSuccessful(Response $response, string $action): void
    {
        if ($response->successful()) {
            return;
        }

        $reason = match ($response->status()) {
            401, 403 => 'The access token is invalid or lacks the read:user and read:repository scopes.',
            404 => 'Not found. Check the server URL.',
            default => 'Forgejo answered with HTTP '.$response->status().'.',
        };

        throw new RuntimeException("Could not {$action}. {$reason}");
    }

    private function request(): PendingRequest
    {
        if (! $this->hasToken()) {
            throw new RuntimeException('The Forgejo bot token is not configured.');
        }

        return Http::GitSource($this->baseUrl)
            ->baseUrl($this->baseUrl.'/api/v1')
            ->withToken((string) $this->token, 'token')
            ->acceptJson()
            ->timeout(20);
    }
}
