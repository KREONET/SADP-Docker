<?php

namespace App\Livewire\Platform;

use App\Jobs\ReportVulnerabilitiesToRepositoryJob;
use App\Livewire\Platform\Concerns\AuthorizesPlatformAdmin;
use App\Livewire\Platform\Concerns\ManagesUserAccounts;
use App\Models\OauthSetting;
use App\Models\User;
use App\Models\VulnerabilityScan;
use App\Services\Security\TrivyScanner;
use App\Support\InstanceSettingsAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Trivy vulnerability scanning, SSO-only login, the scan history, and user account states.
 */
class Security extends Component
{
    use AuthorizesPlatformAdmin;
    use ManagesUserAccounts;

    /**
     * Account state filters for the user list.
     */
    public const ACCOUNT_FILTERS = ['', 'locked', 'deactivated'];

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

    #[Url(as: 'users', except: '')]
    public string $accountSearch = '';

    #[Url(as: 'account', except: '')]
    public string $accountFilter = '';

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
        $this->dispatch('success', __('Vulnerability scanning settings saved.'));
    }

    /**
     * Save the login policy. Changing SSO-only login is saved only after the password
     * confirmation modal calls this method again with the administrator's password.
     *
     * @param  mixed  $password  Null when called from the form; a string when called from the confirmation modal.
     */
    public function saveLogin(mixed $password = null): bool|string|null
    {
        $this->authorizePlatformAdmin();
        $this->validate(['isSsoOnlyLoginEnabled' => ['boolean']]);

        if ($this->isSsoOnlyLoginEnabled && ! OauthSetting::where('enabled', true)->exists()) {
            $this->isSsoOnlyLoginEnabled = false;
            $this->dispatch('error', __('Enable an OIDC/OAuth provider in Settings → Authentication before requiring SSO.'));

            return null;
        }

        $settings = instanceSettings();
        $settings->is_sso_only_login_enabled = $this->isSsoOnlyLoginEnabled;

        if (InstanceSettingsAudit::pendingChanges($settings) === []) {
            $settings->refresh();
            $this->dispatch('success', __('Login policy saved.'));

            return true;
        }

        if ($password === null) {
            // Nothing is saved until the administrator confirms with their password.
            $settings->refresh();
            $this->dispatch('confirm-login-policy');

            return null;
        }

        if (! verifyPasswordConfirmation($password, $this)) {
            $settings->refresh();
            auditLog(InstanceSettingsAudit::EVENT, [
                'fields' => ['is_sso_only_login_enabled'],
                'result' => 'denied',
                'reason' => 'password_confirmation_failed',
            ], 'warning');

            return __('The provided password is incorrect.');
        }

        InstanceSettingsAudit::save($settings);
        auditLog('platform.security.sso_only_updated', ['enabled' => $this->isSsoOnlyLoginEnabled]);
        $this->dispatch('success', __('Login policy saved.'));

        return true;
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
            $this->dispatch('error', __('This scan has no findings to report.'));

            return;
        }

        ReportVulnerabilitiesToRepositoryJob::dispatch($scan);
        $this->dispatch('success', __('The report is being posted to the repository.'));
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
            'accounts' => $this->accounts(),
        ]);
    }

    /**
     * @return Collection<int, User>
     */
    private function accounts(): Collection
    {
        $search = trim($this->accountSearch);
        $filter = in_array($this->accountFilter, self::ACCOUNT_FILTERS, true) ? $this->accountFilter : '';

        return User::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.mb_strtolower($search).'%';
                $query->where(fn (Builder $query) => $query
                    ->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like]));
            })
            ->when($filter === 'locked', fn (Builder $query) => $query->where('locked_until', '>', now()))
            ->when($filter === 'deactivated', fn (Builder $query) => $query->whereNotNull('deactivated_at'))
            ->orderBy('id')
            ->limit(50)
            ->get();
    }
}
