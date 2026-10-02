<div class="application-settings-form">
    <form wire:submit="submit" class="flex flex-col gap-6">
        <x-unsaved-bar action="submit" />

        <x-application.settings-section :title="__('Database details')"
            :description="__('Manage the identity and container image for this :engine database.', ['engine' => 'SQLite'])">
            <x-slot:actions>
                <x-status-badge :status="__('Experimental')" type="warning" />
                <x-modal-input :title="__('Resource details')" :buttonTitle="__('Details')">
                    <livewire:project.shared.resource-details :resource="$database" />
                </x-modal-input>
            </x-slot:actions>
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input :label="__('Name')" id="name" canGate="update" :canResource="$database" />
                <x-forms.input :label="__('Description')" id="description" canGate="update" :canResource="$database" />
                <div class="lg:col-span-2">
                    <x-forms.input :label="__('Image')" id="image" required canGate="update" :canResource="$database"
                        :helper="__('Use a published :image image from Docker Hub.', ['image' => 'peakimages/sqlite'])" />
                </div>
            </div>
        </x-application.settings-section>

        <x-application.settings-section :title="__('Database files')"
            :description="__('Applications use these databases by mounting the data volume.')">
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input :label="__('File names')" id="sqliteDatabases" placeholder="database.sqlite" required
                    canGate="update" :canResource="$database"
                    :helper="__('Comma-separated file names. Missing files are created in the data volume on the next start. Removing a name does not delete its file. Backups restore into the first file.')" />
            </div>
        </x-application.settings-section>

        <livewire:project.database.sqlite.connect-application :database="$database" />

        <x-application.settings-section :title="__('Runtime')" :description="__('Configure Docker runtime options.')">
            <x-forms.input
                :helper="__('Add supported docker run options used when the container starts. Unsupported options can interfere with Coolify automation.')"
                placeholder="--cap-add SYS_ADMIN --device=/dev/fuse"
                id="customDockerRunOptions" :label="__('Custom Docker options')" canGate="update"
                :canResource="$database" />
        </x-application.settings-section>

        <x-application.settings-section :title="__('Log delivery')"
            :description="__('Forward container logs to the drain configured on the server.')">
            <x-forms.listbox canGate="update" :canResource="$database" id="isLogDrainEnabled" :label="__('Log drain')" live onChange="instantSaveAdvanced"
                :disabled="! auth()->user()->can('update', $database)" :options="[
                    ['value' => false, 'label' => __('Do not forward logs')],
                    ['value' => true, 'label' => __('Forward logs to the server drain')],
                ]" />
        </x-application.settings-section>
    </form>
</div>
