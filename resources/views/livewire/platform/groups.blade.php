<div>
    <x-slot:title>{{ __('Groups') }} | {{ __('Platform Admin') }} | {{ brand_name() }}</x-slot>

    <x-platform.layout>
        <div class="flex min-w-0 flex-col gap-6">
            <x-application.settings-section :title="__('Teams')" :description="__('Teams group users, projects and servers. Select a team to manage its members and projects.')" flush>
                @foreach ($teams as $team)
                    <div wire:key="platform-team-{{ $team->id }}"
                        @class([
                            'grid items-center gap-3 border-b border-neutral-200 px-4 py-2.5 last:border-b-0 lg:grid-cols-[minmax(10rem,1fr)_repeat(3,6rem)_auto] dark:border-white/[0.06]',
                            'bg-neutral-50 dark:bg-white/[0.03]' => $selectedTeam?->id === $team->id,
                        ])>
                        <div class="min-w-0">
                            <div class="truncate text-[12px] font-semibold text-black dark:text-fg">
                                {{ $team->name }}
                                @if ($team->id === 0)
                                    <span class="ml-1 text-[11px] font-normal text-neutral-500">{{ __('(root)') }}</span>
                                @endif
                            </div>
                            <div class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $team->description ?: __('No description') }}</div>
                        </div>
                        <div class="text-[11px] text-neutral-600 dark:text-fg-dim">{{ __(':count members', ['count' => $team->members_count]) }}</div>
                        <div class="text-[11px] text-neutral-600 dark:text-fg-dim">{{ __(':count projects', ['count' => $team->projects_count]) }}</div>
                        <div class="text-[11px] text-neutral-600 dark:text-fg-dim">{{ __(':count servers', ['count' => $team->servers_count]) }}</div>
                        <div class="flex flex-wrap justify-end gap-2">
                            <x-forms.button wire:click="selectTeam({{ $team->id }})">{{ __('Manage') }}</x-forms.button>
                            <x-forms.button wire:click="editTeam({{ $team->id }})">{{ __('Edit') }}</x-forms.button>
                            @if ($team->id !== 0)
                                <x-modal-confirmation :title="__('Delete team :name?', ['name' => $team->name])" :buttonTitle="__('Delete')" isErrorButton
                                    submitAction="deleteTeam({{ $team->id }})"
                                    :actions="[__('Remove every member from the team.'), __('Permanently delete the team. It must have no projects, servers or git sources.')]"
                                    confirmationText="{{ $team->name }}" :confirmationLabel="__('Enter the team name to confirm deletion')"
                                    :shortConfirmationLabel="__('Team name')" />
                            @endif
                        </div>
                        @if ($editingType === 'team' && $editingId === $team->id)
                            <form wire:submit="save" class="grid gap-4 rounded-lg border border-neutral-200 p-4 lg:col-span-5 lg:grid-cols-2 dark:border-white/[0.08]">
                                <x-forms.input id="name" :label="__('Team name')" required />
                                <x-forms.input id="description" :label="__('Description')" />
                                <div class="flex justify-end gap-2 lg:col-span-2">
                                    <x-forms.button type="button" wire:click="cancelEdit">{{ __('Cancel') }}</x-forms.button>
                                    <x-forms.button type="submit" isHighlighted>{{ __('Save') }}</x-forms.button>
                                </div>
                            </form>
                        @endif
                    </div>
                @endforeach
            </x-application.settings-section>

            @if ($selectedTeam)
                <div class="grid min-w-0 gap-6 2xl:grid-cols-2">
                    <x-application.settings-section :title="__('Members of :name', ['name' => $selectedTeam->name])" flush>
                        @forelse ($selectedTeam->members as $member)
                            <div wire:key="platform-member-{{ $selectedTeam->id }}-{{ $member->id }}"
                                class="flex items-center justify-between gap-3 border-b border-neutral-200 px-4 py-2.5 last:border-b-0 dark:border-white/[0.06]">
                                <div class="min-w-0">
                                    <div class="truncate text-[12px] font-semibold">{{ $member->name }}</div>
                                    <div class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $member->email }}</div>
                                </div>
                                {{-- Root team admins and owners are platform administrators: only administrators change those roles. --}}
                                <select class="input w-32" aria-label="{{ __('Role of :name', ['name' => $member->name]) }}"
                                    @disabled($selectedTeam->id === 0 && ! isInstanceAdmin())
                                    wire:change="updateMemberRole({{ $selectedTeam->id }}, {{ $member->id }}, $event.target.value)">
                                    @foreach (\App\Livewire\Platform\Groups::ROLES as $role)
                                        <option value="{{ $role }}" @selected($member->pivot->role === $role)>{{ ucfirst($role) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @empty
                            <x-empty size="sm" :title="__('No members')" />
                        @endforelse
                    </x-application.settings-section>

                    <x-application.settings-section :title="__('Projects of :name', ['name' => $selectedTeam->name])" flush>
                        @forelse ($selectedTeam->projects as $project)
                            <div wire:key="platform-project-{{ $project->uuid }}"
                                class="border-b border-neutral-200 px-4 py-2.5 last:border-b-0 dark:border-white/[0.06]">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="truncate text-[12px] font-semibold">{{ $project->name }}</div>
                                        <div class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                            {{ __(':environments environments · :applications applications', ['environments' => $project->environments_count, 'applications' => $applicationCounts[$project->id] ?? 0]) }}
                                        </div>
                                    </div>
                                    <div class="flex gap-2">
                                        <x-forms.button wire:click="editProject({{ $project->id }})">{{ __('Edit') }}</x-forms.button>
                                        <x-modal-confirmation :title="__('Delete project :name?', ['name' => $project->name])" :buttonTitle="__('Delete')" isErrorButton
                                            submitAction="deleteProject({{ $project->id }})"
                                            :actions="[__('Permanently delete the project and its environments. It must not contain resources.')]"
                                            confirmationText="{{ $project->name }}" :confirmationLabel="__('Enter the project name to confirm deletion')"
                                            :shortConfirmationLabel="__('Project name')" />
                                    </div>
                                </div>
                                @if ($editingType === 'project' && $editingId === $project->id)
                                    <form wire:submit="save" class="mt-3 grid gap-4 rounded-lg border border-neutral-200 p-4 dark:border-white/[0.08]">
                                        <x-forms.input id="name" :label="__('Project name')" required />
                                        <x-forms.input id="description" :label="__('Description')" />
                                        <div class="flex justify-end gap-2">
                                            <x-forms.button type="button" wire:click="cancelEdit">{{ __('Cancel') }}</x-forms.button>
                                            <x-forms.button type="submit" isHighlighted>{{ __('Save') }}</x-forms.button>
                                        </div>
                                    </form>
                                @endif
                            </div>
                        @empty
                            <x-empty size="sm" :title="__('No projects')" />
                        @endforelse
                    </x-application.settings-section>
                </div>
            @endif
        </div>
    </x-platform.layout>
</div>
