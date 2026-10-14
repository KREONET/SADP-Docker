<div class="application-settings-form mt-6 flex w-full flex-col gap-6">
    @if ($isController)
        <x-application.settings-section id="server-proxy-controller-routing-section"
            :title="__('Traffic for internal servers')"
            :helper="__('This proxy also receives the traffic of servers that are routed through the controller. For each of their routed containers, a small relay container on this server carries the proxy labels and forwards to the server\'s internal IP.')">
            @if ($routedServers->isEmpty())
                <x-empty size="sm" :title="__('No routed servers')"
                    :description="__('Open the Proxy page of a server on the internal network and route it through the controller.')"
                    icon-name="servers" />
            @else
                <div class="flex flex-col divide-y divide-neutral-200 dark:divide-white/[0.07]">
                    @foreach ($routedServers as $routedServer)
                        <div class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="routed-server-{{ $routedServer->id }}">
                            <a class="font-medium hover:underline" wire:navigate
                                href="{{ route('server.proxy', ['server_uuid' => $routedServer->uuid]) }}">{{ $routedServer->name }}</a>
                            <span class="text-sm text-neutral-600 dark:text-fg-dim">
                                {{ $routedServer->settings->internal_ip ?? __('No internal IP') }} ·
                                {{ trans_choice(':count route|:count routes', $routeCounts[$routedServer->id] ?? 0, ['count' => $routeCounts[$routedServer->id] ?? 0]) }}
                            </span>
                        </div>
                    @endforeach
                </div>
                @can('update', $server)
                    <div>
                        <x-forms.button wire:click="syncRoutes">{{ __('Sync routes') }}</x-forms.button>
                    </div>
                @endcan
            @endif
        </x-application.settings-section>
    @else
        <x-application.settings-section id="server-proxy-controller-routing-section"
            :title="__('Route traffic through the controller')"
            :helper="__('For servers on the same internal network as the controller. The server runs no proxy: the controller\'s proxy receives its traffic, handles domains and certificates, and forwards requests to the server over the internal network. Point the DNS records of its domains to the controller.')">
            @if ($isEnabled)
                <x-callout type="info" :title="__('Routed through the controller')">
                    {{ __('Containers with a domain publish their ports on :ip in the range :range. Only the controller needs to reach these ports; keep them closed to the internet.', ['ip' => $internalIp, 'range' => $portRange]) }}
                    {{ trans_choice(':count container is routed right now.|:count containers are routed right now.', $activeRoutes, ['count' => $activeRoutes]) }}
                </x-callout>
            @elseif ($blockers !== [])
                <x-callout type="warning" :title="__('Not available')">
                    {{ $blockers[0] }}
                </x-callout>
            @endif

            @if ($isEnabled || $blockers === [])
                <form wire:submit="save" class="flex flex-col gap-4">
                    <x-forms.checkbox canGate="update" :canResource="$server" id="isEnabled"
                        :label="__('Route traffic through the controller')"
                        :helper="__('Stops this server\'s proxy. Applications and services publish their ports on the internal IP after their next deployment or restart.')" />
                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-forms.input canGate="update" :canResource="$server" id="internalIp"
                            :label="__('Internal IP')" placeholder="10.0.0.12"
                            :helper="__('The address of this server on the internal network. The controller connects to it, and containers are published only on it.')" />
                    </div>
                    @can('update', $server)
                        <div>
                            <x-forms.button type="submit">{{ __('Save') }}</x-forms.button>
                        </div>
                    @endcan
                </form>
            @endif
        </x-application.settings-section>
    @endif
</div>
