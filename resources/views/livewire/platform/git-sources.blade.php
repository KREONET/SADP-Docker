<div class="flex min-w-0 flex-col gap-6">
    <x-application.settings-section :title="__('Git sources')" flush
        :description="__('Git servers that every team can deploy from. Only platform admins add or remove them. Register a Forgejo (or Gitea) server with an access token of a service account that can read the repositories.')">
        @forelse ($sources as $source)
            <div wire:key="git-source-{{ $source->uuid }}"
                class="border-b border-neutral-200 px-4 py-3 last:border-b-0 dark:border-white/[0.06]">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 dark:bg-white/[0.06] dark:text-fg-dim">
                        <x-git-icon class="size-4" git="App\Models\ForgejoApp" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex min-w-0 flex-wrap items-center gap-2">
                            <span class="truncate text-sm font-semibold text-black dark:text-fg">{{ $source->name }}</span>
                            <x-status-badge status="Forgejo" type="neutral" />
                        </div>
                        <p class="mt-0.5 truncate text-xs text-neutral-500 dark:text-fg-dim">
                            {{ $source->html_url }}
                            @if ($source->login)
                                · {{ __('Token user: :login', ['login' => $source->login]) }}
                            @endif
                            · {{ trans_choice(':count application|:count applications', $source->applications_count, ['count' => $source->applications_count]) }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-forms.button type="button" wire:click="test('{{ $source->uuid }}')">{{ __('Test connection') }}</x-forms.button>
                        <x-forms.button type="button" wire:click="startRotating('{{ $source->uuid }}')">{{ __('Replace token') }}</x-forms.button>
                        <x-modal-confirmation :title="__('Remove Git source :name?', ['name' => $source->name])" :buttonTitle="__('Remove')" isErrorButton
                            submitAction="remove('{{ $source->uuid }}')" :confirmWithText="false" :confirmWithPassword="false"
                            :actions="[__('Teams can no longer create applications from this server.')]" />
                    </div>
                </div>
                @if ($rotatingUuid === $source->uuid)
                    <form wire:submit="rotate" class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-end">
                        <div class="min-w-0 flex-1">
                            <x-forms.input id="newAccessToken" type="password" :label="__('New access token')" required />
                        </div>
                        <x-forms.button type="submit" isHighlighted>{{ __('Save token') }}</x-forms.button>
                        <x-forms.button type="button" wire:click="cancelRotating">{{ __('Cancel') }}</x-forms.button>
                    </form>
                @endif
            </div>
        @empty
            <x-empty size="sm" icon-name="sources" :title="__('No Git sources yet')"
                :description="__('Add a Forgejo server below so teams can pick repositories from it.')" />
        @endforelse
    </x-application.settings-section>

    <form wire:submit="add">
        <x-application.settings-section :title="__('Add Forgejo server')"
            :description="__('Creating applications lists the repositories this token can read. Create the token under Settings → Applications in Forgejo with the read:user and read:repository scopes.')">
            <div class="grid gap-4">
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.input id="name" :label="__('Name')" required placeholder="company-forgejo" />
                    <x-forms.input id="htmlUrl" :label="__('Server URL')" required placeholder="https://git.example.com" />
                </div>
                <x-forms.input id="accessToken" type="password" :label="__('Access token')" required
                    :helper="__('Stored encrypted and only used on the server to list repositories and clone.')" />
                <p class="text-[11px] text-neutral-600 dark:text-fg-dim">
                    {{ __('To redeploy automatically on push, add the auto-deploy webhook below (payload URL :url).', ['url' => $webhookUrl]) }}
                </p>
                <div class="flex justify-end">
                    <x-forms.button type="submit" isHighlighted>{{ __('Add Git source') }}</x-forms.button>
                </div>
            </div>
        </x-application.settings-section>
    </form>
</div>
