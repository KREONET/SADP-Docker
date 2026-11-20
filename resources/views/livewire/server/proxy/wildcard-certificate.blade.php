<div class="application-settings-form mt-6 flex w-full flex-col gap-6">
    <x-application.settings-section id="server-proxy-wildcard-certificate-section"
        :title="__('Wildcard certificate (DNS-01)')"
        :helper="__('Issue one Let\'s Encrypt certificate for a domain and all of its direct subdomains through the DNS-01 challenge. Traefik proves domain ownership by creating a TXT record with your DNS provider, so it also works when port 80 is not reachable from the internet.')">
        @if (! $isTraefik)
            <x-callout type="info" :title="__('Traefik only')">
                {{ __('DNS-01 wildcard certificates need a Caddy build with DNS provider modules, which this proxy does not include. Switch the server to Traefik to use them.') }}
            </x-callout>
        @else
            <form wire:submit="save" class="flex flex-col gap-4">
                <x-forms.checkbox canGate="update" :canResource="$server" id="isEnabled"
                    :label="__('Use a wildcard certificate')"
                    :helper="__('Applications and services whose domain is the base domain or one level below it use the wildcard certificate after their next deployment. Other domains keep their own HTTP-01 certificates.')" />

                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.input canGate="update" :canResource="$server" id="domain" :label="__('Base domain')"
                        placeholder="example.com"
                        :helper="__('The certificate covers :domain and *.:domain. Point both DNS records to this server.', ['domain' => $certificateDomain])" />
                    <x-forms.listbox canGate="update" :canResource="$server" id="provider" :label="__('DNS provider')"
                        :options="$providerOptions" live :helper="$selectedProvider->helper()" />
                </div>

                @if ($selectedProvider === \App\Enums\DnsChallengeProvider::Other)
                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-forms.input canGate="update" :canResource="$server" id="otherProviderCode"
                            :label="__('Provider code')" placeholder="gandiv5"
                            :helper="__('The code from the Traefik DNS provider list, for example gandiv5 or ovh.')" />
                    </div>
                    <x-forms.textarea canGate="update" :canResource="$server" id="otherCredentials"
                        :label="__('Environment variables')" rows="4"
                        :placeholder="count($storedCredentialKeys) > 0 ? __('Saved: :keys. Leave empty to keep them.', ['keys' => implode(', ', $storedCredentialKeys)]) : 'GANDIV5_PERSONAL_ACCESS_TOKEN=...'"
                        :helper="__('One KEY=value per line. Entering values replaces all saved ones.')" />
                @else
                    <div class="grid gap-4 lg:grid-cols-2">
                        @foreach ($selectedProvider->credentialFields() as $field)
                            @php
                                $isSaved = $field['secret'] && in_array($field['key'], $storedCredentialKeys, true);
                            @endphp
                            <x-forms.input canGate="update" :canResource="$server" id="credentials.{{ $field['key'] }}"
                                wire:key="dns-credential-{{ $selectedProvider->value }}-{{ $field['key'] }}"
                                :label="$field['label'].' ('.$field['key'].')'"
                                :type="$field['secret'] ? 'password' : 'text'" autocomplete="new-password"
                                :placeholder="$isSaved ? __('Saved. Leave empty to keep it.') : ($field['default'] ?? '')" />
                        @endforeach
                    </div>
                @endif

                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.input canGate="update" :canResource="$server" id="email" type="email"
                        :label="__('ACME email')"
                        :helper="__('Let\'s Encrypt sends expiry notices to this address.')" />
                    <x-forms.input canGate="update" :canResource="$server" id="resolvers"
                        :label="__('DNS resolvers (advanced)')" placeholder="1.1.1.1:53,8.8.8.8:53"
                        :helper="__('Resolvers Traefik uses to check that the TXT record propagated. Leave empty for 1.1.1.1 and 8.8.8.8.')" />
                </div>

                @can('update', $server)
                    <div class="flex flex-wrap items-center gap-2">
                        <x-forms.button type="submit">{{ __('Save') }}</x-forms.button>
                        <x-modal-confirmation :title="__('Apply and restart the proxy?')"
                            :buttonTitle="__('Apply and restart proxy')" submitAction="applyAndRestart"
                            :actions="[
                                __('The saved wildcard certificate settings are written to the Traefik configuration.'),
                                __('The proxy restarts. All resources on this server are unavailable for a few seconds.'),
                            ]" :confirmWithText="false" :confirmWithPassword="false"
                            :step2ButtonText="__('Apply and restart proxy')" />
                    </div>
                @endcan
            </form>
        @endif
    </x-application.settings-section>
</div>
