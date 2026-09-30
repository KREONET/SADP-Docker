<div>
    <x-slot:title>Sources | Platform Admin | Coolify</x-slot>

    <x-platform.layout>
        <div class="flex min-w-0 flex-col gap-6">
            <form wire:submit="saveAllowlist">
                <x-application.settings-section title="Allowed sources"
                    description="When enabled, applications can only be created or deployed from these git hosts and container registries. Each entry is a host or a host/path prefix, one per line.">
                    <div class="grid gap-4">
                        <x-forms.checkbox id="isSourceAllowlistEnabled" label="Only allow the sources below" />
                        <div class="grid gap-4 lg:grid-cols-2">
                            <x-forms.textarea id="allowedGitSources" label="Allowed git sources" rows="6"
                                placeholder="https://git.example.com/&#10;git.example.com/platform-team"
                                helper="Matches https, ssh and git@host:owner/repo URLs. Ports and .git suffixes are ignored." />
                            <x-forms.textarea id="allowedDockerRegistries" label="Allowed container registries" rows="6"
                                placeholder="registry.example.com&#10;docker.io/library"
                                helper="Used for Docker image applications. Images without a registry resolve to docker.io, and official images to docker.io/library." />
                        </div>
                        @if ($isSourceAllowlistEnabled)
                            <div class="grid gap-4 text-[11px] text-neutral-600 lg:grid-cols-2 dark:text-fg-dim">
                                <div>
                                    <div class="mb-1 font-medium">Effective git prefixes</div>
                                    @forelse ($normalizedGitSources as $entry)
                                        <code wire:key="git-source-{{ md5($entry) }}" class="block">{{ $entry }}</code>
                                    @empty
                                        <span class="text-red-500">None. Every git deployment is blocked.</span>
                                    @endforelse
                                </div>
                                <div>
                                    <div class="mb-1 font-medium">Effective registry prefixes</div>
                                    @forelse ($normalizedDockerRegistries as $entry)
                                        <code wire:key="registry-{{ md5($entry) }}" class="block">{{ $entry }}</code>
                                    @empty
                                        <span class="text-red-500">None. Every Docker image deployment is blocked.</span>
                                    @endforelse
                                </div>
                            </div>
                        @endif
                        <div class="flex justify-end">
                            <x-forms.button type="submit" isHighlighted>Save allowed sources</x-forms.button>
                        </div>
                    </div>
                </x-application.settings-section>
            </form>

            <form wire:submit="saveForgejo">
                <x-application.settings-section title="Forgejo"
                    description="The company Forgejo instance. The bot account opens issues with vulnerability reports, and the instance webhook redeploys applications when new commits are pushed.">
                    <div class="grid gap-4">
                        <div class="grid gap-4 lg:grid-cols-2">
                            <x-forms.input id="forgejoBaseUrl" label="Forgejo base URL" placeholder="https://git.example.com" />
                            <x-forms.input id="forgejoBotToken" type="password" label="Bot access token"
                                placeholder="{{ $hasForgejoBotToken ? 'Configured. Enter a new token to replace it.' : 'Token with issue write access' }}"
                                helper="Create an access token for the bot user with the write:issue and read:repository scopes, and give the bot access to the repositories." />
                        </div>
                        <div class="flex flex-wrap justify-end gap-2">
                            @if ($hasForgejoBotToken)
                                <x-forms.button type="button" wire:click="removeBotToken" isError>Remove token</x-forms.button>
                            @endif
                            <x-forms.button type="button" wire:click="testForgejo">Test connection</x-forms.button>
                            <x-forms.button type="submit" isHighlighted>Save Forgejo settings</x-forms.button>
                        </div>
                    </div>
                </x-application.settings-section>
            </form>

            <x-application.settings-section title="Auto-deploy webhook"
                description="Add this as a system webhook in Forgejo (Site administration → Webhooks), or as an organization webhook. Choose the push event and application/json content type.">
                <div class="grid gap-4">
                    <x-forms.copy-input label="Payload URL" :text="$webhookUrl" />
                    @if ($revealedWebhookSecret)
                        <x-callout type="warning" title="Copy the secret now">
                            This secret is shown only once.
                        </x-callout>
                        <x-forms.copy-input label="Secret" :text="$revealedWebhookSecret" />
                    @else
                        <div class="flex items-center gap-2">
                            <x-status-badge :status="$hasForgejoWebhookSecret ? 'Secret configured' : 'No secret'" :type="$hasForgejoWebhookSecret ? 'success' : 'warning'" />
                        </div>
                    @endif
                    <div class="flex justify-end">
                        <x-forms.button type="button" wire:click="generateWebhookSecret"
                            wire:confirm="{{ $hasForgejoWebhookSecret ? 'The current secret stops working immediately. Continue?' : 'Generate a webhook secret?' }}">
                            {{ $hasForgejoWebhookSecret ? 'Rotate secret' : 'Generate secret' }}
                        </x-forms.button>
                    </div>
                </div>
            </x-application.settings-section>
        </div>
    </x-platform.layout>
</div>
