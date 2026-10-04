<?php

namespace App\Livewire\Platform;

use App\Livewire\Platform\Concerns\AuthorizesPlatformAdmin;
use App\Models\ForgejoApp;
use App\Rules\SafeExternalUrl;
use App\Services\Forgejo\ForgejoClient;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use RuntimeException;

/**
 * Git servers platform admins register as sources. Every team can create applications from them;
 * teams cannot add servers themselves.
 *
 * [한국어] Git 소스(GitHub/GitLab/Forgejo) 목록과 Forgejo 서버 추가·삭제 화면입니다. 추가·삭제는 플랫폼 관리자만 할 수 있습니다.
 * 정책 확인: App\Services\Platform\SourcePolicy 와 각 모델의 Policy.
 */
class GitSources extends Component
{
    use AuthorizesPlatformAdmin;

    public string $name = '';

    public string $htmlUrl = '';

    public string $accessToken = '';

    public ?string $rotatingUuid = null;

    public string $newAccessToken = '';

    public function mount(): void
    {
        $this->authorizePlatformAdmin();
    }

    public function add(): void
    {
        $this->authorizePlatformAdmin();
        $this->htmlUrl = self::normalizeServerUrl($this->htmlUrl);

        $this->validate([
            'name' => ['required', 'string', 'max:80', 'unique:forgejo_apps,name'],
            'htmlUrl' => ['required', 'string', 'max:255', 'url:http,https', SafeExternalUrl::forGitSource()],
            'accessToken' => ['required', 'string', 'max:255'],
        ]);

        try {
            $user = (new ForgejoClient($this->htmlUrl, trim($this->accessToken)))->currentUser();
        } catch (\Throwable $e) {
            $this->addError('accessToken', __('Could not connect with this token: :message', ['message' => $e->getMessage()]));

            return;
        }

        ForgejoApp::create([
            'name' => trim($this->name),
            'html_url' => $this->htmlUrl,
            'login' => data_get($user, 'login'),
            'access_token' => trim($this->accessToken),
            'is_system_wide' => true,
            'team_id' => 0,
        ]);

        $this->reset('name', 'htmlUrl', 'accessToken');
        $this->dispatch('success', __('Git source added.'), __('Every team can now select it when creating an application.'));
    }

    public function test(string $uuid): void
    {
        $this->authorizePlatformAdmin();
        $source = ForgejoApp::query()->where('uuid', $uuid)->firstOrFail();

        try {
            $user = ForgejoClient::forSource($source)->currentUser();
            $source->update(['login' => data_get($user, 'login', $source->login)]);
            $this->dispatch('success', __('Connected to :server as :login.', ['server' => e($source->name), 'login' => e((string) data_get($user, 'login', 'unknown'))]));
        } catch (\Throwable $e) {
            $this->dispatch('error', e($e->getMessage()));
        }
    }

    public function startRotating(string $uuid): void
    {
        $this->authorizePlatformAdmin();
        $this->rotatingUuid = ForgejoApp::query()->where('uuid', $uuid)->firstOrFail()->uuid;
        $this->newAccessToken = '';
        $this->resetErrorBag();
    }

    public function cancelRotating(): void
    {
        $this->rotatingUuid = null;
        $this->newAccessToken = '';
    }

    public function rotate(): void
    {
        $this->authorizePlatformAdmin();
        $this->validate(['newAccessToken' => ['required', 'string', 'max:255']]);
        $source = ForgejoApp::query()->where('uuid', (string) $this->rotatingUuid)->firstOrFail();

        try {
            $user = (new ForgejoClient($source->html_url, trim($this->newAccessToken)))->currentUser();
        } catch (\Throwable $e) {
            $this->addError('newAccessToken', __('Could not connect with this token: :message', ['message' => $e->getMessage()]));

            return;
        }

        $source->update(['access_token' => trim($this->newAccessToken), 'login' => data_get($user, 'login', $source->login)]);
        $this->cancelRotating();
        $this->dispatch('success', __('Access token replaced.'));
    }

    public function remove(string $uuid): void
    {
        $this->authorizePlatformAdmin();
        $source = ForgejoApp::query()->where('uuid', $uuid)->firstOrFail();

        try {
            $source->delete();
        } catch (RuntimeException) {
            $this->dispatch('error', __('This source is used by applications. Delete or move them first.'));

            return;
        }

        $this->dispatch('success', __('Git source removed.'));
    }

    /**
     * Accept the server URL or its API URL, without a trailing slash.
     */
    public static function normalizeServerUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');

        return (string) preg_replace('#/api/v1$#', '', $url);
    }

    public function render(): View
    {
        $this->authorizePlatformAdmin();

        return view('livewire.platform.git-sources', [
            'sources' => ForgejoApp::query()->withCount('applications')->orderBy('name')->get(),
            'webhookUrl' => url('/webhooks/source/forgejo/events'),
        ]);
    }
}
