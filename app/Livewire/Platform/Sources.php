<?php

namespace App\Livewire\Platform;

use App\Livewire\Platform\Concerns\AuthorizesPlatformAdmin;
use App\Services\Forgejo\ForgejoClient;
use App\Services\Platform\SourcePolicy;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Allowed git sources / container registries and the company Forgejo integration.
 */
class Sources extends Component
{
    use AuthorizesPlatformAdmin;

    public bool $isSourceAllowlistEnabled = false;

    public ?string $allowedGitSources = null;

    public ?string $allowedDockerRegistries = null;

    public ?string $forgejoBaseUrl = null;

    public ?string $forgejoBotToken = null;

    public bool $hasForgejoBotToken = false;

    public bool $hasForgejoWebhookSecret = false;

    public ?string $revealedWebhookSecret = null;

    public function mount(): void
    {
        $this->authorizePlatformAdmin();
        $settings = instanceSettings();

        $this->isSourceAllowlistEnabled = (bool) $settings->is_source_allowlist_enabled;
        $this->allowedGitSources = $settings->allowed_git_sources;
        $this->allowedDockerRegistries = $settings->allowed_docker_registries;
        $this->forgejoBaseUrl = $settings->forgejo_base_url;
        $this->hasForgejoBotToken = filled($settings->forgejo_bot_token);
        $this->hasForgejoWebhookSecret = filled($settings->forgejo_webhook_secret);
    }

    public function saveAllowlist(): void
    {
        $this->authorizePlatformAdmin();
        $this->validate([
            'isSourceAllowlistEnabled' => ['boolean'],
            'allowedGitSources' => ['nullable', 'string', 'max:10000'],
            'allowedDockerRegistries' => ['nullable', 'string', 'max:10000'],
        ]);

        $settings = instanceSettings();
        $settings->update([
            'is_source_allowlist_enabled' => $this->isSourceAllowlistEnabled,
            'allowed_git_sources' => $this->cleanList($this->allowedGitSources),
            'allowed_docker_registries' => $this->cleanList($this->allowedDockerRegistries),
        ]);

        if ($this->isSourceAllowlistEnabled && SourcePolicy::current()->allowedGitSources()->isEmpty()) {
            $this->dispatch('warning', 'The allow-list is enabled but empty, so every git deployment is blocked.');
        } else {
            $this->dispatch('success', 'Allowed sources saved.');
        }
        auditLog('platform.sources.allowlist_updated', ['enabled' => $this->isSourceAllowlistEnabled]);
    }

    public function saveForgejo(): void
    {
        $this->authorizePlatformAdmin();
        $this->validate([
            'forgejoBaseUrl' => ['nullable', 'url:http,https', 'max:255'],
            'forgejoBotToken' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = ['forgejo_base_url' => filled($this->forgejoBaseUrl) ? rtrim(trim($this->forgejoBaseUrl), '/') : null];
        if (filled($this->forgejoBotToken)) {
            $payload['forgejo_bot_token'] = trim($this->forgejoBotToken);
        }
        instanceSettings()->update($payload);

        $this->forgejoBotToken = null;
        $this->hasForgejoBotToken = filled(instanceSettings()->forgejo_bot_token);
        auditLog('platform.sources.forgejo_updated', ['base_url' => $payload['forgejo_base_url']]);
        $this->dispatch('success', 'Forgejo settings saved.');
    }

    public function testForgejo(): void
    {
        $this->authorizePlatformAdmin();

        try {
            $client = ForgejoClient::fromSettings(instanceSettings());
            if ($client === null) {
                $this->dispatch('error', 'Save the Forgejo base URL first.');

                return;
            }
            $user = $client->currentUser();
            $this->dispatch('success', 'Connected to Forgejo as '.e((string) data_get($user, 'login', 'unknown')).'.');
        } catch (\Throwable $e) {
            $this->dispatch('error', e($e->getMessage()));
        }
    }

    public function generateWebhookSecret(): void
    {
        $this->authorizePlatformAdmin();

        $secret = Str::random(48);
        instanceSettings()->update(['forgejo_webhook_secret' => $secret]);
        $this->hasForgejoWebhookSecret = true;
        $this->revealedWebhookSecret = $secret;

        auditLog('platform.sources.forgejo_webhook_secret_rotated', []);
        $this->dispatch('success', 'New webhook secret generated. Update the Forgejo webhook now.');
    }

    public function removeBotToken(): void
    {
        $this->authorizePlatformAdmin();
        instanceSettings()->update(['forgejo_bot_token' => null]);
        $this->hasForgejoBotToken = false;
        $this->dispatch('success', 'Bot token removed.');
    }

    private function cleanList(?string $value): ?string
    {
        $lines = str((string) $value)
            ->replace(',', "\n")
            ->explode("\n")
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->unique()
            ->implode("\n");

        return $lines === '' ? null : $lines;
    }

    public function render()
    {
        $this->authorizePlatformAdmin();
        $policy = SourcePolicy::current();

        return view('livewire.platform.sources', [
            'normalizedGitSources' => $policy->allowedGitSources(),
            'normalizedDockerRegistries' => $policy->allowedDockerRegistries(),
            'webhookUrl' => url('/webhooks/source/forgejo/events'),
        ]);
    }
}
