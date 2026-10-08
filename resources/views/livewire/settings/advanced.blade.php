<div>
    <x-slot:title>
        {{ __('Advanced Settings') }} | {{ brand_name() }}
    </x-slot>

    <x-settings.layout>
        <form wire:submit="submit" class="application-settings-form flex min-w-0 flex-col gap-6">
            {{-- Scope dirty tracking to fields that need an explicit Save. Instant-save
                 listboxes (navigation, DNS validation, …) update the snapshot on the server
                 immediately; without wire:target they briefly flash this bar. Access, API and
                 external connection listboxes are password protected, so they wait for Save. --}}
            <x-unsaved-bar action="submit"
                targets="is_registration_enabled,is_api_enabled,is_mcp_server_enabled,allowed_ips,admin_allowed_ips,do_not_track,is_error_reporting_enabled,is_changelog_enabled,is_feedback_forwarding_enabled,audit_log_retention_days,custom_dns_servers,webhook_allowed_internal_hosts,webhook_allow_localhost,domain_connect_private_key,image_cdn_url" />

            {{-- Opened by submit() when access or external connection settings change; it calls
                 submit(password) after the administrator re-enters their password. Fixed so the
                 empty trigger does not add a gap to the form layout. --}}
            <div class="fixed">
                <x-modal-confirmation :title="__('Confirm security settings change')" submitAction="submit"
                    :confirmWithText="false" :step3ButtonText="__('Save settings')"
                    :warningMessage="e(__('These settings control who can reach this instance and what it sends to the internet.'))"
                    :actions="[
                        __('Save the changed access, API, administrator IP and external connection settings.'),
                        __('The change takes effect immediately for every user.'),
                    ]">
                    <x-slot:trigger>
                        <span x-on:confirm-security-settings.window="modalOpen = true"></span>
                    </x-slot:trigger>
                </x-modal-confirmation>
            </div>

            @if ($hasPendingSecurityChanges)
                <x-callout type="warning" :title="__('Security settings not saved yet')">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <span>{{ __('Changes to access, API, administrator IP and external connection settings are saved only after you confirm your password.') }}</span>
                        <x-forms.button type="button" x-on:click="$dispatch('confirm-security-settings')">
                            {{ __('Confirm and save') }}
                        </x-forms.button>
                    </div>
                </x-callout>
            @endif

            <x-application.settings-section id="access-section" :title="__('Access')"
                :helper="__('Changing registration requires your password.')">
                <div class="grid gap-4 lg:grid-cols-2">
                     <x-forms.listbox id="is_registration_enabled" :label="__('Registration')"
                        :helper="__('Allow users to create their own account. When disabled, only administrators can create accounts.')"
                        :options="[
                            ['value' => true, 'label' => __('Anyone can register')],
                            ['value' => false, 'label' => __('Registration disabled')],
                         ]" />
                     <x-forms.listbox canGate="update" :canResource="$settings"
                         id="disable_registration_when_oauth_enabled" :label="__('Password registration with OAuth')"
                         :helper="__('Hide password registration whenever at least one OAuth provider is enabled.')"
                         onChange="instantSave" :options="[
                             ['value' => false, 'label' => __('Allow password registration')],
                             ['value' => true, 'label' => __('Disable when OAuth is enabled')],
                         ]" />
                </div>
            </x-application.settings-section>

            <x-application.settings-section id="admin-access-section" :title="__('Administrator access')"
                :helper="__('Limit instance settings and other administrator pages to trusted networks. Changing this list requires your password.')">
                <div class="flex flex-col gap-4">
                    <x-forms.textarea id="admin_allowed_ips" rows="4" :label="__('Allowed administrator IPs')"
                        :helper="__('One IPv4/IPv6 address or CIDR range per line (commas also work). Leave empty to allow administrators from any address.')"
                        placeholder="10.0.0.0/8&#10;192.168.10.25&#10;2001:db8::/32" />
                    <x-callout type="warning" :title="__('Do not lock yourself out')">
                        {{ __('Your current IP address is :ip. A list that does not include it would lock you out, so it cannot be saved.', ['ip' => $currentIp ?? __('unknown')]) }}
                    </x-callout>
                </div>
            </x-application.settings-section>

            <x-application.settings-section id="dns-section" :title="__('DNS validation')">
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.listbox id="is_dns_validation_enabled" :label="__('DNS validation')"
                        :helper="__('Validate custom domains before deployment.')" onChange="instantSave" :options="[
                            ['value' => true, 'label' => __('Enabled')],
                            ['value' => false, 'label' => __('Disabled')],
                        ]" />
                    <x-forms.input id="custom_dns_servers" :label="__('Custom DNS servers')"
                        :helper="__('Comma-separated resolvers. Leave empty to use system defaults.')"
                        placeholder="1.1.1.1, 8.8.8.8" />
                </div>
            </x-application.settings-section>

            @if (isCloud())
                <x-application.settings-section id="domain-connect-section" :title="__('Domain Connect')"
                    :helper="__('Optional RSA private key used to sign Cloudflare Domain Connect apply URLs on Coolify Cloud. Leave blank to keep the existing key.')">
                    <div class="grid gap-4">
                        <x-forms.input id="domain_connect_private_key" type="password" allowToPeak
                            :label="__('Domain Connect private key (PEM)')"
                            :helper="__('Paste a PEM private key to set or rotate. Public key must be published at domainconnect.coolify.io. Env DOMAIN_CONNECT_PRIVATE_KEY is used as a fallback when this is empty.')"
                            placeholder="-----BEGIN PRIVATE KEY-----" />
                        @if (filled(data_get($settings, 'domain_connect_private_key')))
                            <div class="flex flex-wrap items-center gap-2">
                                <x-status-badge :status="__('Key configured')" type="success" />
                                <x-forms.button type="button" wire:click="clearDomainConnectPrivateKey" isError>
                                    {{ __('Remove key') }}
                                </x-forms.button>
                            </div>
                        @elseif (filled(config('services.domain_connect.private_key')))
                            <x-status-badge :status="__('Using DOMAIN_CONNECT_PRIVATE_KEY from environment')" type="neutral" />
                        @else
                            <x-callout type="info" :title="__('Not configured')">
                                {{ __('Automated Cloudflare DNS (Domain Connect) stays hidden until a private key is set.') }}
                            </x-callout>
                        @endif
                    </div>
                </x-application.settings-section>
            @endif

            <x-application.settings-section id="api-section" :title="__('API and MCP')"
                :helper="__('Changing API access, the MCP server or the API allowlist requires your password.')">
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.listbox id="is_api_enabled" :label="__('API access')"
                        :helper="__('Allow authenticated requests to the Coolify REST API.')"
                        :options="[
                            ['value' => true, 'label' => __('Enabled')],
                            ['value' => false, 'label' => __('Disabled')],
                        ]" />
                    <x-forms.listbox id="is_mcp_server_enabled" :label="__('MCP server')"
                        :helper="__('Expose the authenticated Streamable HTTP endpoint at /mcp.')"
                        :options="[
                            ['value' => true, 'label' => __('Enabled')],
                            ['value' => false, 'label' => __('Disabled')],
                        ]" />
                    <div class="lg:col-span-2">
                        <x-forms.input id="allowed_ips" :label="__('Allowed API IPs')"
                            :helper="__('Comma-separated IPs or CIDR ranges. Empty or 0.0.0.0 allows all sources.')"
                            placeholder="192.168.1.100, 10.0.0.0/8" />
                    </div>
                </div>
                @if ($is_api_enabled && (empty($allowed_ips) || in_array('0.0.0.0', array_map('trim', explode(',', $allowed_ips ?? '')))))
                    <x-callout type="warning" :title="__('API access is open to every source')" class="mt-4">
                        {{ __('Restrict the allowlist before using API access on a public production instance.') }}
                    </x-callout>
                @endif
                @if ($is_mcp_server_enabled)
                    <x-callout type="info" :title="__('MCP endpoint')" class="mt-4">
                        <code>{{ url('/mcp') }}</code> {{ __('uses Sanctum bearer tokens from Security → API Tokens.') }}
                    </x-callout>
                @endif
            </x-application.settings-section>

            <x-application.settings-section id="endpoint-section" :title="__('Outbound endpoints')">
                <div class="flex flex-col gap-4">
                    <x-forms.textarea id="webhook_allowed_internal_hosts" rows="4"
                        :label="__('Allowed internal targets')"
                        :helper="__('Hostnames, IPs, or CIDR ranges separated by commas or new lines.')"
                        placeholder="hooks.company.local, 10.50.0.0/16" />
                    <div class="max-w-md">
                        <x-forms.listbox id="webhook_allow_localhost" :label="__('Localhost targets')"
                            :helper="__('Loopback targets must also be present in the allowlist.')" :options="[
                                ['value' => true, 'label' => __('Allowed')],
                                ['value' => false, 'label' => __('Blocked')],
                            ]" />
                    </div>
                </div>
            </x-application.settings-section>

            <x-application.settings-section id="interface-section" :title="__('Interface')">
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.listbox id="is_wire_navigate_enabled" :label="__('Navigation')"
                        :helper="__('Prefetch pages and navigate without full reloads.')" onChange="instantSave" :options="[
                            ['value' => true, 'label' => __('SPA navigation')],
                            ['value' => false, 'label' => __('Full page navigation')],
                        ]" />
                </div>
            </x-application.settings-section>

            <x-application.settings-section id="external-connections-section" :title="__('External connections')"
                :helper="__('These features contact services on the internet. They are off by default so the instance works on closed networks without outbound access. Changing them requires your password.')">
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.listbox id="do_not_track" :label="__('Send anonymous usage data')"
                        :helper="__('Report this installation to the usage counter. Off by default for closed networks.')"
                        :options="[
                            ['value' => false, 'label' => __('Enabled')],
                            ['value' => true, 'label' => __('Disabled')],
                        ]" />
                    <x-forms.listbox id="is_error_reporting_enabled" :label="__('Error reports')"
                        :helper="__('Send error reports to Sentry. Off by default for closed networks.')"
                        :options="[
                            ['value' => true, 'label' => __('Enabled')],
                            ['value' => false, 'label' => __('Disabled')],
                        ]" />
                    <x-forms.listbox id="is_changelog_enabled" :label="__('Release notes')"
                        :helper="__('Fetch release notes from the internet. Off by default for closed networks.')"
                        :options="[
                            ['value' => true, 'label' => __('Enabled')],
                            ['value' => false, 'label' => __('Disabled')],
                        ]" />
                    <x-forms.listbox id="is_feedback_forwarding_enabled" :label="__('Feedback forwarding')"
                        :helper="__('Forward help requests and feedback to coolify.io. Off by default for closed networks.')"
                        :options="[
                            ['value' => true, 'label' => __('Enabled')],
                            ['value' => false, 'label' => __('Disabled')],
                        ]" />
                </div>
            </x-application.settings-section>

            <x-application.settings-section id="audit-log-section" :title="__('Audit log')">
                <div class="max-w-md">
                    <x-forms.input id="audit_log_retention_days" type="number"
                        min="{{ $minimumAuditLogRetentionDays }}" :label="__('Audit log retention (days)')"
                        :helper="__('How long audit events are kept. The minimum is :days days.', ['days' => $minimumAuditLogRetentionDays])"
                        required />
                </div>
            </x-application.settings-section>

            <x-application.settings-section id="avatar-storage-section" :title="__('Image storage')"
                :helper="__('Choose where compressed profile pictures and project icons are stored. Use S3 for multi-instance or cloud deployments so every application replica can access the same files.')">
                <div class="flex max-w-md flex-col gap-4">
                    <x-forms.listbox id="avatar_storage" :label="__('Storage destination')" onChange="instantSave"
                        :options="$avatar_storage_options" />
                    <x-forms.input id="image_cdn_url" :label="__('Image CDN URL')"
                        :helper="__('Optional public CDN URL for profile pictures and project icons stored on S3. Leave empty to use the S3 endpoint.')" placeholder="https://images.example.com" />
                </div>
                @if (count($avatar_storage_options) === 1)
                    <x-callout type="info" :title="__('No usable S3 storage configured')" class="mt-4">
                        {{ __('Add and test an S3-compatible storage under Storages before selecting it here.') }}
                    </x-callout>
                @endif
            </x-application.settings-section>
        </form>
    </x-settings.layout>
</div>
