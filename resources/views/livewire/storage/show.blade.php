<div>
    <x-slot:title>
        {{ data_get_str($storage, 'name')->limit(20) }} | {{ __('S3 Storage') }} | {{ brand_name() }}
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
                'icon' => 'shield-alert',
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
                <aside class="application-settings-navigation min-w-0 xl:self-start">
                    <nav aria-label="{{ __('S3 storage settings') }}"
                        class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
                        <div class="nav-section hidden xl:block">{{ __('Settings') }}</div>
                        @foreach ($settingsMenuItems as $menuItem)
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
                </aside>

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
