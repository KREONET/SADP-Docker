<nav wire:poll.10000ms="checkStatus" class="w-full max-w-none pb-4 md:pb-6 lg:pb-0">
    @php
        $databasePageItems = [
            [
                'label' => __('Settings'),
                'route' => 'project.database.configuration',
                'active' => request()->routeIs('project.database.configuration')
                    || request()->routeIs('project.database.environment-variables')
                    || request()->routeIs('project.database.servers')
                    || request()->routeIs('project.database.import-backup')
                    || request()->routeIs('project.database.persistent-storage')
                    || request()->routeIs('project.database.healthcheck')
                    || request()->routeIs('project.database.webhooks')
                    || request()->routeIs('project.database.resource-limits')
                    || request()->routeIs('project.database.resource-operations')
                    || request()->routeIs('project.database.metrics')
                    || request()->routeIs('project.database.tags')
                    || request()->routeIs('project.database.danger'),
            ],
            [
                'label' => __('Backups'),
                'route' => 'project.database.backup.index',
                'active' => request()->routeIs('project.database.backup.*'),
                'visible' => $database->isBackupSolutionAvailable(),
            ],
            [
                'label' => __('Runtime Logs'),
                'route' => 'project.database.logs',
                'active' => request()->routeIs('project.database.logs'),
                'navigate' => false,
            ],
            [
                'label' => __('Terminal'),
                'route' => 'project.database.command',
                'active' => request()->routeIs('project.database.command'),
                'navigate' => false,
                'visible' => auth()->user()?->can('canAccessTerminal'),
            ],
        ];

        $databasePageItems = array_values(array_filter(
            $databasePageItems,
            fn (array $item): bool => $item['visible'] ?? true,
        ));

        $databaseStatus = str($database->status ?? 'exited');
    @endphp

    <livewire:project.shared.configuration-checker :resource="$database" />

    <x-process-dialog @startdatabase.window="processDialogOpen = true" closeWithX>
        <x-slot:title>{{ __('Database Startup') }}</x-slot:title>
        <x-slot:content>
            <div wire:ignore class="flex h-full min-h-0 min-w-0 max-w-full flex-col">
                <livewire:activity-monitor :header="__('Logs')" fullHeight />
            </div>
        </x-slot:content>
    </x-process-dialog>

    <div x-data="{ busy: false }" @database-busy.window="busy = true"
        @database-action-finished.window="busy = false">
        <div class="mb-3 w-full xl:hidden">
            <div class="flex min-w-0 flex-col items-start gap-2">
                <h1 class="min-w-0 max-w-full truncate text-[24px]! leading-7! font-semibold! tracking-tight! text-black dark:text-fg">
                    {{ $database->name }}
                </h1>
                <div class="relative flex w-full min-w-0 items-center gap-2">
                    <x-status-summary :status="$database->status" :title="__('Database status')"
                        :healthcheck-url="route('project.database.healthcheck', $parameters)" />
                    @if ($isDeploymentProgress)
                        <x-deploying-indicator :label="__('Working')" />
                    @endif
                </div>
                <div class="flex w-full flex-wrap gap-1">
                    <x-application.restart-limit-warning :application="$database" />
                </div>
            </div>
        </div>

        <div class="w-full xl:hidden">
            @if ($database->destination->server->isFunctional())
                @can('manage', $database)
                    <x-split-action id="database-mobile-actions" class="mb-3 flex w-full">
                        @if (! $databaseStatus->startsWith('exited'))
                            <x-slot:main x-bind:disabled="busy"
                                @click="document.getElementById('database-restart-trigger')?.click()">
                                <x-loading-on-button x-show="busy" x-cloak />
                                <x-reicon name="restart" class="size-3.5" x-show="!busy" />
                                <span x-text="busy ? @js(__('Restarting…')) : @js(__('Restart'))">{{ __('Restart') }}</span>
                            </x-slot:main>
                            <button type="button" class="listbox-option justify-start! gap-2.5!"
                                @click="open = false; document.getElementById('database-stop-trigger')?.click()" role="menuitem">
                                <x-reicon name="stop-circle" class="size-3.5 text-error" />
                                {{ __('Stop') }}
                            </button>
                        @else
                            <x-slot:main x-bind:disabled="busy" @click="busy = true; $wire.dispatch('startEvent')">
                                <x-loading-on-button x-show="busy" x-cloak />
                                <x-reicon name="play-circle" class="size-3.5" x-show="!busy" />
                                <span x-text="busy ? @js(__('Starting…')) : @js(__('Start'))">{{ __('Start') }}</span>
                            </x-slot:main>
                        @endif
                    </x-split-action>
                @endcan
            @endif

        </div>

        @teleport('#resource-action-hud-slot')
        <div class="hidden w-full items-center xl:flex xl:w-auto">
            <div
                class="resource-heading-navbar application-heading-actions flex w-auto min-w-0 items-center justify-end gap-1 overflow-visible">
                <div class="resource-heading-actions flex shrink-0 items-center gap-0.5">
                    @if ($isDeploymentProgress)
                        <x-deploying-indicator :label="__('Working')" class="mr-1" />
                    @endif
                    @if ($database->destination->server->isFunctional())
                        @can('manage', $database)
                            <x-split-action id="database-desktop-actions">
                                @if (! $databaseStatus->startsWith('exited'))
                                    <x-slot:main x-bind:disabled="busy"
                                        @click="document.getElementById('database-restart-trigger')?.click()">
                                        <x-loading-on-button x-show="busy" x-cloak />
                                        <x-reicon name="restart" class="size-3.5" x-show="!busy" />
                                        <span x-text="busy ? @js(__('Restarting…')) : @js(__('Restart'))">{{ __('Restart') }}</span>
                                    </x-slot:main>
                                    <button type="button" class="listbox-option justify-start! gap-2.5!"
                                        @click="open = false; document.getElementById('database-stop-trigger')?.click()" role="menuitem">
                                        <x-reicon name="stop-circle" class="size-3.5 text-error" />
                                        {{ __('Stop') }}
                                    </button>
                                @else
                                    <x-slot:main x-bind:disabled="busy" @click="busy = true; $wire.dispatch('startEvent')">
                                        <x-loading-on-button x-show="busy" x-cloak />
                                        <x-reicon name="play-circle" class="size-3.5" x-show="!busy" />
                                        <span x-text="busy ? @js(__('Starting…')) : @js(__('Start'))">{{ __('Start') }}</span>
                                    </x-slot:main>
                                @endif
                            </x-split-action>
                        @endcan
                    @else
                        <x-status-badge :status="__('Server unavailable')" type="error" />
                    @endif
                </div>
            </div>
        </div>
        @endteleport

    </div>

    @if ($database->destination->server->isFunctional())
        <div class="hidden" aria-hidden="true">
            <x-modal-confirmation :title="__('Confirm Database Restart?')" :buttonTitle="__('Restart')"
                submitAction="restartEvent" :actions="[
                    __('This database will be unavailable during the restart.'),
                    __('If the database is currently in use, data could be lost.'),
                ]" :confirmWithText="false" :confirmWithPassword="false" :step2ButtonText="__('Restart Database')"
                :dispatchAction="true">
                <x-slot:trigger>
                    <button id="database-restart-trigger" type="button">{{ __('Restart') }}</button>
                </x-slot:trigger>
            </x-modal-confirmation>
            <x-modal-confirmation :title="__('Confirm Database Stopping?')" :buttonTitle="__('Stop')" submitAction="stop"
                :checkboxes="$checkboxes" :actions="[
                    __('This database will be stopped.'),
                    __('If the database is currently in use, data could be lost.'),
                    __('Non-persistent containers, networks, and unused images will be removed.'),
                ]" :confirmWithText="false" :confirmWithPassword="false"
                :step1ButtonText="__('Continue')" :step2ButtonText="__('Confirm')">
                <x-slot:trigger>
                    <button id="database-stop-trigger" type="button">{{ __('Stop') }}</button>
                </x-slot:trigger>
            </x-modal-confirmation>
        </div>
    @endif

    @script
        <script>
            $wire.$on('startEvent', async () => {
                if (await $wire.$call('checkDeployments')) {
                    // An operation is already running: reopen its live log.
                    $wire.$call('reopenDeployment');
                    window.dispatchEvent(new CustomEvent('database-action-finished'));
                    return;
                }
                window.dispatchEvent(new CustomEvent('startdatabase'));
                try {
                    await $wire.$call('start');
                } finally {
                    window.dispatchEvent(new CustomEvent('database-action-finished'));
                }
            });
            $wire.$on('restartEvent', async () => {
                if (await $wire.$call('checkDeployments')) {
                    // An operation is already running: reopen its live log.
                    $wire.$call('reopenDeployment');
                    return;
                }
                window.dispatchEvent(new CustomEvent('database-busy'));
                $wire.$dispatch('info', @js(__('Restarting database.')));
                window.dispatchEvent(new CustomEvent('startdatabase'));
                try {
                    await $wire.$call('restart');
                } finally {
                    window.dispatchEvent(new CustomEvent('database-action-finished'));
                }
            });
        </script>
    @endscript
</nav>
