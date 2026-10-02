@php use App\Enums\ProxyTypes; @endphp

<div class="application-settings-form flex w-full flex-col gap-6">
    @if ($server->proxyType())
        @if ($selectedProxy !== 'NONE')
            <form wire:submit="submit" class="contents">
                <x-unsaved-bar action="submit" />

                <fieldset class="contents" wire:loading.attr="disabled"
                    wire:target="submit,resetProxyConfiguration">

                <x-application.settings-section id="server-proxy-overview-section" :title="__('Proxy configuration')"
                    :helper="__('Configure the reverse proxy and request handling for this server.')">
                    <x-slot:actions>
                        <div class="flex items-center gap-2">
                            <x-status-badge :status="str($server->proxy->status)->headline()"
                                :type="str($server->proxy->status)->contains('running') ? 'success' : 'neutral'" />
                            @if ($server->proxy->status === 'exited' || $server->proxy->status === 'removing')
                                @can('update', $server)
                                    <x-modal-confirmation :title="__('Confirm Proxy Switching?')"
                                        :buttonTitle="__('Switch proxy')" submitAction="changeProxy"
                                        :actions="[__('Custom proxy configurations may be reset to their default settings.')]"
                                        :warningMessage="__('Review the proxy switching guide before continuing.')"
                                        :step2ButtonText="__('Switch Proxy')" :confirmWithText="false"
                                        :confirmWithPassword="false" />
                                @endcan
                            @else
                                <x-forms.button canGate="update" :canResource="$server"
                                    wire:click="$dispatch('error', {{ \Illuminate\Support\Js::from(__('The running proxy must be stopped before switching.')) }})">
                                    {{ __('Switch proxy') }}
                                </x-forms.button>
                            @endif
                        </div>
                    </x-slot:actions>

                    @if (
                        $server->proxy->last_applied_settings &&
                            $server->proxy->last_saved_settings !== $server->proxy->last_applied_settings)
                        <x-callout type="warning" :title="__('Your configuration changed, please restart the proxy.')" />
                    @elseif ($server->hasPendingProxyConfiguration())
                        <x-callout type="warning" :title="__('Restart required')">
                            {{ __('Restart the proxy to stop serving deleted TLS certificates.') }}
                        </x-callout>
                    @else
                        <div class="flex items-start gap-3">
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 dark:bg-white/[0.06] dark:text-fg-dim">
                                <x-reicon name="servers" class="size-4" />
                            </div>
                            <div>
                                <p class="text-sm font-medium text-neutral-950 dark:text-fg">
                                    {{ str($server->proxyType())->title() }}
                                </p>
                                <p class="mt-1 text-xs text-neutral-500 dark:text-fg-dim">
                                    {{ __('Saved and running configuration are synchronized.') }}
                                </p>
                            </div>
                        </div>
                    @endif
                </x-application.settings-section>

                <x-application.settings-section id="server-proxy-routing-section" :title="__('Routing behavior')"
                    :helper="__('Control generated labels and requests that do not match a running resource.')">
                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-forms.listbox id="generateExactLabels" :label="__('Generated labels')"
                            :helper="__('<ul class=\'list-disc space-y-1 pl-4\'><li><span class=\'font-semibold\'>All supported proxies:</span> Traefik and Caddy labels are both generated, so switching the proxy keeps routing working.</li><li><span class=\'font-semibold\'>Active proxy only:</span> generates fewer labels, but all resources must be redeployed to become accessible again after each proxy switch.</li></ul>')"
                            onChange="instantSave" :options="[
                                ['value' => false, 'label' => __('Labels for all supported proxies')],
                                ['value' => true, 'label' => __('Labels for the active proxy only')],
                            ]" />
                        <x-forms.listbox id="redirectEnabled" :label="__('Unknown requests')"
                            :helper="__('Override the default 503 response for unknown hosts and stopped services.')"
                            onChange="instantSaveRedirect" :options="[
                                ['value' => false, 'label' => __('Return the default 503 response')],
                                ['value' => true, 'label' => __('Use custom request handling')],
                            ]" />
                        @if ($redirectEnabled)
                            <x-forms.input canGate="update" :canResource="$server"
                                placeholder="https://app.coolify.io" id="redirectUrl"
                                :label="__('Redirect URL')"
                                :helper="__('Leave empty to keep a custom 503 response without redirecting.')" />
                        @endif
                    </div>
                </x-application.settings-section>

                @if ($server->proxyType() === ProxyTypes::TRAEFIK->value)
                    <x-application.settings-section id="server-proxy-certificates-section" :title="__('TLS certificates')"
                        x-init="$wire.loadTraefikCertificates()"
                        :helper="__('Review certificates stored in Traefik\'s acme.json file. Deleting an entry removes it from local ACME storage. It does not revoke the certificate at the certificate authority. If a route still uses the domain, Traefik requests a new certificate after the restart.')">
                        <x-slot:actions>
                            <x-forms.button type="button" wire:click="loadTraefikCertificates"
                                wire:loading.attr="disabled" wire:target="loadTraefikCertificates">
                                {{ __('Refresh') }}
                            </x-forms.button>
                        </x-slot:actions>

                        <div wire:loading.flex wire:target="loadTraefikCertificates"
                            class="min-h-24 items-center justify-center">
                            <x-loading :text="__('Loading TLS certificates…')" />
                        </div>

                        <div wire:loading.remove wire:target="loadTraefikCertificates">
                            @if ($traefikCertificatesLoaded && count($traefikCertificates) === 0)
                                <x-empty size="sm" :title="__('No TLS certificates found')"
                                    :description="__('Traefik\'s ACME storage does not contain certificate entries.')"
                                    icon-name="security" />
                            @elseif (count($traefikCertificates) > 0)
                                <div class="overflow-hidden rounded-lg ring-1 ring-neutral-200 dark:ring-white/[0.08]">
                                    <div class="overflow-x-auto">
                                        <table class="w-full min-w-3xl">
                                            <thead>
                                                <tr>
                                                    <th>{{ __('Domain') }}</th>
                                                    <th>{{ __('Resolver') }}</th>
                                                    <th>{{ __('Alternative names') }}</th>
                                                    <th>{{ __('Expires') }}</th>
                                                    <th><span class="sr-only">{{ __('Actions') }}</span></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($traefikCertificates as $certificate)
                                                    <tr wire:key="traefik-certificate-{{ $certificate['id'] }}">
                                                        <td class="font-medium text-neutral-950 dark:text-fg">
                                                            {{ $certificate['main_domain'] }}
                                                        </td>
                                                        <td>
                                                            <span class="font-mono text-xs">{{ $certificate['resolver'] }}</span>
                                                            @if ($certificate['store'])
                                                                <span class="text-xs text-neutral-500 dark:text-fg-dim">
                                                                    ({{ $certificate['store'] }})
                                                                </span>
                                                            @endif
                                                        </td>
                                                        <td>
                                                            @if (count($certificate['sans']) > 0)
                                                                <div class="flex max-w-md flex-wrap gap-1">
                                                                    @foreach ($certificate['sans'] as $domain)
                                                                        <span
                                                                            class="rounded bg-neutral-100 px-1.5 py-0.5 font-mono text-xs text-neutral-700 dark:bg-white/[0.06] dark:text-fg-dim">
                                                                            {{ $domain }}
                                                                        </span>
                                                                    @endforeach
                                                                </div>
                                                            @else
                                                                <span class="text-neutral-500 dark:text-fg-dim">{{ __('None') }}</span>
                                                            @endif
                                                        </td>
                                                        <td>
                                                            {{ $certificate['expires_at'] ?? __('Unknown') }}
                                                        </td>
                                                        <td class="text-right">
                                                            @can('update', $server)
                                                                <x-modal-confirmation :title="__('Delete TLS Certificate?')"
                                                                    :buttonTitle="__('Delete')"
                                                                    submitAction="deleteTraefikCertificate({{ $certificate['id'] }})"
                                                                    :actions="[
                                                                        __('Delete the certificate for :domain from acme.json.', ['domain' => $certificate['main_domain']]),
                                                                        __('Restart the proxy before it stops using the certificate. If a route still uses the domain, Traefik requests a new certificate after the restart.'),
                                                                    ]"
                                                                    confirmationText="{{ $certificate['main_domain'] }}"
                                                                    :confirmationLabel="__('Confirm by entering the domain')"
                                                                    :shortConfirmationLabel="__('Domain')"
                                                                    :step2ButtonText="__('Delete Certificate')"
                                                                    isErrorButton :confirmWithPassword="false"
                                                                    :confirmWithText="true" />
                                                            @endcan
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </x-application.settings-section>
                @endif

                @php
                    $proxyTitle =
                        $server->proxyType() === ProxyTypes::TRAEFIK->value
                            ? __('Traefik configuration')
                            : __('Caddy configuration');
                @endphp

                @if ($server->proxyType() === ProxyTypes::TRAEFIK->value || $server->proxyType() === 'CADDY')
                    <x-application.settings-section id="server-proxy-file-section" :title="$proxyTitle"
                        x-init="$wire.loadProxyConfiguration()"
                        :helper="__('Edit the generated proxy compose configuration used on this server.')">
                        <x-slot:actions>
                            @can('update', $server)
                                @if ($proxySettings)
                                    <x-modal-confirmation :title="__('Reset Proxy Configuration?')"
                                        :buttonTitle="__('Reset configuration')"
                                        submitAction="resetProxyConfiguration" :actions="[
                                            __('Reset the proxy configuration to Coolify defaults.'),
                                            __('Remove custom ports, entrypoints, and other manual changes.'),
                                        ]" confirmationText="{{ $server->name }}"
                                        :confirmationLabel="__('Confirm by entering the server name')"
                                        :shortConfirmationLabel="__('Server Name')"
                                        :step2ButtonText="__('Reset Configuration')"
                                        :confirmWithPassword="false" :confirmWithText="true" />
                                @endif
                            @endcan
                        </x-slot:actions>

                        @if ($server->proxyType() === ProxyTypes::TRAEFIK->value)
                            @if ($this->traefikVersionForWarning === 'latest')
                                <x-callout type="warning" :title="__('Unpinned Traefik version')">
                                    {!! __('The proxy uses the <span class="font-mono">latest</span> tag. Pin <span class="font-mono">traefik::version</span> for predictable updates.', ['version' => e($this->latestTraefikVersion)]) !!}
                                </x-callout>
                            @endif
                            @if ($this->isTraefikOutdated)
                                <x-callout type="warning" :title="__('Traefik patch update available')">
                                    {{ $server->detected_traefik_version
                                        ? __('Running version v:current. The latest patch for this branch is :latest. Test the update before applying it to production servers.', ['current' => ltrim($this->traefikVersionForWarning, 'v'), 'latest' => $this->latestTraefikVersion])
                                        : __('Configured image v:current. The latest patch for this branch is :latest. Test the update before applying it to production servers.', ['current' => ltrim($this->traefikVersionForWarning, 'v'), 'latest' => $this->latestTraefikVersion]) }}
                                </x-callout>
                            @endif
                            @if ($this->newerTraefikBranchAvailable)
                                <x-callout type="info" :title="__('New Traefik minor version available')">
                                    {{ __(':branch is available (latest patch: :latest). Review the Traefik changelog for breaking changes before upgrading.', ['branch' => $this->newerTraefikBranchAvailable, 'latest' => $this->latestNewerTraefikVersion]) }}
                                </x-callout>
                            @endif
                        @elseif ($this->outdatedCaddyImage)
                            <x-server.caddy-image-outdated-callout :image="$this->outdatedCaddyImage" />
                        @endif

                        <div wire:loading.flex wire:target="loadProxyConfiguration"
                            class="min-h-32 items-center justify-center">
                            <x-loading :text="__('Loading proxy configuration…')" />
                        </div>

                        @if ($proxySettings)
                            <div class="relative mt-4" wire:loading.class="pointer-events-none opacity-50"
                                wire:target="submit,resetProxyConfiguration" aria-live="polite">
                                <div wire:loading.flex wire:target="submit,resetProxyConfiguration"
                                    class="absolute inset-0 z-20 hidden items-center justify-center rounded-lg bg-white/75 backdrop-blur-[1px] dark:bg-black/55">
                                    <div
                                        class="flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-xs font-medium text-neutral-700 shadow-sm ring-1 ring-neutral-200 dark:bg-coolgray-100 dark:text-fg dark:ring-white/10">
                                        <x-loading />
                                        {{ __('Updating proxy configuration…') }}
                                    </div>
                                </div>
                                <x-forms.textarea canGate="update" :canResource="$server" useMonacoEditor
                                    monacoEditorLanguage="yaml"
                                    :label="__('Configuration file · :path', ['path' => $this->configurationFilePath])"
                                    name="proxySettings" id="proxySettings" rows="30" />
                            </div>
                        @endif
                    </x-application.settings-section>
                @endif
                </fieldset>
            </form>
        @elseif($selectedProxy === 'NONE')
            <x-application.settings-section :title="__('Custom proxy')"
                :helper="__('Coolify will not manage a reverse proxy for this server.')">
                <x-slot:actions>
                    @can('update', $server)
                        <x-forms.button wire:click.prevent="changeProxy">{{ __('Switch proxy') }}</x-forms.button>
                    @endcan
                </x-slot:actions>
                <x-callout type="info" :title="__('Custom proxy selected')">
                    {{ __('Configure and operate the proxy outside Coolify.') }}
                </x-callout>
            </x-application.settings-section>
        @else
            <x-application.settings-section :title="__('Proxy configuration')"
                :helper="__('Choose the reverse proxy implementation for this server.')">
                @can('update', $server)
                    <div class="grid gap-3 lg:grid-cols-3">
                        @foreach ([
                            ['value' => 'NONE', 'title' => __('Custom'), 'description' => __('Manage the proxy outside Coolify.')],
                            ['value' => 'TRAEFIK', 'title' => 'Traefik', 'description' => __('Use the default Coolify proxy.')],
                            ['value' => 'CADDY', 'title' => 'Caddy', 'description' => __('Use the Coolify Caddy integration.')],
                        ] as $proxyOption)
                            <button type="button" wire:click="selectProxy('{{ $proxyOption['value'] }}')"
                                class="rounded-lg p-4 text-left ring-1 ring-neutral-200 transition-colors hover:bg-neutral-50 dark:ring-white/[0.08] dark:hover:bg-white/[0.04]">
                                <p class="text-sm font-medium text-neutral-950 dark:text-fg">
                                    {{ $proxyOption['title'] }}
                                </p>
                                <p class="mt-1 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                                    {{ $proxyOption['description'] }}
                                </p>
                            </button>
                        @endforeach
                    </div>
                @else
                    <x-callout type="danger" :title="__('Insufficient permissions')">
                        {{ __('You do not have permission to select a proxy for this server.') }}
                    </x-callout>
                @endcan
            </x-application.settings-section>
        @endif
    @else
        <x-application.settings-section :title="__('Proxy configuration')"
            :helper="__('Choose the reverse proxy implementation for this server.')">
            @can('update', $server)
                <div class="grid gap-3 lg:grid-cols-3">
                    @foreach ([
                        ['value' => 'NONE', 'title' => __('Custom'), 'description' => __('Manage the proxy outside Coolify.')],
                        ['value' => 'TRAEFIK', 'title' => 'Traefik', 'description' => __('Use the default Coolify proxy.')],
                        ['value' => 'CADDY', 'title' => 'Caddy', 'description' => __('Use the Coolify Caddy integration.')],
                    ] as $proxyOption)
                        <button type="button" wire:click="selectProxy('{{ $proxyOption['value'] }}')"
                            class="rounded-lg p-4 text-left ring-1 ring-neutral-200 transition-colors hover:bg-neutral-50 dark:ring-white/[0.08] dark:hover:bg-white/[0.04]">
                            <p class="text-sm font-medium text-neutral-950 dark:text-fg">{{ $proxyOption['title'] }}</p>
                            <p class="mt-1 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                                {{ $proxyOption['description'] }}
                            </p>
                        </button>
                    @endforeach
                </div>
            @endcan
        </x-application.settings-section>
    @endif
</div>
