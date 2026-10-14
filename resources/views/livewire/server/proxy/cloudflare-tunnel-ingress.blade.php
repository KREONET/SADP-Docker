<div class="application-settings-form mt-6 flex w-full flex-col gap-6">
    <x-application.settings-section id="server-proxy-cloudflare-tunnel-ingress-section"
        :title="__('Cloudflare Tunnel (public traffic)')"
        :helper="__('For setups that cannot get a public IP, open ports, or DNS-01 credentials. cloudflared runs next to the proxy and opens an outbound tunnel to Cloudflare. Cloudflare serves your domains with its own certificates and sends the requests through the tunnel to this proxy.')">
        @if ($blockers !== [])
            <x-callout type="warning" :title="__('Not available')">
                {{ $blockers[0] }}
            </x-callout>
        @else
            <x-callout type="info" :title="__('Set up the tunnel in Cloudflare')">
                <ol class="ml-4 list-decimal space-y-1">
                    <li>{{ __('In Cloudflare Zero Trust, open Networks → Tunnels and create a tunnel of type Cloudflared.') }}</li>
                    <li>{{ __('Copy the token from the install command (the long value after --token) and paste it below.') }}</li>
                    <li>{{ __('Add a public hostname for each domain, or one wildcard such as *.example.com, with the service type HTTP and the URL coolify-proxy:80.') }}</li>
                    <li>
                        {{ __('Cloudflare creates the DNS record for each public hostname. For a wildcard, add a proxied CNAME record * that points to :target.', ['target' => ($tunnelId ?? '<tunnel-id>').'.cfargotunnel.com']) }}
                    </li>
                </ol>
            </x-callout>

            {{-- Applying restarts the proxy, so only the confirmation modal submits. --}}
            <form x-on:submit.prevent class="flex flex-col gap-4">
                <x-forms.checkbox canGate="update" :canResource="$server" id="isEnabled"
                    :label="__('Receive public traffic through a Cloudflare Tunnel')"
                    :helper="__('Domains are served over HTTP behind the tunnel, because Cloudflare handles HTTPS. Applications still see https as the original scheme. This also covers servers routed through this one.')" />
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.input canGate="update" :canResource="$server" id="token" type="password"
                        autocomplete="new-password" :label="__('Tunnel token')"
                        :placeholder="$hasStoredToken ? __('Saved. Leave empty to keep it.') : 'eyJhIjoi...'"
                        :helper="$tunnelId ? __('Tunnel ID: :id', ['id' => $tunnelId]) : __('Stored encrypted. It is never shown again.')" />
                </div>
                @can('update', $server)
                    <div>
                        <x-modal-confirmation :title="__('Apply and restart the proxy?')"
                            :buttonTitle="__('Apply and restart proxy')" submitAction="applyAndRestart"
                            :actions="[
                                __('cloudflared is started or removed on this server.'),
                                __('The proxy restarts. All resources on this server are unavailable for a few seconds.'),
                                __('Redeploy applications and restart services afterwards so their proxy labels follow the change.'),
                            ]" :confirmWithText="false" :confirmWithPassword="false"
                            :step2ButtonText="__('Apply and restart proxy')" />
                    </div>
                @endcan
            </form>
        @endif
    </x-application.settings-section>
</div>
