<div class="application-settings-form flex w-full flex-col gap-6">
    <form wire:submit.prevent="saveTrafficAnalyticsSettings" class="contents">
        @if ($isTrafficAnalyticsEnabled)
            <x-unsaved-bar action="saveTrafficAnalyticsSettings"
                targets="trafficTopn,trafficSampleThreshold,trafficRetention1hDays,trafficRetention1dDays,isGeoipEnabled,geoipRefreshDays,geoipMaxmindLicenseKey" />
        @endif

        <x-application.settings-section id="server-traffic-analytics-settings-section" :title="__('Traffic analytics')"
            :helper="__('Control proxy traffic collection, retention, and visitor geolocation for this server.')">
            <x-slot:actions>
                @if ($isTrafficAnalyticsEnabled)
                    <div class="flex items-center gap-3">
                        <x-loading wire:loading.flex wire:target="toggleTrafficAnalytics"
                            :text="__('Restarting Sentinel and proxy...')" compact />
                        <x-modal-confirmation :title="__('Disable traffic analytics?')"
                            :buttonTitle="__('Disable traffic analytics')" submitAction="toggleTrafficAnalytics"
                            :actions="[
                                __('Disabling traffic analytics will restart Sentinel and the proxy. Your applications will experience a brief interruption.'),
                            ]"
                            :warningMessage="__('Application traffic may be interrupted while Sentinel and the proxy restart.')"
                            :confirmWithText="false" :confirmWithPassword="false" :ignoreWire="false"
                            :step2ButtonText="__('Disable traffic analytics')"
                            :disabled="! auth()->user()->can('update', $server)"
                            :authDisabled="! auth()->user()->can('update', $server)" />
                    </div>
                @endif
            </x-slot:actions>

            @if ($outdatedCaddyImage)
                <x-server.caddy-image-outdated-callout :image="$outdatedCaddyImage" />
            @endif

            @if ($isTrafficAnalyticsEnabled)
                @if ($caddyRedeployNote)
                    <x-callout type="info" :title="__('Redeploy to start logging')">{{ $caddyRedeployNote }}</x-callout>
                @endif
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.input canGate="update" :canResource="$server" type="number" min="1"
                        id="trafficTopn" :label="__('Top-N cap')" required
                        :helper="__('Maximum distinct values kept per dimension (paths, countries, browsers). Overflow folds into Other.')" />
                    <x-forms.input canGate="update" :canResource="$server" type="number" min="0"
                        id="trafficSampleThreshold" :label="__('Sample threshold')" required
                        :helper="__('Events per second above which Sentinel starts sampling. 0 disables sampling.')" />
                    <x-forms.input canGate="update" :canResource="$server" type="number" min="1"
                        id="trafficRetention1hDays" :label="__('Hourly retention')" required
                        :helper="__('Days of hourly rollups to keep. This is the fine-grained history window.')" />
                    <x-forms.input canGate="update" :canResource="$server" type="number" min="1"
                        id="trafficRetention1dDays" :label="__('Daily retention')" required
                        :helper="__('Days of daily rollups to keep before deletion.')" />
                    <x-forms.listbox canGate="update" :canResource="$server"
                        id="isGeoipEnabled" :label="__('Geolocation')"
                        :options="[
                            ['value' => true, 'label' => __('Enabled')],
                            ['value' => false, 'label' => __('Disabled')],
                        ]"
                        :helper="__('Country enrichment from visitor IPs. Disable to skip GeoIP lookups.')" />
                    <x-forms.input canGate="update" :canResource="$server" type="number" min="1"
                        id="geoipRefreshDays" :label="__('GeoIP refresh interval')" required
                        :helper="__('Days between GeoIP database update checks.')" />
                    <x-forms.input canGate="update" :canResource="$server" type="password"
                        id="geoipMaxmindLicenseKey" :label="__('MaxMind GeoIP license key')" :placeholder="__('Optional')"
                        :helper="__('Used to download the GeoLite2 database for visitor geolocation. Leave empty to use the default database source.')" />
                </div>
            @else
                <x-empty size="sm" :title="__('Traffic analytics is disabled')"
                    :description="$unsupportedReason ?? __('Enable traffic analytics to collect proxy access logs and geolocate visitor traffic.')"
                    icon-name="dashboard">
                    <x-slot:contents>
                        <div class="flex items-center gap-3">
                            @if ($unsupportedReason)
                                <x-forms.button disabled :tooltip="$unsupportedReason">
                                    {{ __('Enable traffic analytics') }}
                                </x-forms.button>
                            @else
                            <x-loading wire:loading.flex wire:target="toggleTrafficAnalytics"
                                :text="__('Restarting Sentinel and proxy...')" compact />
                            <x-modal-confirmation :title="__('Enable traffic analytics?')"
                                :buttonTitle="__('Enable traffic analytics')" submitAction="toggleTrafficAnalytics"
                                :actions="[
                                    __('Enabling traffic analytics will restart Sentinel and the proxy. Your applications will experience a brief interruption.'),
                                ]"
                                :warningMessage="__('Application traffic may be interrupted while Sentinel and the proxy restart.')"
                                :confirmWithText="false" :confirmWithPassword="false" :ignoreWire="false"
                                :step2ButtonText="__('Enable traffic analytics')" isHighlightedButton
                                :disabled="! auth()->user()->can('update', $server)"
                                :authDisabled="! auth()->user()->can('update', $server)" />
                            @endif
                        </div>
                    </x-slot:contents>
                </x-empty>
            @endif
        </x-application.settings-section>
    </form>
</div>
