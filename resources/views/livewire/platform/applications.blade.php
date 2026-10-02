<div>
    <x-slot:title>{{ __('Applications') }} | {{ __('Platform Admin') }} | Coolify</x-slot>

    <x-platform.layout>
        <x-application.settings-section :title="__('All applications')"
            :description="__('Every application across all teams. Edits here apply immediately; deleting removes containers, volumes and configuration.')" flush>
            <div class="flex flex-wrap items-end gap-2 border-b border-neutral-200 p-4 dark:border-white/[0.06]">
                <div class="min-w-48 flex-1">
                    <x-forms.input wire:model.live.debounce.400ms="search" :label="__('Search')"
                        :placeholder="__('Name, repository or domain')" />
                </div>
                <div class="w-56">
                    <label for="platform-app-team" class="mb-1.5 block text-sm font-medium leading-4">{{ __('Team') }}</label>
                    <select id="platform-app-team" wire:model.live="team" class="input w-full">
                        <option value="">{{ __('All teams') }}</option>
                        @foreach ($teams as $teamOption)
                            <option value="{{ $teamOption->id }}">{{ $teamOption->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            @forelse ($applications as $application)
                @php
                    $scan = $application->latestVulnerabilityScan;
                    $isRunning = str($application->status)->startsWith('running');
                @endphp
                <div wire:key="platform-application-{{ $application->uuid }}"
                    class="border-b border-neutral-200 px-4 py-3 last:border-b-0 dark:border-white/[0.06]">
                    <div class="grid items-center gap-3 lg:grid-cols-[minmax(10rem,1.2fr)_minmax(8rem,1fr)_minmax(10rem,1.4fr)_8rem_auto]">
                        <div class="min-w-0">
                            <div class="truncate text-[12px] font-semibold text-black dark:text-fg">{{ $application->name }}</div>
                            <div class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $application->fqdn ?: __('No domain') }}</div>
                        </div>
                        <div class="min-w-0 text-[11px] text-neutral-600 dark:text-fg-dim">
                            <div class="truncate">{{ data_get($application, 'environment.project.team.name', '-') }}</div>
                            <div class="truncate">{{ data_get($application, 'environment.project.name', '-') }} / {{ data_get($application, 'environment.name', '-') }}</div>
                        </div>
                        <div class="min-w-0 text-[11px] text-neutral-600 dark:text-fg-dim">
                            @if ($application->build_pack === 'dockerimage')
                                <div class="truncate">{{ $application->docker_registry_image_name }}:{{ $application->docker_registry_image_tag }}</div>
                            @else
                                <div class="truncate">{{ $application->git_repository }}</div>
                                <div class="truncate">{{ $application->git_branch }} · {{ $application->build_pack }}</div>
                            @endif
                        </div>
                        <div class="flex flex-col items-start gap-1">
                            <x-status-badge :status="str($application->status)->before(':')->value()" :type="$isRunning ? 'success' : 'neutral'" />
                            @if ($scan)
                                <x-status-badge :label="__('Scan')" :status="$scan->status" :type="match ($scan->status) { 'passed' => 'success', 'found' => 'warning', default => 'error' }" />
                            @endif
                        </div>
                        <div class="flex flex-wrap justify-end gap-2">
                            <x-forms.button wire:click="edit('{{ $application->uuid }}')">{{ __('Edit') }}</x-forms.button>
                            <x-forms.button wire:click="redeploy('{{ $application->uuid }}')">{{ __('Redeploy') }}</x-forms.button>
                            @if ($isRunning)
                                <x-forms.button wire:click="stop('{{ $application->uuid }}')">{{ __('Stop') }}</x-forms.button>
                            @endif
                            <x-modal-confirmation :title="__('Delete :name?', ['name' => $application->name])" :buttonTitle="__('Delete')" isErrorButton
                                submitAction="delete({{ $application->uuid }})"
                                :actions="[__('Stop and remove the application containers.'), __('Delete its volumes, networks and configuration.'), __('Remove it from team :team.', ['team' => data_get($application, 'environment.project.team.name', '-')])]"
                                confirmationText="{{ $application->name }}"
                                :confirmationLabel="__('Enter the application name to confirm deletion')"
                                :shortConfirmationLabel="__('Application name')" />
                        </div>
                    </div>

                    @if ($editingUuid === $application->uuid)
                        <form wire:submit="save" class="mt-4 grid gap-4 rounded-lg border border-neutral-200 p-4 lg:grid-cols-2 dark:border-white/[0.08]">
                            <x-forms.input id="name" :label="__('Name')" required />
                            <x-forms.input id="description" :label="__('Description')" />
                            @if ($application->build_pack !== 'dockerimage' && blank($application->dockerfile))
                                <x-forms.input id="gitRepository" :label="__('Git repository')" required
                                    :helper="__('Must match an allowed source when the allow-list is enabled.')" />
                                <x-forms.input id="gitBranch" :label="__('Branch')" required />
                            @endif
                            <x-forms.checkbox id="isAutoDeployEnabled" :label="__('Auto deploy on push')" />
                            <div class="flex items-center justify-end gap-2 lg:col-span-2">
                                <x-forms.button type="button" wire:click="cancelEdit">{{ __('Cancel') }}</x-forms.button>
                                <x-forms.button type="submit" isHighlighted>{{ __('Save') }}</x-forms.button>
                            </div>
                        </form>
                    @endif
                </div>
            @empty
                <x-empty size="sm" :title="__('No applications found')" icon-name="layers" />
            @endforelse

            @if ($total > $applications->count())
                <div class="border-t border-neutral-200 px-4 py-3 text-[11px] text-neutral-500 dark:border-white/[0.08] dark:text-fg-faint">
                    {{ __('Showing :count of :total. Narrow the search to see more.', ['count' => $applications->count(), 'total' => $total]) }}
                </div>
            @endif
        </x-application.settings-section>
    </x-platform.layout>
</div>
