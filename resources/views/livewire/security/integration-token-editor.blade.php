<div class="w-full">
    <form class="application-settings-form flex w-full flex-col gap-4" wire:submit="save">
        <div class="grid gap-4 lg:grid-cols-2">
            <x-forms.input required id="name" :label="__('Token name')" />
            <x-forms.input readonly :label="__('Provider')" value="{{ $integrationToken->providerName() }}" />
            <div class="lg:col-span-2">
                <x-forms.input type="password" id="newToken"
                    :label="$integrationToken->provider === 'infisical' ? __('New client secret') : __('New API token')"
                    :placeholder="__('Leave blank to keep the current token')"
                    :helper="__('Paste a replacement token to rotate this credential.')" />
            </div>
        </div>

        @if ($integrationToken->provider === 'infisical')
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input required id="metadata.base_url" :label="__('Base URL')"
                    placeholder="https://app.infisical.com" />
                <x-forms.input required id="metadata.client_id" :label="__('Client ID')" />
            </div>
        @elseif ($integrationToken->provider === 'vault')
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input required id="metadata.base_url" :label="__('Base URL')"
                    placeholder="https://vault.example.com:8200" />
                <x-forms.input id="metadata.namespace" :label="__('Namespace (optional)')" />
            </div>
        @endif

        @if ($integrationToken->provider === 'cloudflare')
            <fieldset>
                <legend class="text-sm font-medium text-black dark:text-fg">{{ __('Capabilities') }}</legend>
                <div class="mt-3 rounded-lg border border-neutral-200 p-1 dark:border-white/[0.08]">
                    <x-forms.checkbox id="edit-dns-capability" :label="__('DNS')" domValue="dns" fullWidth
                        wire:model.live="capabilities" />
                    <p class="px-2.5 pb-2 text-[11px] text-neutral-500 dark:text-fg-dim">
                        {{ __('Manage Cloudflare DNS records.') }}
                    </p>
                </div>
                @error('capabilities')
                    <span class="text-xs text-red-500">{{ $message }}</span>
                @enderror
            </fieldset>
            <div class="rounded-lg border border-neutral-200 p-1 dark:border-white/[0.08]">
                <x-forms.checkbox id="edit-automatic-dns" :label="__('Automatically configure DNS')" fullWidth
                    wire:model.live="automaticDns" canGate="update" :canResource="$integrationToken" />
                <p class="px-2.5 pb-2 text-[11px] text-neutral-500 dark:text-fg-dim">
                    {{ __('Create DNS records automatically when a new domain has one unambiguous matching credential.') }}
                </p>
            </div>
        @else
            <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-[11px] leading-5 text-neutral-600 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
                <div class="font-medium text-black dark:text-fg">{{ __('Capability: Secrets (read-only)') }}</div>
                <p>{{ __('Coolify reads secrets from this provider at deployment time. Secret values are never stored in the Coolify database.') }}</p>
            </div>
        @endif

        @if ($integrationToken->provider === 'cloudflare' && in_array('dns', $capabilities, true))
            <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-[11px] leading-5 text-neutral-600 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
                <div class="font-medium text-black dark:text-fg">{{ __('Required Cloudflare permissions') }}</div>
                <ul class="list-inside list-disc">
                    <li>Zone - DNS - Edit</li>
                    <li>Zone - Zone - Read</li>
                </ul>
                <a href="https://dash.cloudflare.com/profile/api-tokens?permissionGroupKeys=%5B%7B%22key%22%3A%22dns%22%2C%22type%22%3A%22edit%22%7D%5D&amp;accountId=%2A&amp;zoneId=all&amp;name=Coolify%20DNS%20Management"
                    target="_blank" rel="noopener noreferrer"
                    class="font-medium text-coollabs hover:underline dark:text-warning">
                    {{ __('Create a replacement token in Cloudflare') }}
                </a>
            </div>
            <div class="rounded-lg border border-neutral-200 dark:border-white/[0.08]">
                <div class="flex items-center justify-between gap-3 p-3">
                    <div class="text-xs text-neutral-600 dark:text-fg-dim">
                        <div class="font-medium text-black dark:text-fg">{{ __('Domains this token can manage') }}</div>
                        <div>{{ trans_choice(':count accessible zone|:count accessible zones', $zoneCount) }}</div>
                    @if (data_get($integrationToken->metadata, 'zones_synced_at'))
                        <div>{{ __('Last refreshed') }} {{ \Carbon\Carbon::parse(data_get($integrationToken->metadata, 'zones_synced_at'))->diffForHumans() }}</div>
                    @endif
                    </div>
                    <x-forms.button type="button" wire:click="refreshZones" wire:target="refreshZones"
                        canGate="update" :canResource="$integrationToken">{{ __('Refresh zones') }}</x-forms.button>
                </div>
                @if ($zones !== [])
                    <div class="max-h-48 overflow-y-auto border-t border-neutral-200 dark:border-white/[0.08]">
                        @foreach ($zones as $zone)
                            <div wire:key="integration-token-zone-{{ $zone['id'] }}"
                                class="flex items-center justify-between gap-3 border-b border-neutral-200 px-3 py-2.5 last:border-b-0 dark:border-white/[0.08]">
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-medium text-black dark:text-fg">{{ $zone['name'] }}</div>
                                    @if ($zone['account_name'])
                                        <div class="truncate text-[11px] text-neutral-500 dark:text-fg-dim">{{ $zone['account_name'] }}</div>
                                    @endif
                                </div>
                                @if ($zone['managed_records_count'] > 0)
                                    <div class="shrink-0 text-[11px] text-neutral-500 dark:text-fg-dim">
                                        {{ $zone['managed_records_count'] }} managed {{ Str::plural('record', $zone['managed_records_count']) }}
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        <div class="flex items-center justify-between gap-2 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
            <x-modal-confirmation :title="__('Delete integration token?')" isErrorButton :buttonTitle="__('Delete')"
                submitAction="delete" :actions="[__('This integration token will be permanently deleted.')]"
                confirmationText="{{ $integrationToken->name }}" :confirmWithPassword="false"
                :step2ButtonText="__('Delete token')" />
            <x-forms.button type="submit" wire:target="save" isHighlighted>
                {{ __('Validate and save') }}
            </x-forms.button>
        </div>
    </form>
</div>
