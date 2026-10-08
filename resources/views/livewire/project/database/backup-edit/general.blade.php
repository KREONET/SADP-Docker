<form wire:submit="submit">
    <x-unsaved-bar action="submit" />

    <x-application.settings-section :title="__('Backup schedule')"
        :description="__('Choose what to back up, when it runs, and how long it may run.')">
        <x-slot:actions>
            <div class="flex items-center gap-2">
                @if (! $backupEnabled)
                    <x-forms.button type="button" wire:click="toggleEnabled" wire:loading.attr="disabled"
                        wire:target="toggleEnabled" isHighlighted>
                        {{ __('Enable backup') }}
                    </x-forms.button>
                @else
                    <x-forms.button type="button" wire:click="toggleEnabled" wire:loading.attr="disabled"
                        wire:target="toggleEnabled">
                        {{ __('Disable backup') }}
                    </x-forms.button>
                @endif
                <x-forms.button type="button" wire:click="backupNow"
                    :disabled="! str($status)->startsWith('running')"
                    :tooltip="! str($status)->startsWith('running') ? __('The database must be running to start a backup.') : null">{{ __('Back up now') }}</x-forms.button>
            </div>
        </x-slot:actions>

        <div class="space-y-5">
            @if ($backup->database_type === 'App\Models\StandalonePostgresql' && $backup->database_id !== 0
                    || $backup->database_type === 'App\Models\StandaloneMysql'
                    || $backup->database_type === 'App\Models\StandaloneMariadb')
                <div class="grid w-full gap-4">
                    <x-forms.listbox id="dumpAll" :label="__('Database selection')" onChange="instantSave" :options="[
                        ['value' => true, 'label' => __('All databases')],
                        ['value' => false, 'label' => __('Specific databases')],
                    ]" />
                    @if (! $backup->dump_all)
                        <div class="w-full" x-data="{
                            value: @entangle('databasesToBackup').live,
                            draft: '',
                            get databases() {
                                return (this.value || '').split(',').map(name => name.trim()).filter(Boolean);
                            },
                            addDatabase() {
                                const names = this.draft.split(',').map(name => name.trim()).filter(Boolean);
                                if (names.length === 0) return;
                                this.value = [...new Set([...this.databases, ...names])].join(',');
                                this.draft = '';
                            },
                            removeDatabase(index) {
                                this.value = this.databases.filter((_, itemIndex) => itemIndex !== index).join(',');
                            },
                        }">
                            <label class="mb-1.5 block text-sm font-medium">{{ __('Databases to back up') }}</label>
                            <div class="chip-input">
                                <template x-for="(database, index) in databases" :key="database">
                                    <span class="chip font-mono">
                                        <span x-text="database"></span>
                                        <button type="button" @click="removeDatabase(index)"
                                            class="chip-remove"
                                            :aria-label="@js(__('Remove :name')).replace(':name', database)">
                                            <x-reicon name="x" class="size-3" />
                                        </button>
                                    </span>
                                </template>
                                <input x-model="draft" @keydown.enter.prevent="addDatabase()"
                                    @keydown="if ($event.key === ',') { $event.preventDefault(); addDatabase(); }"
                                    @blur="addDatabase()" type="text"
                                    placeholder="{{ __('Type a database and press Enter') }}" />
                            </div>
                            <p class="mt-1.5 text-xs text-neutral-500 dark:text-fg-dim">
                                {{ __('Add one or more database names. Leave empty to include the default database.') }}
                            </p>
                        </div>
                    @endif
                </div>
            @elseif ($backup->database_type === 'App\Models\StandaloneMongodb')
                <x-forms.input :label="__('Databases to include')"
                    :helper="__('Use database:collection1,collection2|database2 to exclude selected collections. Leave empty to include all databases and collections.')"
                    id="databasesToBackup" />
            @elseif ($backup->database_type === 'App\Models\StandaloneClickhouse')
                <x-forms.input :label="__('Databases to back up')"
                    :helper="__('Comma-separated database names. Leave empty to include the default database.')"
                    id="databasesToBackup" />
            @elseif ($backup->database_type === 'App\Models\StandaloneSqlite')
                <x-forms.input :label="__('Database files to back up')"
                    :helper="__('Comma-separated file names inside the data volume. Leave empty to back up every configured file.')"
                    id="databasesToBackup" />
            @endif

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-forms.input :label="__('Frequency')" id="frequency" required />
                <x-forms.input :label="__('Timezone')" id="timezone" disabled
                    :helper="__('Uses the deployment server timezone, or the instance timezone when none is configured.')"
                    required />
                <x-forms.input :label="__('Timeout')" id="timeout" type="number" min="60"
                    :helper="__('Maximum backup runtime in seconds.')" required />
                <x-forms.input :label="__('Missing backup alert after')" id="missingBackupNotificationDays" type="number"
                    min="0" max="365" :suffix="__('days')" canGate="manageBackups" :canResource="$backup->database"
                    :helper="__('Notify through backup failure channels after this many days without an execution. Use 0 to disable.')" required />
            </div>
            @if ($backup->supportsEncryption())
                <div class="w-full sm:w-96">
                    <x-forms.checkbox instantSave id="encryptBackup" :label="__('Encrypt backup files')"
                        :helper="__('Encrypts each new backup file on the server with AES-256 (OpenSSL, PBKDF2-SHA256) before it is stored or uploaded to S3. The server needs openssl. Coolify keeps a random passphrase per backup and decrypts it automatically when you restore it here. Downloaded files stay encrypted. Existing backups are not changed.')"
                        canGate="manageBackups" :canResource="$backup->database" />
                </div>
            @endif
            <x-next-run :at="$backup->next_run_at" :timezone="$timezone" :enabled="$backup->enabled" :frequency="$backup->frequency" />
        </div>
    </x-application.settings-section>
</form>
