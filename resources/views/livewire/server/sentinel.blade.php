@php
    $sentinelStatusLabel = match ($sentinelStatus) {
        'restarting' => __('Restarting'),
        'waiting' => __('Waiting for first report'),
        'in_sync' => __('In sync'),
        default => __('Out of sync'),
    };
    $sentinelStatusType = match ($sentinelStatus) {
        'in_sync' => 'success',
        'out_of_sync' => 'warning',
        default => 'neutral',
    };
@endphp

<div class="application-settings-form flex w-full flex-col gap-6" wire:poll.10s="refreshSentinelStatus">
    <form wire:submit.prevent="submit" class="contents">
        {{-- Scope dirty tracking to savable form fields only. Without wire:target,
             Livewire compares the entire component snapshot — so dev-only x-init
             `$wire.set('sentinelCustomDockerImage', …)` (and similar) briefly
             flashes this bar on every page open. --}}
        <x-unsaved-bar action="submit"
            targets="sentinelCustomUrl,sentinelToken" />

        <x-application.settings-section id="server-sentinel-overview-section" title="Sentinel"
            :helper="__('Monitor server and container health while collecting historical metrics.')">
            <x-slot:actions>
                <div class="flex items-center gap-2">
                    <x-status-badge :status="$sentinelStatusLabel" :type="$sentinelStatusType" />
                    <x-forms.button wire:click="restartSentinel" canGate="update"
                        :canResource="$server">
                        <x-reicon name="refresh" class="size-3.5" />
                        {{ $sentinelStatus === 'in_sync' ? __('Restart') : __('Sync') }}
                    </x-forms.button>
                </div>
            </x-slot:actions>

            @if ($sentinelStatus === 'out_of_sync')
                <x-callout type="warning" :title="__('Sentinel is out of sync')">
                    <div class="space-y-3">
                        <p>{{ __('Sentinel has not reported within the expected interval. Check these items before syncing again:') }}</p>
                        <ul class="list-disc space-y-1 pl-4">
                            <li>{!! __('Confirm that the <code>coolify-sentinel</code> container is running.') !!}</li>
                            <li>
                                <a class="font-medium underline underline-offset-2"
                                    href="{{ route('server.sentinel.logs', ['server_uuid' => $server->uuid]) }}"
                                    wire:navigate>{{ __('Open Sentinel logs') }}</a>
                                {{ __('and review recent connection or push errors.') }}
                            </li>
                            <li>{{ __('Confirm that the Coolify URL and Sentinel token match this configuration.') }}</li>
                        </ul>

                        @if ($server->isLocalhost())
                            <p>{{ __('Sync Sentinel to recreate it on the Coolify Docker network.') }}</p>
                        @else
                            <div class="space-y-2">
                                <p>{{ __('The remote server needs outbound access to this Coolify URL. Sentinel reporting does not require an inbound listening port.') }}</p>
                                @if (filled($sentinelCustomUrl))
                                    <p>
                                        {{ __('From the remote server, test') }}
                                        <code class="break-all">curl -fsS {{ escapeshellarg(rtrim($sentinelCustomUrl, '/') . '/api/health') }}</code>.
                                    </p>
                                @else
                                    <p>{{ __('Set a reachable Coolify URL before syncing Sentinel.') }}</p>
                                @endif
                                <p>{{ __('Check DNS, TLS certificates, outbound firewall rules, and proxy settings.') }}</p>
                            </div>
                        @endif
                    </div>
                </x-callout>
            @elseif ($sentinelStatus === 'in_sync')
                <p class="text-sm text-neutral-500 dark:text-fg-dim">
                    {{ __('Sentinel is connected and reporting server health to this Coolify instance.') }}
                </p>
            @elseif ($sentinelStatus === 'restarting')
                <p class="text-sm text-neutral-500 dark:text-fg-dim">{{ __('Sentinel is restarting.') }}</p>
            @else
                <p class="text-sm text-neutral-500 dark:text-fg-dim">{{ __('Sentinel started and is waiting for its first authenticated report.') }}</p>
            @endif
        </x-application.settings-section>

        @if ($server->isSentinelEnabled())
            <x-application.settings-section id="server-sentinel-connection-section" :title="__('Connection')"
                :helper="__('Configure how Sentinel authenticates with and reports to Coolify.')">
                <x-slot:actions>
                    <div class="flex items-center gap-2">
                        @can('manageSentinel', $server)
                            <x-modal-confirmation :title="__('Restore default Sentinel configuration?')"
                                :buttonTitle="__('Restore defaults')" submitAction="restoreDefaultConfiguration"
                                :actions="[
                                    __('Restore the generated Coolify URL and default collection settings.'),
                                    __('Clear debug logging and the development image override.'),
                                    __('The Sentinel token and metrics setting will be preserved.'),
                                    __('Restart Sentinel to apply the restored configuration.'),
                                ]" :warningMessage="__('Your custom Sentinel configuration will be replaced with Coolify defaults.')"
                                :confirmWithText="false" :confirmWithPassword="false"
                                :step2ButtonText="__('Restore defaults')" />
                        @endcan
                        <x-forms.button canGate="update" :canResource="$server"
                            wire:click="regenerateSentinelToken">
                            {{ __('Regenerate token') }}
                        </x-forms.button>
                    </div>
                </x-slot:actions>
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.input canGate="update" :canResource="$server" id="sentinelCustomUrl"
                        required :label="__('Coolify URL')"
                        :helper="__('Public URL used by Sentinel to reach this Coolify instance.')" />
                    <x-forms.input canGate="update" :canResource="$server" type="password"
                        id="sentinelToken" :label="__('Sentinel token')" required
                        :helper="__('Authentication token used by Sentinel.')" />
                </div>
            </x-application.settings-section>

            @if (isDev())
                <x-application.settings-section id="server-sentinel-development-section"
                    :title="__('Development overrides')"
                    :helper="__('Local testing controls that are unavailable in production.')">
                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-forms.listbox id="isSentinelDebugEnabled" :label="__('Debug logging')"
                            onChange="instantSave" :options="[
                                ['value' => false, 'label' => __('Standard logging')],
                                ['value' => true, 'label' => __('Enable debug logging')],
                            ]" />
                        <div x-data="{
                            customImage: localStorage.getItem('sentinel_custom_docker_image_{{ $server->uuid }}') || '',
                            async applyCustomImage() {
                                localStorage.setItem('sentinel_custom_docker_image_{{ $server->uuid }}', this.customImage);
                                await $wire.set('sentinelCustomDockerImage', this.customImage || null);
                                await $wire.restartSentinel();
                            }
                        }"
                            @sentinel-defaults-restored.window="localStorage.removeItem('sentinel_custom_docker_image_{{ $server->uuid }}'); customImage = ''"
                            {{-- Only hydrate Livewire when a real override exists. Unconditional
                                 $wire.set('', null→'') on every open marks the component dirty and
                                 flashes the unsaved bar until the round-trip completes. --}}
                            x-init="if (customImage) { $wire.set('sentinelCustomDockerImage', customImage) }">
                            <div class="flex items-end gap-2">
                                <div class="min-w-0 flex-1">
                                    <x-forms.input canGate="update" :canResource="$server" x-model="customImage"
                                        placeholder="sentinel:latest" :label="__('Custom Docker image')"
                                        :helper="__('Leave empty to use the default Sentinel image.')" />
                                </div>
                                <x-forms.button canGate="update" :canResource="$server"
                                    x-on:click="applyCustomImage()">
                                    {{ __('Apply and restart') }}
                                </x-forms.button>
                            </div>
                        </div>
                    </div>
                </x-application.settings-section>
            @endif
        @endif
    </form>
</div>
