@php
    $hasSourcePaths = $resource->persistentStorages->contains(fn ($storage) => filled($storage->host_path));
    $gridClass = match (true) {
        $supportsPreviewSuffix => 'volumes-table-grid-with-pr',
        $showActionsColumn => 'volumes-table-grid',
        default => 'volumes-table-grid-readonly',
    };
@endphp

<div class="flex w-full flex-col">
    @if (data_get($resource, 'build_pack') === 'dockercompose')
        <div
            class="border-b border-neutral-200 px-4 py-3 text-[13px] leading-5 text-amber-800 dark:border-white/[0.08] dark:text-amber-300/90">
            {{ __('Docker Compose volume mounts are read-only here. Edit the compose file and reload it to change volumes.') }}
        </div>
    @endif

    @if ($resource->persistentStorages->isNotEmpty())
        <div class="data-table w-full">
            <div class="data-table-header {{ $gridClass }} {{ $hasSourcePaths ? 'has-source' : '' }}">
                <span>{{ __('Storage Name') }}</span>
                @if ($hasSourcePaths)
                    <span>{{ __('Source Path') }}</span>
                @endif
                <span>{{ __('Destination Path') }}</span>
                @if ($supportsPreviewSuffix)
                    <div class="volumes-col-pr flex items-center gap-1.5">
                        <span>{{ __('PR suffix') }}</span>
                        <x-helper :helper="__('Adds -pr-N to the storage name or path so each preview uses isolated data. Disabling it shares production data with previews.')" />
                    </div>
                @endif
                <span class="volumes-col-backup text-center">{{ __('Backup') }}</span>
                @if ($showActionsColumn)
                    <span class="volumes-col-actions text-right">{{ __('Actions') }}</span>
                @endif
            </div>

            @foreach ($this->storages as $storage)
                @php
                    $id = $storage->id;
                    $form = $forms[$id] ?? null;
                    if (! $form) {
                        continue;
                    }
                    $backupMeta = $volumeBackupMeta[$id] ?? ['enabled' => false, 's3' => false, 'url' => null];
                    $hasEnabledBackup = $backupMeta['enabled'];
                    $hasS3Backup = $backupMeta['s3'];
                    $backupUrl = $backupMeta['url'];
                    $inputsReadonly = $form['isReadOnly'];
                @endphp

                @if ($inputsReadonly)
                    <div class="env-table-item" wire:key="storage-row-{{ $id }}">
                        <div class="data-table-row {{ $gridClass }} {{ $hasSourcePaths ? 'has-source' : '' }} text-[13px] text-neutral-700 dark:text-fg-dim">
                            <div class="volumes-cell-name min-w-0">
                                <span class="volumes-mobile-label volumes-field-label">{{ __('Storage Name') }}</span>
                                <div class="flex min-w-0 items-center gap-2">
                                    <span
                                        class="min-w-0 truncate text-[13px] font-medium text-neutral-950 dark:text-fg"
                                        title="{{ $form['name'] }}">{{ $form['name'] }}</span>
                                </div>
                                @if (blank($storage->host_path))
                                    <span class="block text-xs text-neutral-500 dark:text-fg-dim">{{ __('Volume mount') }}</span>
                                @endif
                                @if ($form['replacedExternalVolume'])
                                    <span class="block text-xs text-amber-800 dark:text-amber-300/90">
                                        {{ __('Replaces the external volume \':volume\'. Copy the data into the external volume, then delete this entry to use it.', ['volume' => $form['replacedExternalVolume']]) }}
                                    </span>
                                @endif
                            </div>

                            @if ($hasSourcePaths)
                                <div class="volumes-cell-source min-w-0">
                                    <span class="volumes-mobile-label volumes-field-label">{{ __('Source Path') }}</span>
                                    @if (filled($storage->host_path))
                                        <x-forms.input :aria-label="__('Source Path')" :value="$storage->host_path" readonly />
                                    @else
                                        <span class="data-table-cell-dash">-</span>
                                    @endif
                                </div>
                            @endif

                            <div class="volumes-cell-dest min-w-0">
                                <span class="volumes-mobile-label volumes-field-label">{{ __('Destination Path') }}</span>
                                <span
                                    class="block min-w-0 truncate text-[13px] text-neutral-950 dark:text-fg"
                                    title="{{ $form['mountPath'] }}">{{ $form['mountPath'] }}</span>
                            </div>

                            @if ($supportsPreviewSuffix)
                                <div class="volumes-col-pr min-w-0">
                                    <div class="volumes-mobile-label volumes-field-label flex items-center gap-1.5">
                                        <span>{{ __('PR suffix') }}</span>
                                        <x-helper :helper="__('Adds -pr-N to the storage name or path so each preview uses isolated data. Disabling it shares production data with previews.')" />
                                    </div>
                                    <span>{{ $form['isPreviewSuffixEnabled'] ? __('Add suffix') : __('Share volume') }}</span>
                                </div>
                            @endif

                            <div class="volumes-col-backup flex items-center justify-center gap-1.5">
                                <span class="volumes-mobile-label volumes-field-label">{{ __('Backup') }}</span>
                                @if ($hasEnabledBackup)
                                    @if ($backupUrl)
                                        <a href="{{ $backupUrl }}" @class([
                                            'table-badge underline-offset-2 hover:underline',
                                            'table-badge-success' => $hasS3Backup,
                                        ])
                                            title="{{ __('Volume backup is enabled') }}"
                                            aria-label="{{ $hasS3Backup ? __('Backups are saved to S3') : __('Backups are stored locally only') }}">
                                            {{ $hasS3Backup ? 'S3' : __('Local') }}
                                        </a>
                                    @else
                                        <span @class(['table-badge', 'table-badge-success' => $hasS3Backup])
                                            title="{{ __('Volume backup is enabled') }}"
                                            aria-label="{{ $hasS3Backup ? __('Backups are saved to S3') : __('Backups are stored locally only') }}">
                                            {{ $hasS3Backup ? 'S3' : __('Local') }}
                                        </span>
                                    @endif
                                @else
                                    <span class="data-table-cell-dash">-</span>
                                @endif
                            </div>

                            @if ($showBackupAction)
                                <div
                                    class="volumes-col-actions volumes-cell-actions flex flex-nowrap items-center justify-end gap-1.5">
                                    @if ($canUpdate)
                                        <x-modal-input :title="__('Configure Volume Backup')" :wireIgnore="false">
                                            <x-slot:content>
                                                <x-forms.button type="button" class="!px-2.5 !text-xs" canGate="update"
                                                    :canResource="$resource">
                                                    {{ __('Backup') }}
                                                </x-forms.button>
                                            </x-slot:content>
                                            @if ($resource instanceof \App\Models\Application)
                                                <livewire:project.application.backup.create :application="$resource"
                                                    :selected-target-key="'volume:' . $id"
                                                    wire:key="configure-volume-backup-{{ $id }}" />
                                            @else
                                                <livewire:project.service.volume-backup.create :service="$resource->service"
                                                    :selected-target-key="'volume:' . $id"
                                                    wire:key="configure-service-volume-backup-{{ $id }}" />
                                            @endif
                                        </x-modal-input>
                                    @else
                                        <span class="text-neutral-400 dark:text-fg-faint">—</span>
                                    @endif

                                    @if ($form['canDeleteStale'])
                                        <x-modal-confirmation :title="__('Remove stale volume entry?')" isErrorButton
                                            :buttonTitle="__('Delete stale volume entry')" submitAction="delete({{ $id }})"
                                            :checkboxes="[[
                                                'id' => 'deleteDockerVolume',
                                                'label' => __('Also permanently delete the Docker volume and all its data.'),
                                                'default_warning' => __('The Docker volume and its data will not be deleted.'),
                                            ]]"
                                            :actions="array_values(array_filter([
                                                __('This removes only the stale volume entry from Coolify.'),
                                                $form['replacedExternalVolume']
                                                    ? __('The next deployment uses the external volume \':volume\' instead of this volume.', ['volume' => $form['replacedExternalVolume']])
                                                    : null,
                                            ]))" confirmationText="{{ $form['name'] }}"
                                            :confirmationLabel="__('Please confirm by entering the Storage Name below')"
                                            :shortConfirmationLabel="__('Storage Name')" />
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                @else
                    <form wire:submit="submit({{ $id }})" class="env-table-item" wire:key="storage-row-{{ $id }}">
                        <div class="data-table-row {{ $gridClass }} {{ $hasSourcePaths ? 'has-source' : '' }}">
                            <div class="volumes-cell-name min-w-0">
                                <span class="volumes-mobile-label volumes-field-label">{{ __('Storage Name') }}</span>
                                <div class="flex min-w-0 items-center gap-2">
                                    <div class="min-w-0 flex-1">
                                        <x-forms.input id="forms.{{ $id }}.name" required :readonly="$form['isShared']" />
                                    </div>
                                </div>
                                @if ($storage->standaloneSqlite)
                                    <a href="{{ $storage->standaloneSqlite->link() }}"
                                        class="block text-xs text-neutral-500 underline underline-offset-2 hover:text-black dark:text-fg-dim dark:hover:text-fg">{{ __('SQLite database :name', ['name' => $storage->standaloneSqlite->name]) }}</a>
                                @elseif (blank($storage->host_path))
                                    <span class="block text-xs text-neutral-500 dark:text-fg-dim">{{ __('Volume mount') }}</span>
                                @endif
                            </div>

                            @if ($hasSourcePaths)
                                <div class="volumes-cell-source min-w-0">
                                    <span class="volumes-mobile-label volumes-field-label">{{ __('Source Path') }}</span>
                                    @if (filled($storage->host_path))
                                        <x-forms.input :aria-label="__('Source Path')" :value="$storage->host_path" readonly />
                                    @else
                                        <span class="data-table-cell-dash">-</span>
                                    @endif
                                </div>
                            @endif

                            <div class="volumes-cell-dest min-w-0">
                                <span class="volumes-mobile-label volumes-field-label">{{ __('Destination Path') }}</span>
                                <x-forms.input id="forms.{{ $id }}.mountPath" required
                                    placeholder="/path/in/container" />
                            </div>

                            @if ($supportsPreviewSuffix)
                                <div class="volumes-col-pr min-w-0">
                                    <div class="volumes-mobile-label volumes-field-label flex items-center gap-1.5">
                                        <span>{{ __('PR suffix') }}</span>
                                        <x-helper :helper="__('Adds -pr-N to the storage name or path so each preview uses isolated data. Disabling it shares production data with previews.')" />
                                    </div>
                                    <x-forms.listbox id="forms.{{ $id }}.isPreviewSuffixEnabled" portal :options="[
                                        ['value' => true, 'label' => __('Add suffix')],
                                        ['value' => false, 'label' => __('Share volume')],
                                    ]" canGate="update" :canResource="$resource" />
                                </div>
                            @endif

                            <div class="volumes-col-backup flex items-center justify-center gap-1.5">
                                <span class="volumes-mobile-label volumes-field-label">{{ __('Backup') }}</span>
                                @if ($hasEnabledBackup)
                                    @if ($backupUrl)
                                        <a href="{{ $backupUrl }}" @class([
                                            'table-badge underline-offset-2 hover:underline',
                                            'table-badge-success' => $hasS3Backup,
                                        ])
                                            title="{{ __('Volume backup is enabled') }}"
                                            aria-label="{{ $hasS3Backup ? __('Backups are saved to S3') : __('Backups are stored locally only') }}">
                                            {{ $hasS3Backup ? 'S3' : __('Local') }}
                                        </a>
                                    @else
                                        <span @class(['table-badge', 'table-badge-success' => $hasS3Backup])
                                            title="{{ __('Volume backup is enabled') }}"
                                            aria-label="{{ $hasS3Backup ? __('Backups are saved to S3') : __('Backups are stored locally only') }}">
                                            {{ $hasS3Backup ? 'S3' : __('Local') }}
                                        </span>
                                    @endif
                                @else
                                    <span class="data-table-cell-dash">-</span>
                                @endif
                            </div>

                            <div
                                class="volumes-col-actions volumes-cell-actions flex flex-nowrap items-center justify-end gap-1.5">
                                <x-forms.button type="submit" class="!px-2.5 !text-xs">
                                    {{ __('Update') }}
                                </x-forms.button>

                                @if ($showBackupAction && ! $form['isShared'])
                                    <x-modal-input :title="__('Configure Volume Backup')" :wireIgnore="false">
                                        <x-slot:content>
                                            <x-forms.button type="button" class="!px-2.5 !text-xs" canGate="update"
                                                :canResource="$resource">
                                                {{ __('Backup') }}
                                            </x-forms.button>
                                        </x-slot:content>
                                        @if ($resource instanceof \App\Models\Application)
                                            <livewire:project.application.backup.create :application="$resource"
                                                :selected-target-key="'volume:' . $id"
                                                wire:key="configure-volume-backup-{{ $id }}" />
                                        @else
                                            <livewire:project.service.volume-backup.create :service="$resource->service"
                                                :selected-target-key="'volume:' . $id"
                                                wire:key="configure-service-volume-backup-{{ $id }}" />
                                        @endif
                                    </x-modal-input>
                                @elseif (method_exists($resource, 'isBackupSolutionAvailable') && $resource->isBackupSolutionAvailable())
                                    <x-modal-input :title="__('New Scheduled Backup')" :wireIgnore="false">
                                        <x-slot:content>
                                            <x-forms.button type="button" class="!px-2.5 !text-xs" canGate="update"
                                                :canResource="$resource">
                                                {{ __('Backup') }}
                                            </x-forms.button>
                                        </x-slot:content>
                                        <livewire:project.database.create-scheduled-backup :database="$resource"
                                            wire:key="configure-database-backup-{{ $id }}" />
                                    </x-modal-input>
                                @endif

                                @unless ($form['isShared'])
                                <x-modal-confirmation :title="__('Confirm persistent storage deletion?')" isErrorButton
                                    :buttonTitle="__('Delete')" submitAction="delete({{ $id }})" :actions="[
                                        __('The selected persistent storage/volume will be permanently deleted.'),
                                        __('If the persistent storage/volume is actvily used by a resource data will be lost.'),
                                    ]" confirmationText="{{ $form['name'] }}"
                                    :confirmationLabel="__('Please confirm the execution of the actions by entering the Storage Name below')"
                                    :shortConfirmationLabel="__('Storage Name')" />
                                @endunless
                            </div>
                        </div>
                    </form>
                @endif
            @endforeach
        </div>
    @endif

</div>
