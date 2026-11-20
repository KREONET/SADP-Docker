<div class="w-full">
    <form class="application-settings-form flex w-full flex-col gap-4" wire:submit="addToken">
        <x-forms.listbox required id="provider" :label="__('Provider')" :live="true" :options="[
            ['value' => 'cloudflare', 'label' => 'Cloudflare'],
            ['value' => 'doppler', 'label' => 'Doppler'],
            ['value' => 'infisical', 'label' => 'Infisical'],
            ['value' => 'vault', 'label' => 'HashiCorp Vault'],
        ]" />

        @if ($provider === 'infisical')
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input required id="name" :label="__('Token name')" :placeholder="__('Production secrets')" />
                <x-forms.input required id="metadata.client_id" :label="__('Client ID')"
                    :placeholder="__('Machine identity client ID')" />
                <x-forms.input required type="password" id="token" :label="__('Client secret')"
                    :placeholder="__('Paste the machine identity client secret')" />
                <x-forms.input required id="metadata.base_url" :label="__('Base URL')"
                    placeholder="https://app.infisical.com"
                    :helper="__('Use the URL of your self-hosted Infisical instance, or the Infisical cloud URL.')" />
                <x-forms.input type="number" min="1" max="300" id="metadata.timeout" label="{{ __('Request timeout (optional)') }}"
                    placeholder="10" helper="Seconds to wait for each request. Increase it if your instance responds slowly." />
            </div>
        @else
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input required id="name" :label="__('Token name')"
                    :placeholder="$provider === 'cloudflare' ? __('Production DNS') : __('Production secrets')" />
                <x-forms.input required type="password" id="token"
                    :label="$provider === 'vault' ? __('Vault token') : __('API token')"
                    :placeholder="__('Paste the provider token')" />
            </div>
        @endif

        @if ($provider === 'vault')
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input required id="metadata.base_url" :label="__('Base URL')"
                    placeholder="https://vault.example.com:8200" />
                <x-forms.input id="metadata.namespace" :label="__('Namespace (optional)')"
                    placeholder="admin/team-a" :helper="__('Only for Vault Enterprise / HCP Vault.')" />
                <x-forms.input type="number" min="1" max="300" id="metadata.timeout" label="{{ __('Request timeout (optional)') }}"
                    placeholder="10" helper="Seconds to wait for each request. Increase it if your instance responds slowly." />
            </div>
        @endif

        @if ($provider === 'cloudflare')
            <fieldset>
                <legend class="text-sm font-medium text-black dark:text-fg">{{ __('Capabilities') }}</legend>
                <div class="mt-3 rounded-lg border border-neutral-200 p-1 dark:border-white/[0.08]">
                    <x-forms.checkbox id="dns-capability" :label="__('DNS')" domValue="dns" fullWidth
                        :disabled="in_array('tunnel', $capabilities, true)" wire:model.live="capabilities" />
                    <p class="px-2.5 pb-2 text-[11px] text-neutral-500 dark:text-fg-dim">
                        {{ __('Manage Cloudflare DNS records.') }}
                    </p>
                    <x-forms.checkbox id="tunnel-capability" label="{{ __('Cloudflare Tunnel') }}" domValue="tunnel" fullWidth
                        wire:model.live="capabilities" />
                    <p class="px-2.5 pb-2 text-[11px] text-neutral-500 dark:text-fg-dim">
                        {{ __('Create and manage tunnels for server SSH. Needs DNS.') }}
                    </p>
                </div>
                @error('capabilities')
                    <span class="text-xs text-red-500">{{ $message }}</span>
                @enderror
            </fieldset>
            <div class="rounded-lg border border-neutral-200 p-1 dark:border-white/[0.08]">
                <x-forms.checkbox id="automatic-dns" :label="__('Automatically configure DNS')" fullWidth
                    wire:model.live="automaticDns" canGate="create" :canResource="\App\Models\IntegrationToken::class" />
                <p class="px-2.5 pb-2 text-[11px] text-neutral-500 dark:text-fg-dim">
                    {{ __('Create DNS records automatically when a new domain has one unambiguous matching credential.') }}
                </p>
            </div>
            <div class="rounded-lg border border-neutral-200 p-1 dark:border-white/[0.08]">
                <x-forms.checkbox id="proxied-dns" label="{{ __('Proxy DNS records through Cloudflare') }}" fullWidth
                    wire:model.live="proxiedDns" canGate="create" :canResource="\App\Models\IntegrationToken::class" />
                <p class="px-2.5 pb-2 text-[11px] text-neutral-500 dark:text-fg-dim">
                    {{ __('Create new DNS records with the Cloudflare proxy on (orange cloud), so the server IP is not public. Existing records keep their proxy status.') }}
                </p>
            </div>
        @else
            <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-[11px] leading-5 text-neutral-600 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
                <div class="font-medium text-black dark:text-fg">{{ __('Capability: Secrets (read-only)') }}</div>
                <p>{!! __('Coolify reads secrets from this provider at deployment time and writes them into the generated <code>.env</code> file. Secret values are never stored in the Coolify database.') !!}</p>
            </div>
        @endif

        @if ($provider === 'cloudflare' && in_array('dns', $capabilities, true))
            <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-[11px] leading-5 text-neutral-600 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
                <div class="font-medium text-black dark:text-fg">{{ __('Required Cloudflare permissions') }}</div>
                <ul class="list-inside list-disc">
                    <li>Zone - DNS - Edit</li>
                    <li>Zone - Zone - Read</li>
                    @if (in_array('tunnel', $capabilities, true))
                        <li>Account - Cloudflare Tunnel - Edit</li>
                    @endif
                </ul>
                <p>{{ __('Limit zone resources to the zones Coolify should manage.') }}</p>
                <a href="{{ \App\Models\IntegrationToken::cloudflareTokenTemplateUrl($capabilities) }}"
                    target="_blank" rel="noopener noreferrer"
                    class="button mt-2">
                    {{ __('Create token at Cloudflare') }}
                    <x-external-link />
                </a>
            </div>
        @elseif ($provider === 'doppler')
            <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-[11px] leading-5 text-neutral-600 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
                <div class="font-medium text-black dark:text-fg">{{ __('Recommended Doppler token') }}</div>
                <p>{{ __('Use a read-only') }} <span class="font-medium">{{ __('Service Token') }}</span> {{ __('(dp.st.*). It is pinned to one project and config. Create it in Doppler under Project > Config > Access.') }}</p>
                <a href="https://dashboard.doppler.com?ref=coolify.io" target="_blank" rel="noopener noreferrer" class="button mt-2">
                    Open Doppler dashboard
                    <x-external-link />
                </a>
            </div>
        @elseif ($provider === 'infisical')
            <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-[11px] leading-5 text-neutral-600 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
                <div class="font-medium text-black dark:text-fg">{{ __('Infisical machine identity') }}</div>
                <p>{{ __('Create a machine identity with Universal Auth and read access to your project. Paste the client ID above and the client secret in the secret field.') }}</p>
                <a href="https://app.infisical.com?ref=coolify.io" target="_blank" rel="noopener noreferrer" class="button mt-2">
                    Open Infisical Cloud
                    <x-external-link />
                </a>
            </div>
        @elseif ($provider === 'vault')
            <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-[11px] leading-5 text-neutral-600 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
                <div class="font-medium text-black dark:text-fg">{{ __('Vault token') }}</div>
                <p>{{ __('Use a token with read access to your KV v2 secrets. Prefer a periodic or long-lived token — deployments fail when the token expires.') }}</p>
                <a href="https://portal.cloud.hashicorp.com?ref=coolify.io" target="_blank" rel="noopener noreferrer" class="button mt-2">
                    Open HCP Vault
                    <x-external-link />
                </a>
            </div>
        @endif

        <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
            <x-forms.button type="submit" wire:target="addToken" isHighlighted>
                {{ __('Validate and add') }}
            </x-forms.button>
        </div>
    </form>
</div>
