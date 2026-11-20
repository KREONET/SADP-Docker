<div>
    <x-slot:title>{{ __('Sources') }} | {{ __('Platform Admin') }} | {{ brand_name() }}</x-slot>

    <x-platform.layout>
        <div class="flex min-w-0 flex-col gap-6">
            <livewire:platform.git-sources />

            <form wire:submit="saveAllowlist">
                <x-application.settings-section :title="__('Allowed sources')"
                    :description="__('When enabled, applications can only be created or deployed from these git hosts and container registries. Each entry is a host or a host/path prefix, one per line.')">
                    <div class="grid gap-4">
                        <x-forms.checkbox id="isSourceAllowlistEnabled" :label="__('Only allow the sources below')" />
                        <div class="grid gap-4 lg:grid-cols-2">
                            <x-forms.textarea id="allowedGitSources" :label="__('Allowed git sources')" rows="6"
                                placeholder="https://git.example.com/
git.example.com/platform-team"
                                :helper="__('Matches https, ssh and git@host:owner/repo URLs. Ports and .git suffixes are ignored.')" />
                            <x-forms.textarea id="allowedDockerRegistries" :label="__('Allowed container registries')" rows="6"
                                placeholder="registry.example.com
docker.io/library"
                                :helper="__('Used for Docker image applications. Images without a registry resolve to docker.io, and official images to docker.io/library.')" />
                        </div>
                        @if ($isSourceAllowlistEnabled)
                            <div class="grid gap-4 text-[11px] text-neutral-600 lg:grid-cols-2 dark:text-fg-dim">
                                <div>
                                    <div class="mb-1 font-medium">{{ __('Effective git prefixes') }}</div>
                                    @forelse ($normalizedGitSources as $entry)
                                        <code wire:key="git-source-{{ md5($entry) }}" class="block">{{ $entry }}</code>
                                    @empty
                                        <span class="text-red-500">{{ __('None. Every git deployment is blocked.') }}</span>
                                    @endforelse
                                </div>
                                <div>
                                    <div class="mb-1 font-medium">{{ __('Effective registry prefixes') }}</div>
                                    @forelse ($normalizedDockerRegistries as $entry)
                                        <code wire:key="registry-{{ md5($entry) }}" class="block">{{ $entry }}</code>
                                    @empty
                                        <span class="text-red-500">{{ __('None. Every Docker image deployment is blocked.') }}</span>
                                    @endforelse
                                </div>
                            </div>
                        @endif
                        <div class="flex justify-end">
                            <x-forms.button type="submit" isHighlighted>{{ __('Save allowed sources') }}</x-forms.button>
                        </div>
                    </div>
                </x-application.settings-section>
            </form>

            <form wire:submit="saveForgejo">
                <x-application.settings-section :title="__('Forgejo')"
                    :description="__('The company Forgejo instance. The bot account opens issues with vulnerability reports, and the instance webhook redeploys applications when new commits are pushed.')">
                    <div class="grid gap-4">
                        <div class="grid gap-4 lg:grid-cols-2">
                            <x-forms.input id="forgejoBaseUrl" :label="__('Forgejo base URL')" placeholder="https://git.example.com" />
                            <x-forms.input id="forgejoBotToken" type="password" :label="__('Bot access token')"
                                :placeholder="$hasForgejoBotToken ? __('Configured. Enter a new token to replace it.') : __('Token with issue write access')"
                                :helper="__('Create an access token for the bot user with the write:issue and read:repository scopes, and give the bot access to the repositories.')" />
                        </div>
                        <div class="flex flex-wrap justify-end gap-2">
                            @if ($hasForgejoBotToken)
                                <x-forms.button type="button" wire:click="removeBotToken" isError>{{ __('Remove token') }}</x-forms.button>
                            @endif
                            <x-forms.button type="button" wire:click="testForgejo">{{ __('Test connection') }}</x-forms.button>
                            <x-forms.button type="submit" isHighlighted>{{ __('Save Forgejo settings') }}</x-forms.button>
                        </div>
                    </div>
                </x-application.settings-section>
            </form>

            <x-application.settings-section :title="__('Auto-deploy webhook')"
                :description="__('Add this as a system webhook in Forgejo (Site administration → Webhooks), or as an organization webhook. Choose the push event and application/json content type.')">
                <div class="grid gap-4">
                    <x-forms.copy-input :label="__('Payload URL')" :text="$webhookUrl" />
                    @if ($revealedWebhookSecret)
                        <x-callout type="warning" :title="__('Copy the secret now')">
                            {{ __('This secret is shown only once.') }}
                        </x-callout>
                        <x-forms.copy-input :label="__('Secret')" :text="$revealedWebhookSecret" />
                    @else
                        <div class="flex items-center gap-2">
                            <x-status-badge :status="$hasForgejoWebhookSecret ? __('Secret configured') : __('No secret')" :type="$hasForgejoWebhookSecret ? 'success' : 'warning'" />
                        </div>
                    @endif
                    <div class="flex justify-end">
                        @if ($hasForgejoWebhookSecret)
                            <x-modal-confirmation :title="__('The current secret stops working immediately. Continue?')"
                                :buttonTitle="__('Rotate secret')" isErrorButton submitAction="generateWebhookSecret"
                                :confirmWithText="false" :confirmWithPassword="false"
                                :actions="[__('Update the webhook in Forgejo with the new secret afterwards.')]" />
                        @else
                            <x-forms.button type="button" wire:click="generateWebhookSecret">{{ __('Generate secret') }}</x-forms.button>
                        @endif
                    </div>
                </div>
            </x-application.settings-section>
        </div>
    </x-platform.layout>
</div>
