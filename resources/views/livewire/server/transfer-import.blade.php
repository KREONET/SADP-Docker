<div class="w-full">
    <x-slot:title>
        {{ __('Import server') }} | Coolify
    </x-slot>

    <div class="mb-5 flex min-h-9 flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="min-w-0 text-[24px]! leading-7! font-semibold! tracking-tight!">{{ __('Import server') }}</h1>
                <x-status-badge :label="__('Dev')" />
            </div>
            <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                {{ __('Import a server that you downloaded from another Coolify instance. This instance then manages it.') }}
            </p>
        </div>
        <a href="{{ route('server.index') }}" class="button w-fit shrink-0" {{ wireNavigate() }}>
            {{ __('Back to servers') }}
        </a>
    </div>

    <div class="application-settings-form flex flex-col gap-6">
        <x-application.settings-section id="server-import-file-section" :title="__('Transfer file')"
            :helper="__('On the source instance, open the server, go to Transfer, and click Download JSON in Manual transfer. The server data on the machine does not change.')">
            <x-slot:actions>
                <x-forms.button wire:click="dryRun" wire:loading.attr="disabled" wire:target="dryRun,importBundle">
                    <x-reicon name="search" class="size-3.5" />
                    <span wire:loading.remove wire:target="dryRun">{{ __('Dry run') }}</span>
                    <span wire:loading wire:target="dryRun">{{ __('Checking…') }}</span>
                </x-forms.button>
                <x-modal-confirmation :title="__('Import this server?')" submitAction="importBundle"
                    :confirmWithText="false" :confirmWithPassword="false" :step2ButtonText="__('Import server')"
                    :actions="[
                        __('The server and its resources will be added to the current team.'),
                        __('This instance will manage the server. Disable management of it on the source instance.'),
                    ]">
                    <x-slot:trigger>
                        <x-forms.button isHighlighted wire:loading.attr="disabled" wire:target="dryRun,importBundle">
                            <x-reicon name="upload" class="size-3.5" />
                            <span wire:loading.remove wire:target="importBundle">{{ __('Import server') }}</span>
                            <span wire:loading wire:target="importBundle">{{ __('Importing…') }}</span>
                        </x-forms.button>
                    </x-slot:trigger>
                </x-modal-confirmation>
            </x-slot:actions>

            <div class="flex flex-col gap-4">
                <x-forms.input type="file" id="bundleFile" :label="__('Upload file')"
                    accept=".json,application/json" />
                <x-forms.textarea id="bundleJson" :label="__('Or paste the file content')" rows="10"
                    placeholder='{"schema_version":1,...}' />
                <div class="md:max-w-xl">
                    <x-forms.input id="passphrase" type="password" :label="__('Passphrase')"
                        :placeholder="__('Only for encrypted files')" autocomplete="off" />
                </div>
            </div>
        </x-application.settings-section>

        <x-application.settings-section id="server-import-options-section" :title="__('Options')">
            <div class="flex flex-col gap-1 md:max-w-xl">
                <x-forms.checkbox id="preserveUuids" :label="__('Keep UUIDs from the source instance')"
                    :helper="__('Resources keep the same UUIDs, so their names, containers, and webhook URLs stay the same.')" />
                <x-forms.checkbox id="adoptMode" :label="__('Adopt running resources')"
                    :helper="__('Keep the current status of each resource and do not redeploy stopped resources.')" />
            </div>
        </x-application.settings-section>

        @if (count($lastWarnings) > 0)
            <x-callout type="warning" :title="__('Warnings')">
                <ul class="mt-1 list-disc space-y-1 pl-5">
                    @foreach ($lastWarnings as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </x-callout>
        @endif

        @if ($lastResult)
            @php
                $createdCounts = collect(data_get($lastResult, 'created', []))
                    ->filter(fn ($count) => (int) $count > 0);
            @endphp
            <x-application.settings-section id="server-import-result-section"
                :title="data_get($lastResult, 'dry_run') ? __('Dry run result') : __('Import result')">
                <x-slot:actions>
                    @if (data_get($lastResult, 'dry_run'))
                        <x-status-badge :label="__('Nothing written')" type="neutral" />
                    @elseif (data_get($lastResult, 'claimed'))
                        <x-status-badge :label="__('Managed here')" type="success" />
                    @else
                        <x-status-badge :label="__('Management not enabled')" type="warning" />
                    @endif
                    @if ($importedServerUuid)
                        <a href="{{ route('server.show', ['server_uuid' => $importedServerUuid]) }}" class="button"
                            {{ wireNavigate() }}>
                            {{ __('Open server') }}
                            <x-reicon name="arrow-right" class="size-3.5" />
                        </a>
                    @endif
                </x-slot:actions>

                <div class="flex flex-col gap-4">
                    @if (filled(data_get($lastResult, 'server_uuid')))
                        <p class="text-xs text-neutral-500 dark:text-fg-dim">
                            {{ __('Server UUID') }}
                            <code class="ml-1 font-mono text-neutral-700 dark:text-fg">{{ data_get($lastResult, 'server_uuid') }}</code>
                        </p>
                    @endif

                    @if ($createdCounts->isNotEmpty())
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                            @foreach ($createdCounts as $type => $count)
                                <div
                                    class="rounded-lg border border-neutral-200 px-3 py-2 dark:border-white/[0.08]">
                                    <div class="text-[15px] font-semibold text-neutral-950 dark:text-fg">
                                        {{ $count }}</div>
                                    <div class="text-[11px] text-neutral-500 dark:text-fg-faint">
                                        {{ __((string) str($type)->replace('_', ' ')->ucfirst()) }}</div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-xs text-neutral-500 dark:text-fg-dim">{{ __('No resources in this file.') }}</p>
                    @endif
                </div>
            </x-application.settings-section>
        @endif
    </div>
</div>
