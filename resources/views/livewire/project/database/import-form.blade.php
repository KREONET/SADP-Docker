<div x-data="{
    error: $wire.entangle('error'),
    filesize: $wire.entangle('filesize'),
    filename: $wire.entangle('filename'),
    isUploading: $wire.entangle('isUploading'),
    progress: $wire.entangle('progress'),
    s3FileSize: $wire.entangle('s3FileSize'),
    s3StorageId: $wire.entangle('s3StorageId'),
    s3Path: $wire.entangle('s3Path'),
    restoreType: null,
    {{-- Dropzone attaches here, not by auto-discovery: the form can render after page load (in a modal, or when the database starts). --}}
    mountDropzone(element) {
        const wire = this.$wire;
        const attach = () => {
            window.Dropzone.autoDiscover = false;
            if (element.dropzone) {
                return;
            }
            new window.Dropzone(element, {
                chunking: true,
                method: 'POST',
                maxFilesize: 1000000000,
                chunkSize: 10000000,
                createImageThumbnails: false,
                disablePreviews: true,
                parallelChunkUploads: false,
                dictDefaultMessage: 'Select or drop a backup file here.',
                init() {
                    this.on('sending', (file, xhr, formData) => {
                        formData.append('_token', document.querySelector('meta[name=csrf-token]').getAttribute('content'));
                    });
                    this.on('addedfile', () => {
                        wire.isUploading = true;
                        wire.customLocation = '';
                    });
                    this.on('uploadprogress', (file, progress) => {
                        wire.progress = progress;
                    });
                    this.on('complete', (file) => {
                        wire.filename = file.name;
                        if (file.status === window.Dropzone.SUCCESS) {
                            wire.selectUploadedFile(file.name);
                        }
                        wire.filesize = Number(file.size / 1024 / 1024).toFixed(2) + ' MB';
                        wire.isUploading = false;
                    });
                    this.on('error', (file, message) => {
                        wire.error = true;
                        wire.$dispatch('error', message.error);
                    });
                },
            });
        };
        if (window.Dropzone) {
            attach();
            return;
        }
        let script = document.querySelector('script[data-dropzone]');
        if (! script) {
            script = document.createElement('script');
            script.src = @js(URL::asset('js/dropzone.js'));
            script.dataset.dropzone = '';
            document.head.appendChild(script);
        }
        script.addEventListener('load', attach, { once: true });
    },
}">
        <div class="flex flex-col gap-6">
            <x-callout type="danger" :title="__('Restoring a backup changes database data')">
                {{ __('Review the source and import command before continuing. Existing objects can cause the import to fail unless replacement is enabled.') }}
            </x-callout>

            <section>
                <h3 class="text-sm font-semibold text-black dark:text-fg">{{ __('Restore configuration') }}</h3>
                <p class="mt-0.5 text-xs text-neutral-500 dark:text-fg-dim">{{ __('Configure how the selected backup is applied to this database.') }}</p>
                <div class="mt-4 space-y-4">
            @if ($resourceDbType === 'standalone-postgresql' && $dumpAll)
                            <x-callout type="warning" :title="__('Full restore runs as a superuser')">
                                {{ __('Restoring all databases needs a PostgreSQL superuser (it recreates databases and roles), so Coolify cannot restrict it. Only use a backup you trust.') }}
                                <span class="mt-1 block">{{ __('The backup also replaces PostgreSQL administrator role passwords, including the destination administrator password. If the administrator password changes, update it in Coolify\'s database configuration after the restore.') }}</span>
                            </x-callout>
            @endif
                    @if ($resourceDbType !== 'standalone-sqlite')
                    <div class="max-w-sm">
                        <x-forms.listbox id="dumpAll" :label="__('Backup contents')" live :options="[
                            ['value' => true, 'label' => __('Backup contains all databases')],
                            ['value' => false, 'label' => __('Backup contains one database')],
                        ]" />
                    </div>
                    @endif
                    @if (in_array($resourceDbType, ['standalone-postgresql', 'postgresql'], true) && ! $dumpAll)
                        <div class="max-w-sm">
                            <x-forms.checkbox id="replaceExisting" live :label="__('Replace objects that already exist')"
                                :helper="__('Archive backups: drops matching tables, functions, types, and other PostgreSQL objects before restoring them. SQL backups: recreates the database before the restore.')"
                                canGate="update" :canResource="$this->resource" />
                        </div>
                        <div class="max-w-sm">
                            <x-forms.checkbox id="keepOwners" live :label="__('Keep owners and privileges')"
                                :helper="__('Archive backups: restores object owners and GRANTs from the backup. Leave this off for backups from another server (for example Amazon RDS): its roles usually do not exist here, and the whole restore would be rolled back.')"
                                canGate="update" :canResource="$this->resource" />
                        </div>
                    @endif
                    @if (in_array($resourceDbType, ['standalone-mysql', 'standalone-mariadb', 'mysql', 'mariadb'], true) && $dumpAll)
                        <div class="max-w-sm">
                            <x-forms.checkbox id="restoreMysqlUsers" live :label="__('Restore users and privileges (mysql system database)')"
                                :helper="__('Off: the system databases (mysql, sys) of the backup are skipped, so this database keeps its own users and passwords. On: the users, passwords, and privileges of the backup replace the current ones.')"
                                canGate="update" :canResource="$this->resource" />
                        </div>
                        @if ($restoreMysqlUsers)
                            <x-callout type="warning" :title="__('Restoring users changes passwords')">
                                {{ __('The backup replaces all users, passwords, and privileges, including the root password. After the next restart of the database, the passwords from the backup apply, so the credentials Coolify stores for this database, and its health check, may stop working.') }}
                                <span class="mt-1 block">{{ __('Update the passwords in Coolify\'s database configuration after the restore.') }}</span>
                            </x-callout>
                        @endif
                    @endif
                    @if ($resourceDbType === 'standalone-sqlite' && count($this->sqliteDatabaseFiles) > 0)
                        <div class="max-w-sm">
                            <x-forms.listbox id="sqliteDatabase" :label="__('Restore into')" live
                                :helper="__('The database file the backup replaces. Coolify preselects the file named in the backup file name.')"
                                :options="collect($this->sqliteDatabaseFiles)->map(fn ($file) => ['value' => $file, 'label' => $file])->all()"
                                canGate="update" :canResource="$this->resource" />
                        </div>
                    @endif
                    @if ($resourceDbType === 'standalone-mongodb')
                        <div class="max-w-sm">
                            <x-forms.checkbox id="replaceExisting" live :label="__('Replace collections that already exist')"
                                :helper="__('Drops each collection from the backup before restoring it. Without this option, documents that already exist are skipped.')"
                                canGate="update" :canResource="$this->resource" />
                        </div>
                    @endif
                    <div x-data="{ showCommand: false }">
                        <button type="button" @click="showCommand = !showCommand" :aria-expanded="showCommand"
                            class="inline-flex cursor-pointer items-center gap-1.5 text-xs font-medium text-neutral-500 hover:text-black dark:text-fg-dim dark:hover:text-fg">
                            <x-reicon name="chevron-down" class="size-3.5 transition-transform"
                                ::class="showCommand ? '' : '-rotate-90'" />
                            <span x-text="showCommand ? 'Hide import command' : 'Show import command'">{{ __('Show import command') }}</span>
                        </button>
                        <div x-cloak x-show="showCommand" class="mt-3">
                            <x-forms.textarea rows="10" readonly :label="__('Import command')"
                                :helper="__('Coolify detects the backup format (SQL, archive, gzip, bz2, xz, zip, or tar) before it changes the database.')"
                                wire:model="restoreCommandText" canGate="update"
                                :canResource="$this->resource" />
                        </div>
                    </div>
                </div>
            </section>

            <section class="border-t border-neutral-200 pt-6 dark:border-white/[0.06]">
                <h3 class="text-sm font-semibold text-black dark:text-fg">{{ __('Backup source') }}</h3>
                <p class="mt-0.5 text-xs text-neutral-500 dark:text-fg-dim">{{ __('Choose a local file or a file in a backup storage.') }}</p>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <button type="button" @click="restoreType = 'file'"
                        class="flex min-h-20 items-center gap-3 rounded-[10px] border p-3 text-left transition-colors"
                        :class="restoreType === 'file'
                            ? 'border-coollabs/35 bg-coollabs/[0.06] text-coollabs dark:border-brand-light/30 dark:bg-brand/[0.08] dark:text-brand-light'
                            : 'border-neutral-200 bg-white hover:border-neutral-300 dark:border-white/[0.08] dark:bg-white/[0.05] dark:hover:border-white/[0.14]'">
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 dark:bg-white/[0.06]">
                            <x-reicon name="file" class="size-4" />
                        </span>
                        <span>
                            <span class="block text-[13px] font-semibold">{{ __('File') }}</span>
                            <span class="mt-0.5 block text-[11px] text-neutral-500 dark:text-fg-faint">{{ __('Upload a backup or use a server path.') }}</span>
                        </span>
                    </button>

                @if (count($availableS3Storages) > 0)
                        <button type="button" @click="restoreType = 's3'"
                            class="flex min-h-20 items-center gap-3 rounded-[10px] border p-3 text-left transition-colors"
                            :class="restoreType === 's3'
                                ? 'border-coollabs/35 bg-coollabs/[0.06] text-coollabs dark:border-brand-light/30 dark:bg-brand/[0.08] dark:text-brand-light'
                                : 'border-neutral-200 bg-white hover:border-neutral-300 dark:border-white/[0.08] dark:bg-white/[0.05] dark:hover:border-white/[0.14]'">
                            <span
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 dark:bg-white/[0.06]">
                                <x-reicon name="storages" class="size-4" />
                            </span>
                            <span>
                                <span class="block text-[13px] font-semibold">{{ __('Storage') }}</span>
                                <span class="mt-0.5 block text-[11px] text-neutral-500 dark:text-fg-faint">{{ __('Download a backup from a backup storage.') }}</span>
                            </span>
                        </button>
                @endif
                </div>

            {{-- File Restore Section --}}
            @can('update', $this->resource)
                <div x-cloak x-show="restoreType === 'file'"
                    class="mt-4">
                    <form class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div class="min-w-0 flex-1">
                            @php
                                $serverPathHelper = __('Only files inside these directories can be imported: :directories. Encrypted backups (.enc) that Coolify created are decrypted automatically.', [
                                    'directories' => implode(', ', \App\Support\DatabaseImport\ServerImportPath::allowedDirectories()),
                                ]);
                            @endphp
                            <x-forms.input :label="__('File path on the server')"
                                :helper="$serverPathHelper"
                                placeholder="/data/coolify/backups/backup.sql.gz" wire:model="customLocation"
                                x-model="$wire.customLocation" canGate="update"
                                :canResource="$this->resource" />
                        </div>
                        <x-forms.button wire:click="checkFile" x-bind:disabled="!$wire.customLocation"
                            canGate="update" :canResource="$this->resource">{{ __('Check file') }}</x-forms.button>
                    </form>

                    <div class="my-4 flex items-center gap-3 text-[10px] font-medium uppercase tracking-wide text-neutral-400 dark:text-fg-faint">
                        <span class="h-px flex-1 bg-neutral-200 dark:bg-white/[0.08]"></span>
                        {{ __('or upload') }}
                        <span class="h-px flex-1 bg-neutral-200 dark:bg-white/[0.08]"></span>
                    </div>

                    <form action="{{ route('upload.backup', ['databaseUuid' => $resourceUuid]) }}"
                        class="dropzone rounded-lg! border! border-dashed! border-neutral-300! bg-white! dark:border-white/[0.12]! dark:bg-white/[0.05]!"
                        x-init="mountDropzone($el)" wire:ignore>
                        @csrf
                    </form>
                    <div x-show="isUploading" class="mt-3 h-1.5 overflow-hidden rounded-full bg-neutral-200 dark:bg-white/[0.08]">
                        <div class="h-full rounded-full bg-coollabs dark:bg-brand"
                            :style="`width: ${progress}%`"></div>
                    </div>

                    <div x-cloak x-show="filename && !error"
                        class="mt-4 flex flex-col gap-3 rounded-lg border border-neutral-200 bg-white p-3 dark:border-white/[0.08] dark:bg-white/[0.03] sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="truncate text-[12px] font-medium" x-text="filename ?? 'N/A'"></p>
                            <p class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-faint"
                                x-show="filesize" x-text="filesize"></p>
                        </div>
                        <div class="shrink-0">
                            <x-modal-confirmation :title="__('Restore Database from File?')" :buttonTitle="__('Restore from File')"
                                submitAction="runImport" isErrorButton>
                                <x-slot:button-title>
                                    {{ __('Restore from file') }}
                                </x-slot:button-title>
                                {{ __('This will:') }}
                                <ul class="list-disc list-inside pt-2">
                                    <li>{{ __('Copy backup file to database container') }}</li>
                                    <li>{{ __('Execute restore command') }}</li>
                                </ul>
                                <p class="pt-2 font-semibold text-error">{{ __('Existing objects can cause the import to fail unless replacement is enabled.') }}</p>
                            </x-modal-confirmation>
                        </div>
                    </div>
                </div>
            @endcan

            {{-- Storage Restore Section --}}
            @if (count($availableS3Storages) > 0)
                @can('update', $this->resource)
                    @php
                        $s3StorageOptions = collect($availableS3Storages)
                            ->map(fn ($storage) => [
                                'value' => $storage['id'],
                                'label' => $storage['name']
                                    .($storage['description'] ? ' - '.$storage['description'] : ''),
                            ])
                            ->values()
                            ->all();
                    @endphp
                    <div x-cloak x-show="restoreType === 's3'"
                        class="mt-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-forms.listbox id="s3StorageId" :label="__('Storage')" :options="$s3StorageOptions"
                                :placeholder="__('Select storage')" live />

                            <x-forms.input :label="__('File path')"
                                :helper="__('Path to the backup file in the storage, e.g., /backups/database-2025-01-15.gz')"
                                placeholder="/backups/database-backup.gz" wire:model.blur="s3Path"
                                wire:keydown.enter="checkS3File" canGate="update"
                                :canResource="$this->resource" />
                        </div>

                        <div class="mt-3 flex justify-end">
                            <x-forms.button wire:click="checkS3File" x-bind:disabled="!s3StorageId || !s3Path"
                                canGate="update" :canResource="$this->resource">
                                {{ __('Check file') }}
                            </x-forms.button>
                        </div>

                            @if ($s3FileSize)
                            <div
                                class="mt-4 flex flex-col gap-3 rounded-lg border border-neutral-200 bg-white p-3 dark:border-white/[0.08] dark:bg-white/[0.03] sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <p class="truncate text-[12px] font-medium">{{ $s3Path }}</p>
                                    <p class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-faint">
                                        {{ formatBytes($s3FileSize ?? 0) }}</p>
                                </div>
                                <div class="shrink-0">
                                        <x-modal-confirmation :title="__('Restore Database from Storage?')" :buttonTitle="__('Restore from storage')"
                                            submitAction="restoreFromS3" isErrorButton>
                                            <x-slot:button-title>
                                            {{ __('Restore from storage') }}
                                            </x-slot:button-title>
                                        {{ __('This will:') }}
                                            <ul class="list-disc list-inside pt-2">
                                                <li>{{ __('Download backup from the storage') }}</li>
                                                <li>{{ __('Copy file into database container') }}</li>
                                                <li>{{ __('Execute restore command') }}</li>
                                            </ul>
                                        <p class="pt-2 font-semibold text-error">{{ __('Existing objects can cause the import to fail unless replacement is enabled.') }}</p>
                                        </x-modal-confirmation>
                                </div>
                            </div>
                            @endif
                    </div>
                @endcan
            @endif
            </section>
        </div>

            <x-process-dialog @databaserestore.window="processDialogOpen = true" closeWithX size="xl">
                <x-slot:title>{{ __('Database Restore Output') }}</x-slot:title>
                <x-slot:content>
                    <div class="flex h-full min-h-0 flex-col" wire:ignore>
                        <livewire:activity-monitor wire:key="database-restore-{{ $resourceUuid }}" fullHeight />
                    </div>
                </x-slot:content>
            </x-process-dialog>
</div>
