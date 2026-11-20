<div>
    <x-slot:title>
        {{ __('Instance Backup') }} | {{ brand_name() }}
    </x-slot>

    <x-settings.layout>
    <div class="application-settings-form mx-auto flex w-full max-w-none min-w-0 flex-col gap-6">
        @if ($server->isFunctional())
            @if (isset($database) && isset($backup))
                <form wire:submit="submit">
                    {{-- Exclude is_backup_before_update_enabled (instantSave) so the bar does not flash. --}}
                    <x-unsaved-bar action="submit" targets="description" />

                    <x-application.settings-section :title="__('Instance database')">
                        <div class="grid gap-4 lg:grid-cols-2">
                            <x-forms.input :label="__('Name')" readonly :value="$database->name" />
                            <x-forms.input :label="__('Description')" id="description" />
                            <div class="lg:col-span-2">
                                <x-forms.input label="UUID" readonly :value="$database->uuid" />
                            </div>
                            <x-forms.input :label="__('User')" readonly :value="$database->postgres_user" />
                        </div>
                    </x-application.settings-section>
                </form>

                <livewire:project.database.backup-edit :backup="$backup" :available-s3-storages="$s3s"
                    :status="data_get($database, 'status')" />

                <x-application.settings-section :title="__('Backup before update')"
                    :description="__('Back up the Coolify database before an update is installed. If the backup fails, the update is not installed.')">
                    <div class="max-w-md">
                        <x-forms.listbox id="is_backup_before_update_enabled" :label="__('Database backup')"
                            onChange="instantSave" :options="[
                                ['value' => true, 'label' => __('Enabled')],
                                ['value' => false, 'label' => __('Disabled')],
                            ]"
                            :helper="__('Runs this backup once before every manual or automatic update, in addition to the schedule. It does not run when the backup schedule is disabled.')" />
                    </div>
                </x-application.settings-section>

                <livewire:project.database.backup-executions :backup="$backup" />
            @else
                <x-application.settings-section :title="__('Instance backup')">
                    <x-empty :title="__('Backup is not configured')"
                        :description="__('Coolify needs an internal database resource to create automatic backups.')"
                        icon-name="database" size="sm">
                        <x-slot:actions>
                            <x-forms.button wire:click="addCoolifyDatabase" isHighlighted>
                                {{ __('Configure backup') }}
                            </x-forms.button>
                        </x-slot:actions>
                    </x-empty>
                </x-application.settings-section>
            @endif
        @else
            <x-application.settings-section :title="__('Instance backup')">
                <x-callout type="danger" :title="__('Localhost is not ready')">
                    {{ __('Validate the localhost connection before configuring instance backups.') }}
                    <a href="{{ route('server.show', [$server->uuid]) }}" class="font-medium underline"
                        {{ wireNavigate() }}>{{ __('Open server settings') }}</a>
                </x-callout>
            </x-application.settings-section>
        @endif
    </div>
    </x-settings.layout>
</div>
