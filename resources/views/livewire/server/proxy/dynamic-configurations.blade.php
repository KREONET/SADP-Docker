<div>
    <x-slot:title>
        {{ __('Proxy Dynamic Configuration') }} | {{ brand_name() }}
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="proxy" activeSubMenu="dynamic-confs" />

        <div class="application-settings-form flex w-full flex-col gap-6">
            @if ($server->isFunctional())
                <div class="flex flex-wrap items-center justify-end gap-3 px-1">
                    <div class="flex items-center gap-2">
                        <x-forms.button wire:click="loadDynamicConfigurations">
                            <x-reicon name="refresh" class="size-3.5" />
                            {{ __('Reload') }}
                        </x-forms.button>
                        @can('update', $server)
                            <x-modal-input :buttonTitle="__('+ Add')" :title="__('New Dynamic Configuration')">
                                <livewire:server.proxy.new-dynamic-configuration :server_id="$server->id" />
                            </x-modal-input>
                        @endcan
                    </div>
                </div>

                <div x-init="$wire.initLoadDynamicConfigurations" class="contents">
                    <x-skeleton.settings-sections wire:loading.flex wire:target="initLoadDynamicConfigurations"
                        :label="__('Loading dynamic configurations')" />

                    @if ($contents?->isNotEmpty())
                        @foreach ($contents as $fileName => $value)
                            @php
                                $displayName = str_replace('|', '.', $fileName);
                                $isManagedConfiguration = in_array($displayName, [
                                    'coolify.yaml',
                                    'Caddyfile',
                                    'coolify.caddy',
                                    'default_redirect_503.yaml',
                                    'default_redirect_503.caddy',
                                ]);
                            @endphp
                            <x-application.settings-section :title="$displayName"
                                wire:key="proxy-dynamic-configuration-{{ $fileName }}">
                                <x-slot:actions>
                                    @if ($isManagedConfiguration)
                                        <x-status-badge :status="__('Managed')" type="neutral" />
                                    @else
                                        <livewire:server.proxy.dynamic-configuration-navbar
                                            :server_id="$server->id" :server="$server" :fileName="$fileName"
                                            :value="$value ?? ''" :newFile="false"
                                            wire:key="proxy-navbar-{{ $fileName }}" />
                                    @endif
                                </x-slot:actions>
                                @can('update', $server)
                                    <x-forms.textarea disabled wire:model="contents.{{ $fileName }}"
                                        rows="8" />
                                @else
                                    <p class="text-xs text-neutral-500 dark:text-fg-dim">
                                        {{ __('You do not have permission to view the contents of this configuration.') }}
                                    </p>
                                @endcan
                            </x-application.settings-section>
                        @endforeach
                    @else
                        <x-application.settings-section wire:loading.remove :title="__('Dynamic configurations')">
                            <x-empty size="sm" :title="__('No dynamic configurations')"
                                :description="__('Add a configuration file to extend the proxy at runtime.')"
                                icon-name="file-content" />
                        </x-application.settings-section>
                    @endif
                </div>
            @else
                <x-application.settings-section :title="__('Dynamic configurations')"
                    :helper="__('Manage additional runtime proxy configuration.')">
                    <x-empty size="sm" :title="__('Server validation required')"
                        :description="__('Validate this server before loading proxy configuration.')"
                        icon-name="file-content" />
                </x-application.settings-section>
            @endif
        </div>
    </div>
</div>
