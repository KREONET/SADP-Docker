{{-- Rendered inside the General form, so no <form> here: the button is type=button and Enter is handled explicitly. --}}
<div>
    <x-application.settings-section :title="__('Connect application')"
        :description="__('Mount the data volume of this database into an application on the same server. The application must run as user 65532 or as root to access the files.')">
        @if ($this->connections->isNotEmpty() || $this->composeConnections->isNotEmpty())
            <div class="mb-4 divide-y divide-neutral-200 rounded-lg border border-neutral-200 dark:divide-white/[0.08] dark:border-white/[0.08]">
                @foreach ($this->connections as $connection)
                    <div wire:key="sqlite-connection-{{ $connection->id }}"
                        class="flex items-center justify-between gap-3 px-3 py-2 text-[13px]">
                        <span class="min-w-0 truncate">
                            <a href="{{ $connection->resource?->link() }}"
                                class="font-medium underline-offset-2 hover:underline">{{ $connection->resource?->name }}</a>
                            <span class="text-neutral-500 dark:text-fg-dim">{{ __('at :path', ['path' => $connection->mount_path]) }}</span>
                        </span>
                        <x-forms.button type="button" wire:click="unlink({{ $connection->id }})" class="!px-2.5 !text-xs"
                            canGate="update" :canResource="$database">
                            {{ __('Unlink') }}
                        </x-forms.button>
                    </div>
                @endforeach
                @foreach ($this->composeConnections as $composeApplication)
                    <div wire:key="sqlite-compose-connection-{{ $composeApplication->id }}"
                        class="flex items-center justify-between gap-3 px-3 py-2 text-[13px]">
                        <span class="min-w-0 truncate">
                            <a href="{{ $composeApplication->link() }}"
                                class="font-medium underline-offset-2 hover:underline">{{ $composeApplication->name }}</a>
                            <span class="text-neutral-500 dark:text-fg-dim">{{ __('in its compose file') }}</span>
                        </span>
                        <span class="shrink-0 text-xs text-neutral-500 dark:text-fg-dim">{{ __('Remove it from the compose file to unlink') }}</span>
                    </div>
                @endforeach
            </div>
        @endif
        @if (count($applicationOptions) === 0)
            <p class="text-[13px] leading-5 text-neutral-500 dark:text-fg-dim">
                {{ __('No applications are deployed on this server yet.') }}
            </p>
        @else
            <div class="flex flex-col gap-4">
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.searchable-listbox id="applicationUuid" :label="__('Application')" required
                        :options="$applicationOptions" :placeholder="__('Select an application')"
                        :searchPlaceholder="__('Search applications…')" :emptyText="__('No matching applications')"
                        :helper="__('Docker Compose applications mount the volume from their compose file. Coolify shows the lines to add.')" />
                    <x-forms.input id="mountPath" :label="__('Mount path')" required placeholder="/var/lib/sqlite"
                        canGate="update" :canResource="$database" x-on:keydown.enter.prevent="$wire.connect()"
                        :helper="__('Any directory inside the application container. The database files (:files) will be available there.', ['files' => str($database->sqlite_databases)->replace(',', ', ')])" />
                </div>

                <div class="flex justify-end">
                    <x-forms.button type="button" wire:click="connect" canGate="update" :canResource="$database">
                        {{ __('Mount volume') }}
                    </x-forms.button>
                </div>

                @if ($composeSnippet)
                    <x-callout type="info" :title="__('Add the volume to the compose file of :name', ['name' => $composeApplicationName])">
                        <p>{{ __('Add these lines to the service that uses the database, then redeploy. Start this database first: Docker Compose does not create external volumes.') }}</p>
                        <div class="relative mt-2">
                            <pre
                                class="overflow-auto rounded-md bg-white p-3 pr-10 font-mono text-xs leading-5 text-neutral-700 ring-1 ring-neutral-200 dark:bg-black/20 dark:text-neutral-300 dark:ring-white/[0.08]">{{ $composeSnippet }}</pre>
                            <x-copy-button :value="$composeSnippet" :label="__('Copy compose lines')"
                                class="absolute top-2 right-2" />
                        </div>
                    </x-callout>
                @endif
            </div>
        @endif
    </x-application.settings-section>
</div>
