<?php

namespace App\Livewire\Settings;

use App\Models\InstanceSettings;
use App\Models\S3Storage;
use App\Rules\ValidDnsServers;
use App\Rules\ValidIpOrCidr;
use App\Rules\ValidIpOrCidrList;
use App\Support\AdminIpAllowlist;
use App\Support\InstanceSettingsAudit;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Advanced extends Component
{
    use AuthorizesRequests;

    /**
     * Settings that change who can reach the instance or what it sends to the internet.
     * They are only saved by submit() after the administrator re-enters their password.
     *
     * @var array<int, string>
     */
    public const PASSWORD_PROTECTED_FIELDS = [
        'is_registration_enabled',
        'is_api_enabled',
        'allowed_ips',
        'is_mcp_server_enabled',
        'admin_allowed_ips',
        'do_not_track',
        'is_error_reporting_enabled',
        'is_changelog_enabled',
        'is_feedback_forwarding_enabled',
    ];

    public InstanceSettings $settings;

    #[Validate('boolean')]
    public bool $is_registration_enabled;

    #[Validate('boolean')]
    public bool $disable_registration_when_oauth_enabled;

    #[Validate('boolean')]
    public bool $do_not_track;

    #[Validate('boolean')]
    public bool $is_dns_validation_enabled;

    public ?string $custom_dns_servers = null;

    #[Validate('boolean')]
    public bool $is_api_enabled;

    public ?string $allowed_ips = null;

    #[Validate('boolean')]
    public bool $is_wire_navigate_enabled;

    #[Validate('boolean')]
    public bool $is_mcp_server_enabled;

    public ?string $webhook_allowed_internal_hosts = null;

    #[Validate('boolean')]
    public bool $webhook_allow_localhost;

    public ?string $domain_connect_private_key = null;

    public bool $is_error_reporting_enabled = false;

    public bool $is_changelog_enabled = false;

    public bool $is_feedback_forwarding_enabled = false;

    /** IP addresses and CIDR ranges allowed to use administrator pages, one per line or comma separated. */
    public ?string $admin_allowed_ips = null;

    public ?int $audit_log_retention_days = null;

    public string $avatar_storage = 'local';

    public ?string $image_cdn_url = null;

    public array $avatar_storage_options = [];

    public function rules()
    {
        return [
            'is_registration_enabled' => 'boolean',
            'disable_registration_when_oauth_enabled' => 'boolean',
            'do_not_track' => 'boolean',
            'is_dns_validation_enabled' => 'boolean',
            'custom_dns_servers' => ['nullable', 'string', new ValidDnsServers],
            'is_api_enabled' => 'boolean',
            'allowed_ips' => ['nullable', 'string', new ValidIpOrCidr],
            'is_wire_navigate_enabled' => 'boolean',
            'is_mcp_server_enabled' => 'boolean',
            'webhook_allowed_internal_hosts' => 'nullable|string',
            'webhook_allow_localhost' => 'boolean',
            'domain_connect_private_key' => 'nullable|string',
            'image_cdn_url' => 'nullable|url|max:255',
            'is_error_reporting_enabled' => 'boolean',
            'is_changelog_enabled' => 'boolean',
            'is_feedback_forwarding_enabled' => 'boolean',
            'admin_allowed_ips' => ['nullable', 'string', 'max:5000', new ValidIpOrCidrList],
            'audit_log_retention_days' => ['required', 'integer', 'min:'.$this->minimumAuditLogRetentionDays(), 'max:36500'],
        ];
    }

    public function mount()
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }
        $this->settings = instanceSettings();
        $this->custom_dns_servers = $this->settings->custom_dns_servers;
        $this->allowed_ips = $this->settings->allowed_ips;
        $this->do_not_track = $this->settings->do_not_track;
        $this->is_registration_enabled = $this->settings->is_registration_enabled;
        $this->disable_registration_when_oauth_enabled = $this->settings->disable_registration_when_oauth_enabled;
        $this->is_dns_validation_enabled = $this->settings->is_dns_validation_enabled;
        $this->is_api_enabled = $this->settings->is_api_enabled;
        $this->is_error_reporting_enabled = (bool) $this->settings->is_error_reporting_enabled;
        $this->is_changelog_enabled = (bool) $this->settings->is_changelog_enabled;
        $this->is_feedback_forwarding_enabled = (bool) $this->settings->is_feedback_forwarding_enabled;
        $this->admin_allowed_ips = $this->settings->admin_allowed_ips;
        $this->audit_log_retention_days = max(
            (int) ($this->settings->audit_log_retention_days ?? config('security.audit.retention_days')),
            $this->minimumAuditLogRetentionDays(),
        );
        $this->is_wire_navigate_enabled = $this->settings->is_wire_navigate_enabled ?? true;
        $this->is_mcp_server_enabled = $this->settings->is_mcp_server_enabled ?? false;
        $this->webhook_allowed_internal_hosts = collect($this->settings->webhook_allowed_internal_hosts ?? [])->implode(',');
        $this->webhook_allow_localhost = $this->settings->webhook_allow_localhost ?? false;
        // Do not prefill the secret into the form; only update when the admin pastes a new value.
        $this->domain_connect_private_key = null;
        $this->avatar_storage = $this->settings->avatar_storage_type === 's3' && $this->settings->avatar_s3_storage_id
            ? 's3:'.$this->settings->avatar_s3_storage_id
            : 'local';
        $this->image_cdn_url = $this->settings->image_cdn_url;
        $this->avatar_storage_options = [
            ['value' => 'local', 'label' => __('Local storage')],
            ...S3Storage::query()
                ->whereTeamId(0)
                ->where('is_usable', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (S3Storage $storage): array => [
                    'value' => 's3:'.$storage->id,
                    'label' => $storage->name.' (S3)',
                ])->all(),
        ];
    }

    /**
     * Save every field on the page. Changes to PASSWORD_PROTECTED_FIELDS are saved only after the
     * password confirmation modal calls this method again with the administrator's password.
     *
     * @param  mixed  $password  Null when called from the form; a string when called from the confirmation modal.
     */
    public function submit(mixed $password = null): mixed
    {
        try {
            $this->authorize('update', $this->settings);
            $this->validate();

            $this->custom_dns_servers = str($this->custom_dns_servers)->replaceEnd(',', '')->trim();
            $this->custom_dns_servers = str($this->custom_dns_servers)->trim()->explode(',')->map(function ($dns) {
                return str($dns)->trim()->lower();
            })->unique()->implode(',');

            // Handle allowed IPs with subnet support and 0.0.0.0 special case
            $this->allowed_ips = str($this->allowed_ips)->replaceEnd(',', '')->trim();

            // Only validate and clean up if we have IPs and it's not 0.0.0.0 (allow all)
            if (! empty($this->allowed_ips) && ! in_array('0.0.0.0', array_map('trim', explode(',', $this->allowed_ips)))) {
                $invalidEntries = [];
                $validEntries = str($this->allowed_ips)->trim()->explode(',')->map(function ($entry) use (&$invalidEntries) {
                    $entry = str($entry)->trim()->toString();

                    if (empty($entry)) {
                        return null;
                    }

                    // Check if it's valid CIDR notation
                    if (str_contains($entry, '/')) {
                        [$ip, $mask] = explode('/', $entry);
                        $isIpv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
                        $maxMask = $isIpv6 ? 128 : 32;
                        if (filter_var($ip, FILTER_VALIDATE_IP) && is_numeric($mask) && $mask >= 0 && $mask <= $maxMask) {
                            return $entry;
                        }
                        $invalidEntries[] = $entry;

                        return null;
                    }

                    // Check if it's a valid IP address
                    if (filter_var($entry, FILTER_VALIDATE_IP)) {
                        return $entry;
                    }

                    $invalidEntries[] = $entry;

                    return null;
                })->filter()->values()->all();

                if (! empty($invalidEntries)) {
                    $this->dispatch('error', __('Invalid IP addresses or subnets: :entries', ['entries' => implode(', ', $invalidEntries)]));

                    return null;
                }

                if (empty($validEntries)) {
                    $this->dispatch('error', __('No valid IP addresses or subnets provided'));

                    return null;
                }

                $validEntries = deduplicateAllowlist($validEntries);

                $this->allowed_ips = implode(',', $validEntries);
            }

            $webhookAllowedInternalHosts = $this->normalizeWebhookAllowedInternalHosts();
            if ($webhookAllowedInternalHosts === false) {
                return null;
            }

            $adminAllowedIps = parseNetworkRanges($this->admin_allowed_ips);
            $this->admin_allowed_ips = $adminAllowedIps === [] ? null : implode("\n", $adminAllowedIps);
            if (! $this->adminAllowlistKeepsCurrentRequest($adminAllowedIps)) {
                $message = __('Your current IP address (:ip) is not in the administrator allowlist. Saving it would lock you out, so it was not saved.', ['ip' => (string) request()->ip()]);
                $this->addError('admin_allowed_ips', $message);
                $this->dispatch('error', $message);

                return null;
            }

            $this->fillGeneralSettings($webhookAllowedInternalHosts);
            $this->fillSecuritySettings();
            if (isCloud() && filled($this->domain_connect_private_key)) {
                $this->settings->domain_connect_private_key = $this->normalizeDomainConnectPrivateKey($this->domain_connect_private_key);
            }
            $this->saveAvatarStorageSetting();

            $protectedChanges = array_values(array_intersect(
                array_keys(InstanceSettingsAudit::pendingChanges($this->settings)),
                self::PASSWORD_PROTECTED_FIELDS,
            ));
            if ($protectedChanges !== []) {
                if ($password === null) {
                    // Nothing is saved until the administrator confirms with their password.
                    $this->settings->refresh();
                    $this->dispatch('confirm-security-settings');

                    return null;
                }

                if (! verifyPasswordConfirmation($password, $this)) {
                    $this->settings->refresh();
                    auditLog(InstanceSettingsAudit::EVENT, [
                        'fields' => $protectedChanges,
                        'result' => 'denied',
                        'reason' => 'password_confirmation_failed',
                    ], 'warning');

                    return __('The provided password is incorrect.');
                }
            }

            InstanceSettingsAudit::save($this->settings);
            $this->domain_connect_private_key = null;
            $this->dispatch('success', __('Settings updated!'));

            return true;
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    /**
     * Save the settings that apply immediately when a listbox changes. Password-protected
     * settings are never written here; they wait for submit() and the password confirmation.
     */
    public function instantSave()
    {
        try {
            $this->authorize('update', $this->settings);
            $this->fillGeneralSettings(null);
            $this->saveAvatarStorageSetting();
            InstanceSettingsAudit::save($this->settings);
            $this->dispatch('success', __('Settings updated!'));
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    /**
     * @param  array<int, string>|null  $webhookAllowedInternalHosts
     */
    private function fillGeneralSettings(?array $webhookAllowedInternalHosts): void
    {
        $this->settings->disable_registration_when_oauth_enabled = $this->disable_registration_when_oauth_enabled;
        $this->settings->is_dns_validation_enabled = $this->is_dns_validation_enabled;
        $this->settings->custom_dns_servers = $this->custom_dns_servers;
        $this->settings->is_wire_navigate_enabled = $this->is_wire_navigate_enabled;
        $this->settings->webhook_allowed_internal_hosts = $webhookAllowedInternalHosts ?? $this->settings->webhook_allowed_internal_hosts ?? [];
        $this->settings->webhook_allow_localhost = $this->webhook_allow_localhost;
        $this->settings->image_cdn_url = filled($this->image_cdn_url) ? rtrim($this->image_cdn_url, '/') : null;
        $this->settings->audit_log_retention_days = min(max((int) $this->audit_log_retention_days, $this->minimumAuditLogRetentionDays()), 36500);
    }

    private function fillSecuritySettings(): void
    {
        $this->settings->is_registration_enabled = $this->is_registration_enabled;
        $this->settings->is_api_enabled = $this->is_api_enabled;
        $this->settings->allowed_ips = $this->allowed_ips;
        $this->settings->is_mcp_server_enabled = $this->is_mcp_server_enabled;
        $this->settings->admin_allowed_ips = $this->admin_allowed_ips;
        $this->settings->do_not_track = $this->do_not_track;
        $this->settings->is_error_reporting_enabled = $this->is_error_reporting_enabled;
        $this->settings->is_changelog_enabled = $this->is_changelog_enabled;
        $this->settings->is_feedback_forwarding_enabled = $this->is_feedback_forwarding_enabled;
    }

    /**
     * A changed, non-empty administrator allowlist must still contain the address of the request saving it.
     *
     * @param  array<int, string>  $ranges
     */
    private function adminAllowlistKeepsCurrentRequest(array $ranges): bool
    {
        if ($ranges === []) {
            return true;
        }

        if (parseNetworkRanges($this->settings->getOriginal('admin_allowed_ips')) === $ranges) {
            return true;
        }

        return AdminIpAllowlist::allows(AdminIpAllowlist::clientIp(request()), AdminIpAllowlist::ranges(implode("\n", $ranges)));
    }

    private function minimumAuditLogRetentionDays(): int
    {
        return (int) config('security.audit.min_retention_days', 365);
    }

    private function saveAvatarStorageSetting(): void
    {
        if ($this->avatar_storage === 'local') {
            $this->settings->avatar_storage_type = 'local';
            $this->settings->avatar_s3_storage_id = null;

            return;
        }

        $storageId = (int) str($this->avatar_storage)->after('s3:')->value();
        $storage = S3Storage::query()
            ->whereTeamId(0)
            ->where('is_usable', true)
            ->find($storageId);

        if (! $storage || $this->avatar_storage !== 's3:'.$storage->id) {
            throw new \InvalidArgumentException(__('The selected avatar storage is not available.'));
        }

        $this->settings->avatar_storage_type = 's3';
        $this->settings->avatar_s3_storage_id = $storage->id;
    }

    public function clearDomainConnectPrivateKey(): void
    {
        try {
            if (! isCloud()) {
                return;
            }
            $this->authorize('update', $this->settings);
            $this->settings->domain_connect_private_key = null;
            InstanceSettingsAudit::save($this->settings);
            $this->domain_connect_private_key = null;
            $this->dispatch('success', __('Domain Connect private key removed.'));
        } catch (\Exception $e) {
            handleError($e, $this);
        }
    }

    private function normalizeDomainConnectPrivateKey(string $key): string
    {
        $key = str_replace(["\r\n", "\r"], "\n", trim($key));
        if (! str_contains($key, "\n") && str_contains($key, '\\n')) {
            $key = str_replace('\\n', "\n", $key);
        }

        return $key;
    }

    /**
     * @return array<int, string>|false
     */
    private function normalizeWebhookAllowedInternalHosts(): array|false
    {
        $entries = collect(preg_split('/[,\r\n]+/', $this->webhook_allowed_internal_hosts ?? '') ?: [])
            ->map(fn (string $entry): string => rtrim(strtolower(trim($entry)), '.'))
            ->filter()
            ->unique()
            ->values();

        $invalidEntries = $entries->reject(fn (string $entry): bool => $this->isValidWebhookAllowlistEntry($entry));
        if ($invalidEntries->isNotEmpty()) {
            $this->dispatch('error', __('Invalid webhook internal allowlist entries: :entries', ['entries' => $invalidEntries->implode(', ')]));

            return false;
        }

        $this->webhook_allowed_internal_hosts = $entries->implode(',');

        return $entries->all();
    }

    private function isValidWebhookAllowlistEntry(string $entry): bool
    {
        if (filter_var($entry, FILTER_VALIDATE_IP)) {
            return true;
        }

        if (str_contains($entry, '/')) {
            [$ip, $mask] = array_pad(explode('/', $entry, 2), 2, null);
            $isIpv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            $maxMask = $isIpv6 ? 128 : 32;

            return filter_var($ip, FILTER_VALIDATE_IP) !== false
                && is_numeric($mask)
                && (int) $mask >= 0
                && (int) $mask <= $maxMask;
        }

        return filter_var($entry, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    /**
     * Password-protected fields whose form value differs from the saved value, e.g. after the
     * administrator closed the password confirmation without confirming.
     *
     * @return array<int, string>
     */
    protected function pendingSecurityChanges(): array
    {
        return array_values(array_filter(self::PASSWORD_PROTECTED_FIELDS, function (string $field): bool {
            $saved = $this->settings->getAttribute($field);
            $current = $this->{$field};

            if (is_bool($current)) {
                return $current !== (bool) $saved;
            }

            return trim((string) $current) !== trim((string) $saved);
        }));
    }

    public function render()
    {
        return view('livewire.settings.advanced', [
            'currentIp' => request()->ip(),
            'minimumAuditLogRetentionDays' => $this->minimumAuditLogRetentionDays(),
            'hasPendingSecurityChanges' => $this->pendingSecurityChanges() !== [],
        ]);
    }
}
