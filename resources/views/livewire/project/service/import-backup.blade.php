<div class="flex flex-col gap-6">
    @if ($this->databases->isEmpty())
        <x-empty :title="__('No compatible databases')"
            :description="__('This service does not contain a database that supports backup imports.')"
            icon-name="database" size="sm" />
    @else
        @if ($this->databases->count() > 1)
            <div class="max-w-sm">
                <x-forms.listbox id="selectedDatabaseUuid" :label="__('Database')" live required canGate="update"
                    :canResource="$service" placeholder="Select a database"
                    :options="$this->databases->map(fn ($database) => [
                        'value' => $database->uuid,
                        'label' => $database->human_name ?: $database->name,
                    ])->all()" />
            </div>
        @endif

        @if ($this->selectedDatabase)
            <livewire:project.database.import :resource="$this->selectedDatabase"
                :key="'service-import-' . $this->selectedDatabase->uuid" />
        @endif
    @endif
</div>
