<?php

namespace App\Livewire\Platform;

use App\Livewire\Platform\Concerns\AuthorizesPlatformAdmin;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use App\Models\VulnerabilityScan;
use App\Services\Platform\SourcePolicy;
use Livewire\Component;

class Dashboard extends Component
{
    use AuthorizesPlatformAdmin;

    public function mount(): void
    {
        $this->authorizePlatformOperator();
    }

    public function render()
    {
        $this->authorizePlatformOperator();

        $settings = instanceSettings();
        $applicationStatuses = Application::query()->pluck('status');

        return view('livewire.platform.dashboard', [
            'stats' => [
                'teams' => Team::query()->count(),
                'users' => User::query()->count(),
                'projects' => Project::query()->count(),
                'applications' => $applicationStatuses->count(),
                'running' => $applicationStatuses->filter(fn (?string $status) => str($status)->startsWith('running'))->count(),
                'stopped' => $applicationStatuses->filter(fn (?string $status) => ! str($status)->startsWith('running'))->count(),
                'services' => Service::query()->count(),
                'servers' => Server::query()->count(),
                'blocked_scans' => VulnerabilityScan::query()->where('status', VulnerabilityScan::STATUS_BLOCKED)->where('created_at', '>=', now()->subDays(7))->count(),
            ],
            'guardrails' => [
                'sso_only' => $settings->isSsoOnlyLoginActive(),
                'allowlist' => (bool) $settings->is_source_allowlist_enabled,
                'allowed_git_sources' => SourcePolicy::current()->allowedGitSources(),
                'forgejo' => filled($settings->forgejo_base_url) && filled($settings->forgejo_webhook_secret),
                'trivy' => (bool) $settings->is_trivy_scan_enabled,
                'trivy_blocking' => (bool) $settings->trivy_block_on_findings,
            ],
            'recentDeployments' => ApplicationDeploymentQueue::query()
                ->latest()
                ->limit(10)
                ->get(['id', 'deployment_uuid', 'application_id', 'application_name', 'status', 'commit', 'created_at']),
            'recentScans' => VulnerabilityScan::query()
                ->with('application:id,name,uuid')
                ->latest()
                ->limit(10)
                ->get(),
        ]);
    }
}
