<nav wire:poll.10000ms="checkStatus" class="w-full max-w-none pb-4 md:pb-6 lg:pb-0">
    @php
        $serviceStatus = str($service->status ?? 'exited');
        $selectedResourceUuid = data_get($parameters, 'stack_service_uuid');
        $selectedResource = $selectedResourceUuid
            ? $service->applications->firstWhere('uuid', $selectedResourceUuid)
                ?? $service->databases->firstWhere('uuid', $selectedResourceUuid)
            : null;
        $displayStatus = $selectedResource?->status ?? $service->status;
        $selectedResourceStatus = str($selectedResource?->status ?? '');
        $environmentVariablesUrl = route('project.service.environment-variables', [
            'project_uuid' => $service->environment->project->uuid,
            'environment_uuid' => $service->environment->uuid,
            'service_uuid' => $service->uuid,
        ]);
    @endphp

    <livewire:project.shared.configuration-checker :resource="$service" />

    <x-process-dialog @startservice.window="processDialogOpen = true" closeWithX>
        <x-slot:title>{{ __('Service Startup') }}</x-slot:title>
        <x-slot:content>
            <livewire:activity-monitor :header="__('Logs')" fullHeight />
        </x-slot:content>
    </x-process-dialog>

    <div x-data="{ deploying: false }" @service-restarting.window="deploying = true"
        @service-deploy-finished.window="deploying = false">
        <div class="mb-3 w-full xl:hidden">
            <div class="flex min-w-0 flex-col items-start gap-2">
                <h1 class="min-w-0 max-w-full truncate text-[24px]! leading-7! font-semibold! tracking-tight! text-black dark:text-fg">
                    {{ $service->name }}
                </h1>
                <div class="relative flex w-full min-w-0 items-center gap-2">
                    <x-status-summary :status="$displayStatus" :title="$selectedResource ? __('Resource status') : __('Service status')"
                        :container-name="$selectedResource ? __('Container') : __('Containers')" />
                    <x-services.links :service="$service" compact />
                    @if ($isDeploymentProgress)
                        <x-deploying-indicator />
                    @endif
                </div>
                <div class="flex w-full flex-wrap gap-1">
                    @if ($selectedResource)
                        <x-application.restart-limit-warning :application="$selectedResource" />
                    @endif
                </div>
            </div>
        </div>

        <div class="w-full xl:hidden">
            @if ($service->isDeployable)
                @can('deploy', $service)
                    <x-split-action id="service-mobile-actions" class="mb-3 flex w-full">
                        @if ($selectedResource && $selectedResource->container_present !== false && $selectedResourceStatus->startsWith('exited'))
                            <x-slot:main @click="document.getElementById('selected-resource-remove-trigger')?.click()">
                                <x-reicon name="trash" class="size-3.5" />
                                {{ __('Remove container') }}
                            </x-slot:main>
                        @elseif ($serviceStatus->contains('running') || $serviceStatus->contains('degraded'))
                            <x-slot:main x-bind:disabled="deploying"
                                @click="document.getElementById('service-restart-trigger')?.click()">
                                <x-loading-on-button x-show="deploying" x-cloak />
                                <x-reicon name="restart" class="size-3.5" x-show="!deploying" />
                                <span x-text="deploying ? @js(__('Restarting…')) : @js(__('Restart'))">{{ __('Restart') }}</span>
                            </x-slot:main>
                            @if ($serviceStatus->contains('running'))
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    @click="$wire.dispatch('pullAndRestartEvent'); open = false" role="menuitem">
                                    <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                    {{ __('Restart (pull latest)') }}
                                </button>
                            @endif
                            @if ($serviceStatus->contains('degraded'))
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    @click="$wire.dispatch('forceDeployEvent'); open = false" role="menuitem">
                                    <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                    {{ __('Force Restart') }}
                                </button>
                            @endif
                            <button type="button" class="listbox-option justify-start! gap-2.5!"
                                @disabled(!auth()->user()->can('stop', $service))
                                @click="open = false; document.getElementById('service-stop-trigger')?.click()" role="menuitem">
                                <x-reicon name="stop-circle" class="size-3.5 text-error" />
                                {{ __('Stop') }}
                            </button>
                        @else
                            <x-slot:main x-bind:disabled="deploying" @click="deploying = true; $wire.dispatch('startEvent')">
                                <x-loading-on-button x-show="deploying" x-cloak />
                                <x-reicon name="play-circle" class="size-3.5" x-show="!deploying" />
                                <span x-text="deploying ? @js(__('Deploying…')) : @js(__('Deploy'))">{{ __('Deploy') }}</span>
                            </x-slot:main>
                            <button type="button" class="listbox-option justify-start! gap-2.5!"
                                @click="$wire.dispatch('forceDeployEvent'); open = false" role="menuitem">
                                <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                {{ __('Force Deploy') }}
                            </button>
                            <button type="button" class="listbox-option justify-start! gap-2.5!"
                                @disabled(!auth()->user()->can('stop', $service))
                                @click="$wire.dispatch('cleanupEvent'); open = false" role="menuitem">
                                <x-reicon name="trash" class="size-3.5 opacity-70" />
                                {{ __('Force Cleanup Containers') }}
                            </button>
                        @endif
                    </x-split-action>
                @endcan
            @else
                @can('deploy', $service)
                    <div id="service-mobile-actions" class="relative mb-3"
                        x-data="{ open: false }" @click.outside="open = false"
                        @keydown.escape.window="open = false">
                        <button type="button" class="button w-full justify-between" @click="open = !open"
                            :aria-expanded="open" aria-haspopup="menu">
                            <span>{{ __('Actions') }}</span>
                            <span class="inline-flex transition-transform" :class="open && 'rotate-180'">
                                <x-reicon name="chevron-down" class="size-3 opacity-55" />
                            </span>
                        </button>

                        <div x-cloak x-show="open" x-transition.origin.top.left
                            class="listbox-panel top-full! left-0! right-0! mt-1! w-full! min-w-0!" role="menu">
                            <div class="listbox-option cursor-default! justify-start! gap-2.5! text-neutral-400! dark:text-fg-faint!"
                                role="menuitem" aria-disabled="true">
                                <x-reicon name="play-circle" class="size-3.5 opacity-70" />
                                <span>{{ __('Deploy') }} (<a href="{{ $environmentVariablesUrl }}" {{ wireNavigate() }}
                                        class="cursor-pointer underline underline-offset-2">{{ __('missing required env vars') }}</a>)</span>
                            </div>
                        </div>
                    </div>
                @endcan
            @endif

        </div>

        @teleport('#resource-action-hud-slot')
        <div class="hidden w-full items-center xl:flex xl:w-auto">
            <div
                class="resource-heading-navbar application-heading-actions flex w-auto min-w-0 items-center justify-end gap-1 overflow-visible">
                <div class="resource-heading-actions flex shrink-0 items-center gap-0.5">
                    @if ($isDeploymentProgress)
                        <x-deploying-indicator class="mr-1" />
                    @endif
                    @if ($service->isDeployable)
                        <div class="resource-heading-menus shrink-0">
                            <x-services.links :service="$service" />
                        </div>
                        @can('deploy', $service)
                            <x-split-action id="service-desktop-actions">
                                @if ($selectedResource && $selectedResource->container_present !== false && $selectedResourceStatus->startsWith('exited'))
                            <x-slot:main @click="document.getElementById('selected-resource-remove-trigger')?.click()">
                                <x-reicon name="trash" class="size-3.5" />
                                {{ __('Remove container') }}
                            </x-slot:main>
                        @elseif ($serviceStatus->contains('running') || $serviceStatus->contains('degraded'))
                                    <x-slot:main x-bind:disabled="deploying"
                                        @click="document.getElementById('service-restart-trigger')?.click()">
                                        <x-loading-on-button x-show="deploying" x-cloak />
                                        <x-reicon name="restart" class="size-3.5" x-show="!deploying" />
                                        <span x-text="deploying ? @js(__('Restarting…')) : @js(__('Restart current version'))">{{ __('Restart current version') }}</span>
                                    </x-slot:main>
                                    @if ($serviceStatus->contains('running'))
                                        <button type="button" class="listbox-option justify-start! gap-2.5!"
                                            @click="$wire.dispatch('pullAndRestartEvent'); open = false" role="menuitem">
                                            <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                            {{ __('Pull latest and restart') }}
                                        </button>
                                    @endif
                                    @if ($serviceStatus->contains('degraded'))
                                        <button type="button" class="listbox-option justify-start! gap-2.5!"
                                            @click="$wire.dispatch('forceDeployEvent'); open = false" role="menuitem">
                                            <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                            {{ __('Force Restart') }}
                                        </button>
                                    @endif
                                    <button type="button" class="listbox-option justify-start! gap-2.5!"
                                        @disabled(!auth()->user()->can('stop', $service))
                                        @click="open = false; document.getElementById('service-stop-trigger')?.click()" role="menuitem">
                                        <x-reicon name="stop-circle" class="size-3.5 text-error" />
                                        {{ __('Stop') }}
                                    </button>
                                @else
                                    <x-slot:main x-bind:disabled="deploying" @click="deploying = true; $wire.dispatch('startEvent')">
                                        <x-loading-on-button x-show="deploying" x-cloak />
                                        <x-reicon name="play-circle" class="size-3.5" x-show="!deploying" />
                                        <span x-text="deploying ? @js(__('Deploying…')) : @js(__('Deploy'))">{{ __('Deploy') }}</span>
                                    </x-slot:main>
                                    <button type="button" class="listbox-option justify-start! gap-2.5!"
                                        @click="$wire.dispatch('forceDeployEvent'); open = false" role="menuitem">
                                        <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                        {{ __('Force Deploy') }}
                                    </button>
                                    <button type="button" class="listbox-option justify-start! gap-2.5!"
                                        @disabled(!auth()->user()->can('stop', $service))
                                        @click="$wire.dispatch('cleanupEvent'); open = false" role="menuitem">
                                        <x-reicon name="trash" class="size-3.5 opacity-70" />
                                        {{ __('Force Cleanup Containers') }}
                                    </button>
                                @endif
                            </x-split-action>
                        @endcan
                    @else
                        @can('deploy', $service)
                            <div id="service-desktop-actions" class="relative" x-data="{ open: false }"
                                x-effect="$dispatch('resource-actions-toggled', { open })"
                                @click.outside="open = false" @keydown.escape.window="open = false">
                                <button type="button" class="button button-highlighted" @click="open = !open"
                                    :aria-expanded="open" aria-haspopup="menu">
                                    {{ __('Actions') }}
                                    <x-reicon name="chevron-down" class="size-3 opacity-55" />
                                </button>
                                <div x-cloak x-show="open" x-transition.origin.top.right
                                    class="listbox-panel top-full! right-0! left-auto! mt-1! w-64! min-w-0!" role="menu">
                                    <div class="listbox-option cursor-default! justify-start! gap-2.5! text-neutral-400! dark:text-fg-faint!"
                                        role="menuitem" aria-disabled="true">
                                        <x-reicon name="play-circle" class="size-3.5 opacity-70" />
                                        <span>{{ __('Deploy') }} (<a href="{{ $environmentVariablesUrl }}" {{ wireNavigate() }}
                                                class="cursor-pointer underline underline-offset-2">{{ __('missing required env vars') }}</a>)</span>
                                    </div>
                                </div>
                            </div>
                        @endcan
                    @endif
                </div>
            </div>
        </div>
        @endteleport

    </div>

    @if ($service->isDeployable)
        <div class="hidden" aria-hidden="true">
            <x-modal-confirmation :title="__('Confirm Service Restart?')" :buttonTitle="__('Restart')"
                submitAction="restartEvent" :dispatchAction="true" :actions="[__('This service will be restarted.')]"
                :confirmWithText="false" :confirmWithPassword="false" :step2ButtonText="__('Confirm')">
                <x-slot:trigger>
                    <button id="service-restart-trigger" type="button">{{ __('Restart') }}</button>
                </x-slot:trigger>
            </x-modal-confirmation>
            <x-modal-confirmation :title="__('Confirm Service Stopping?')" :buttonTitle="__('Stop')"
                submitAction="stop" :checkboxes="$checkboxes" :actions="[__('service.stop'), __('resource.non_persistent')]"
                :confirmWithText="false" :confirmWithPassword="false" :step1ButtonText="__('Continue')"
                :step2ButtonText="__('Confirm')">
                <x-slot:trigger>
                    <button id="service-stop-trigger" type="button">{{ __('Stop') }}</button>
                </x-slot:trigger>
            </x-modal-confirmation>
            @if ($selectedResource)
                <x-modal-confirmation :title="__('Confirm Container Removal?')" :buttonTitle="__('Remove container')"
                    canGate="deploy" :canResource="$service" submitAction="removeSelectedResourceContainer"
                    :actions="[__('The exited service resource container will be removed.'), __('resource.non_persistent')]"
                    :confirmWithText="false" :confirmWithPassword="false" :step1ButtonText="__('Continue')"
                    :step2ButtonText="__('Confirm')">
                    <x-slot:trigger>
                        <button id="selected-resource-remove-trigger" type="button">{{ __('Remove container') }}</button>
                    </x-slot:trigger>
                </x-modal-confirmation>
            @endif
        </div>
    @endif

    @script
        <script>
            $wire.$on('stopEvent', () => {
                $wire.$dispatch('info',
                    @js(__('Gracefully stopping service.<br/><br/>It could take a while depending on the service.')));
                $wire.$call('stop');
            });
            $wire.$on('startEvent', async () => {
                try {
                    const isDeploymentProgress = await $wire.$call('checkDeployments');

                    if (isDeploymentProgress) {
                        // A deploy is already running: reopen its live log instead of erroring.
                        $wire.$call('reopenDeployment');
                        return;
                    }

                    await $wire.$call('start');
                } finally {
                    window.dispatchEvent(new CustomEvent('service-deploy-finished'));
                }
            });
            $wire.$on('restartEvent', async () => {
                const isDeploymentProgress = await $wire.$call('checkDeployments');

                if (isDeploymentProgress) {
                    // A deploy is already running: reopen its live log instead of erroring.
                    $wire.$call('reopenDeployment');
                    return;
                }

                window.dispatchEvent(new CustomEvent('service-restarting'));
                $wire.$dispatch('info',
                    @js(__('Gracefully stopping service.<br/><br/>It could take a while depending on the service.')));
                try {
                    await $wire.$call('restart');
                } finally {
                    window.dispatchEvent(new CustomEvent('service-deploy-finished'));
                }
            });
            $wire.$on('forceDeployEvent', () => $wire.$call('forceDeploy'));
            $wire.$on('pullAndRestartEvent', () => {
                $wire.$dispatch('info', @js(__('Pulling new images and restarting service.')));
                $wire.$call('pullAndRestartEvent');
            });
            $wire.$on('cleanupEvent', () => $wire.$call('stop', true));
            $wire.on('imagePulled', () => {
                window.dispatchEvent(new CustomEvent('startservice'));
                $wire.$dispatch('info', @js(__('Restarting service.')));
            });
        </script>
    @endscript
</nav>
