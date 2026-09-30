<?php

namespace App\Services\Forgejo;

use App\Models\InstanceSettings;
use App\Services\Platform\SourcePolicy;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal client for the Forgejo (Gitea-compatible) REST API, authenticated as the
 * administrator-configured bot account.
 */
class ForgejoClient
{
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
