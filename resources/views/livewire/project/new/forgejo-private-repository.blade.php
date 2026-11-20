<div class="mt-8 flex w-full max-w-none flex-col gap-6 lg:mt-3">
    @if ($forgejo_apps->isEmpty())
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Forgejo</h2>
                    <p>{{ __('Select a repository of a Git server your platform admins connected.') }}</p>
                </div>
            </div>
            <div class="application-settings-section-body">
                <x-empty :title="__('No Forgejo servers')" icon-name="sources"
                    :description="auth()->user()->can('create', \App\Models\ForgejoApp::class)
                        ? __('Add a Forgejo server under Platform Admin → Sources first.')
                        : __('Ask a platform admin to add a Forgejo server.')">
                    @can('create', \App\Models\ForgejoApp::class)
                        <x-slot:contents>
                            <a class="button button-highlighted" href="{{ route('platform.sources') }}" {{ wireNavigate() }}>
                                {{ __('Open Platform Admin → Sources') }}
                            </a>
                        </x-slot:contents>
                    @endcan
                </x-empty>
            </div>
        </section>
    @elseif ($current_step === 'forgejo_apps')
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>{{ __('Choose Forgejo server') }}</h2>
                    <p>{{ __('Select the server that hosts the repository you want to deploy.') }}</p>
                </div>
            </div>
            <div class="application-settings-section-body p-0!">
                @foreach ($forgejo_apps as $fgapp)
                    <button type="button"
                        class="group flex w-full items-center gap-3 border-b border-neutral-200 px-4 py-3 text-left transition-colors last:border-b-0 hover:bg-neutral-50 dark:border-white/[0.06] dark:hover:bg-white/[0.025]"
                        wire:click.prevent="loadRepositories({{ $fgapp->id }})"
                        wire:loading.attr="disabled" wire:target="loadRepositories({{ $fgapp->id }})"
                        wire:key="forgejo-app-{{ $fgapp->id }}">
                        <div
                            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 dark:bg-white/[0.06] dark:text-fg-dim">
                            <x-git-icon class="size-4" git="App\Models\ForgejoApp" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-semibold text-black dark:text-fg">
                                {{ data_get($fgapp, 'name') }}
                            </div>
                            <p class="mt-0.5 truncate text-xs text-neutral-500 dark:text-fg-dim">
                                {{ data_get($fgapp, 'html_url') }}
                            </p>
                        </div>
                        <x-loading wire:loading wire:target="loadRepositories({{ $fgapp->id }})" />
                    </button>
                @endforeach
            </div>
        </section>
    @elseif ($current_step === 'repository')
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>{{ __('Choose repository') }}</h2>
                    <p>{{ __('Search the repositories the selected Forgejo server lets Coolify read.') }}</p>
                </div>
                @if ($forgejo_app_id)
                    <x-forms.button wire:click.prevent="loadRepositories({{ $forgejo_app_id }})">
                        {{ __('Refresh') }}
                    </x-forms.button>
                @endif
            </div>
            <div class="application-settings-section-body">
                @if ($repositories->isNotEmpty())
                    <div class="flex items-end gap-2">
                        <x-forms.datalist class="w-full" :label="__('Repository')"
                            :placeholder="__('Search repositories…')" wire:model.live="selected_repository_id">
                            @foreach ($repositories as $repo)
                                <option value="{{ data_get($repo, 'id') }}">
                                    {{ data_get($repo, 'full_name') }}
                                </option>
                            @endforeach
                        </x-forms.datalist>
                        <x-forms.button :showLoadingIndicator="false" wire:click.prevent="loadBranches"
                            wire:target="loadBranches,selected_repository_id">
                            <x-loading-on-button wire:loading.delay
                                wire:target="loadBranches,selected_repository_id" />
                            {{ __('Load repository') }}
                        </x-forms.button>
                    </div>
                @else
                    <x-empty size="sm" :title="__('No repositories available')"
                        :description="__('The access token of this Forgejo server cannot read any repository. Ask a platform admin to check it.')" />
                @endif
            </div>
        </section>

        @if ($branches->isNotEmpty())
            <form wire:submit="submit">
                <section class="application-settings-section">
                    <div class="application-settings-section-header">
                        <div>
                            <h2>{{ __('Build configuration') }}</h2>
                            <p>{{ __('Choose the branch and build strategy for this application.') }}</p>
                        </div>
                        <x-forms.button type="submit" isHighlighted>{{ __('Continue') }}</x-forms.button>
                    </div>
                    <div class="application-settings-section-body space-y-5">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-forms.listbox id="selected_branch_name" :label="__('Branch')" required
                                :options="$branches->map(fn ($branch) => [
                                    'value' => data_get($branch, 'name'),
                                    'label' => data_get($branch, 'name'),
                                ])->values()->all()" />
                            <x-forms.listbox id="build_pack" :label="__('Build pack')" required live :options="[
                                ['value' => 'railpack', 'label' => 'Railpack'],
                                ['value' => 'nixpacks', 'label' => 'Nixpacks'],
                                ['value' => 'static', 'label' => __('Static')],
                                ['value' => 'dockerfile', 'label' => 'Dockerfile'],
                                ['value' => 'dockercompose', 'label' => 'Docker Compose'],
                            ]" />
                            @if ($show_is_static)
                                <x-forms.listbox id="is_static" :label="__('Output type')" onChange="instantSave"
                                    :options="[
                                        ['value' => false, 'label' => __('Web application')],
                                        ['value' => true, 'label' => __('Static site')],
                                    ]" />
                                <x-forms.input type="number" id="port" :label="__('Port')"
                                    :readonly="$is_static || $build_pack === 'static'"
                                    :helper="__('Port the application listens on.')" />
                            @endif
                            @if ($is_static)
                                <x-forms.input id="publish_directory" :label="__('Publish directory')"
                                    :helper="__('Directory containing the generated static assets.')" />
                            @endif
                        </div>

                        @if ($build_pack === 'dockercompose')
                            <div x-data="{
                                baseDir: @js($base_directory),
                                composeLocation: @js($docker_compose_location),
                                normalize(path) {
                                    if (!path || path.trim() === '') return '/';
                                    const normalized = path.trim().replace(/\/+$/, '');
                                    return normalized.startsWith('/') ? normalized : '/' + normalized;
                                },
                            }" class="grid gap-4 sm:grid-cols-2">
                                <x-forms.input placeholder="/" wire:model.defer="base_directory"
                                    :label="__('Base directory')" :helper="__('Repository directory used as the build root.')"
                                    x-model="baseDir" @blur="baseDir = normalize(baseDir)" />
                                <x-forms.input placeholder="/docker-compose.yaml"
                                    wire:model.defer="docker_compose_location" :label="__('Compose file')"
                                    :helper="__('Path relative to the base directory.')" x-model="composeLocation"
                                    @blur="composeLocation = normalize(composeLocation)" />
                                <p class="sm:col-span-2 text-xs text-neutral-500 dark:text-fg-dim">
                                    {{ __('Resolved file:') }}
                                    <code class="font-mono text-coollabs dark:text-warning"
                                        x-text='(baseDir === "/" ? "" : baseDir) + (composeLocation.startsWith("/") ? composeLocation : "/" + composeLocation)'></code>
                                </p>
                            </div>
                        @else
                            <x-forms.input wire:model="base_directory" :label="__('Base directory')"
                                :helper="__('Repository directory used as the build root.')" />
                        @endif
                    </div>
                </section>
            </form>
        @endif
    @endif
</div>
