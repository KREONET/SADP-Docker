<div>
    <x-slot:title>
        {{ data_get_str($storage, 'name')->limit(20) }} | Storage | Coolify
    </x-slot>

    @php
        $storageRouteParameters = ['storage_uuid' => $storage->uuid];
        $showSettingsSidebar = in_array($currentRoute, ['storage.show', 'storage.resources', 'storage.danger'], true);
        $settingsMenuItems = [
            [
                'label' => 'General',
                'route' => 'storage.show',
                'active' => $currentRoute === 'storage.show',
                'icon' => 'settings',
            ],
            [
                'label' => 'Resources',
                'route' => 'storage.resources',
                'active' => $currentRoute === 'storage.resources',
                'icon' => 'grid',
            ],
            [
                'label' => 'Danger Zone',
                'route' => 'storage.danger',
                'active' => $currentRoute === 'storage.danger',
                'icon' => 'danger',
            ],
        ];
    @endphp

    <x-dashboard.navbar section="storage" :parameters="$storageRouteParameters"
        :title="$storage->name"
        :subtitle="filled($storage->description) ? $storage->description : __('S3-compatible backup destination')"
        :mobileTitleOnly="true" />

    @if ($showSettingsSidebar)
        <section class="application-settings-workspace mt-4 w-full max-w-none lg:mt-0">
            <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
                <x-main-nav-menu back-label="Storage" back-title="Back to Integrations" :name="$storage->name"
                    data-settings-search-items="{{ json_encode(settingsSearchItems(['S3 storage' => $settingsMenuItems], $storageRouteParameters)) }}">
                    <nav aria-label="{{ __('S3 storage settings') }}"
                        class="grid grid-cols-1 gap-0.5">
                        @foreach ($settingsMenuItems as $menuItem)
                            @if ($menuItem['label'] === 'Danger Zone')
                                <div class="col-span-full my-2 border-t border-neutral-200 dark:border-white/[0.06]" aria-hidden="true"></div>
                            @endif
                            <a wire:key="storage-settings-{{ str($menuItem['label'])->slug() }}"
                                @class([
                                    'menu-item',
                                    'menu-item-active' => $menuItem['active'],
                                ])
                                {{ wireNavigate() }}
                                href="{{ route($menuItem['route'], $storageRouteParameters) }}">
                                <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                                <span class="menu-item-label">{{ __($menuItem['label']) }}</span>
                            </a>
                        @endforeach
                    </nav>
                </x-main-nav-menu>

                <div class="min-w-0">
                    @if ($currentRoute === 'storage.show')
                        <livewire:storage.form :storage="$storage" />
                    @elseif ($currentRoute === 'storage.resources')
                        <livewire:storage.resources :storage="$storage" :key="'resources-'.$storage->uuid" />
                    @elseif ($currentRoute === 'storage.danger')
                        <div class="application-settings-form">
                            <x-application.settings-section id="storage-danger-section" :title="__('Danger zone')"
                                :helper="__('Destructive actions for this S3 storage destination cannot be undone.')">
                                <x-danger-zone :title="__('Delete storage')">
                                            <p>
                                                {!! __('Permanently delete <strong class="font-semibold text-black dark:text-fg">:name</strong> from Coolify. Existing objects in the bucket are not deleted.', ['name' => e($storage->name)]) !!}
                                            </p>
                                            <ul class="space-y-1 text-xs">
                                                <li>• {{ __('Backup schedules pointing at this storage will stop writing to S3.') }}</li>
                                                @if ($backupCount > 0)
                                                    <li>• {{ __(':count backup schedule(s) currently use this destination.', ['count' => $backupCount]) }}</li>
                                                @endif
                                                <li>• {{ __('Bucket contents on the provider are left untouched.') }}</li>
                                                <li>• {{ __('This storage destination cannot be restored from Coolify after deletion.') }}</li>
                                            </ul>
                                        <x-slot:action>
                                            @can('delete', $storage)
                                                <x-modal-confirmation :title="__('Confirm Storage Deletion?')" isErrorButton
                                                    :buttonTitle="__('Delete')" submitAction="delete"
                                                    :actions="array_filter([
                                                        __('The selected storage location will be permanently deleted from Coolify.'),
                                                        $backupCount > 0
                                                            ? __(':count backup schedule(s) will stop saving to S3. Existing objects in this storage will not be deleted.', ['count' => $backupCount])
                                                            : null,
                                                    ])"
                                                    confirmationText="{{ $storage->name }}"
                                                    :confirmationLabel="__('Please confirm the execution of the actions by entering the Storage Name below')"
                                                    :shortConfirmationLabel="__('Storage Name')" :confirmWithPassword="false"
                                                    :step2ButtonText="__('Permanently Delete')" />
                                            @else
                                                <x-forms.button isError disabled :tooltip="__('You do not have permission to delete this storage.')">
                                                    {{ __('Delete') }}
                                                </x-forms.button>
                                            @endcan
                                        </x-slot:action>
                                </x-danger-zone>

                                @cannot('delete', $storage)
                                    <div class="mt-4">
                                        <x-callout type="danger" :title="__('Insufficient permissions')">
                                            {{ __('Contact a team administrator if this storage must be deleted.') }}
                                        </x-callout>
                                    </div>
                                @endcannot
                            </x-application.settings-section>
                        </div>
                    @endif
                </div>
            </div>
        </section>
    @endif
</div>
