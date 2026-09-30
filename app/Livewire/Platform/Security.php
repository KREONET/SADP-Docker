<?php

namespace App\Livewire\Platform;

use App\Jobs\ReportVulnerabilitiesToRepositoryJob;
use App\Livewire\Platform\Concerns\AuthorizesPlatformAdmin;
use App\Models\OauthSetting;
use App\Models\VulnerabilityScan;
use App\Services\Security\TrivyScanner;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Trivy vulnerability scanning, SSO-only login, and the scan history.
 */
class Security extends Component
{
    use AuthorizesPlatformAdmin;

    public bool $isTrivyScanEnabled = false;

    public string $trivyImage = 'aquasec/trivy:latest';

    /** @var array<int, string> */
    public array $trivySeverities = ['HIGH', 'CRITICAL'];

    public bool $trivyIgnoreUnfixed = false;

    public bool $trivyBlockOnFindings = true;

    public bool $trivyReportToRepository = true;

    public bool $isSsoOnlyLoginEnabled = false;

    #[Url(except: '')]
    public string $status = '';

    public ?string $expandedScanUuid = null;

    public function mount(): void
    {
        $this->authorizePlatformAdmin();
        $settings = instanceSettings();

        $this->isTrivyScanEnabled = (bool) $settings->is_trivy_scan_enabled;
        $this->trivyImage = $settings->trivy_image ?: 'aquasec/trivy:latest';
        $this->trivySeverities = TrivyScanner::parseSeverities($settings->trivy_severities);
        $this->trivyIgnoreUnfixed = (bool) $settings->trivy_ignore_unfixed;
        $this->trivyBlockOnFindings = (bool) $settings->trivy_block_on_findings;
        $this->trivyReportToRepository = (bool) $settings->trivy_report_to_repository;
        $this->isSsoOnlyLoginEnabled = (bool) $settings->is_sso_only_login_enabled;
    }

    public function saveTrivy(): void
    {
        $this->authorizePlatformAdmin();
        $this->validate([
            'isTrivyScanEnabled' => ['boolean'],
            'trivyImage' => ['required', 'string', 'max:255', 'regex:'.TrivyScanner::IMAGE_REFERENCE_PATTERN],
            'trivySeverities' => ['required', 'array', 'min:1'],
            'trivySeverities.*' => ['in:'.implode(',', TrivyScanner::SEVERITIES)],
            'trivyIgnoreUnfixed' => ['boolean'],
            'trivyBlockOnFindings' => ['boolean'],
            'trivyReportToRepository' => ['boolean'],
        ]);

        instanceSettings()->update([
            'is_trivy_scan_enabled' => $this->isTrivyScanEnabled,
            'trivy_image' => trim($this->trivyImage),
            'trivy_severities' => implode(',', TrivyScanner::parseSeverities(implode(',', $this->trivySeverities))),
            'trivy_ignore_unfixed' => $this->trivyIgnoreUnfixed,
            'trivy_block_on_findings' => $this->trivyBlockOnFindings,
            'trivy_report_to_repository' => $this->trivyReportToRepository,
        ]);

        auditLog('platform.security.trivy_updated', ['enabled' => $this->isTrivyScanEnabled, 'blocking' => $this->trivyBlockOnFindings]);
        $this->dispatch('success', 'Vulnerability scanning settings saved.');
    }

    public function saveLogin(): void
    {
        $this->authorizePlatformAdmin();
        $this->validate(['isSsoOnlyLoginEnabled' => ['boolean']]);

        if ($this->isSsoOnlyLoginEnabled && ! OauthSetting::where('enabled', true)->exists()) {
            $this->isSsoOnlyLoginEnabled = false;
            $this->dispatch('error', 'Enable an OIDC/OAuth provider in Settings → Authentication before requiring SSO.');

            return;
        }

        instanceSettings()->update(['is_sso_only_login_enabled' => $this->isSsoOnlyLoginEnabled]);
        auditLog('platform.security.sso_only_updated', ['enabled' => $this->isSsoOnlyLoginEnabled]);
        $this->dispatch('success', 'Login policy saved.');
    }

    public function toggleScan(string $uuid): void
    {
        $this->authorizePlatformAdmin();
        $this->expandedScanUuid = $this->expandedScanUuid === $uuid ? null : $uuid;
    }

    public function resendReport(string $uuid): void
    {
        $this->authorizePlatformAdmin();
        $scan = VulnerabilityScan::query()->where('uuid', $uuid)->firstOrFail();
        if ($scan->totalCount() === 0) {
            $this->dispatch('error', 'This scan has no findings to report.');

            return;
        }

        ReportVulnerabilitiesToRepositoryJob::dispatch($scan);
        $this->dispatch('success', 'The report is being posted to the repository.');
    }

    public function render()
    {
        $this->authorizePlatformAdmin();

        return view('livewire.platform.security', [
            'scans' => VulnerabilityScan::query()
                ->with('application:id,uuid,name')
                ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
                ->latest()
                ->limit(50)
                ->get(),
            'enabledProviders' => OauthSetting::where('enabled', true)->get(),
        ]);
    }
}
