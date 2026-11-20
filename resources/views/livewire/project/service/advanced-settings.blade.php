<div class="grid gap-4 sm:grid-cols-2">
    @if ($resourceType === 'application')
        @if (str($serviceApplication->image)->contains('pocketbase'))
            <x-forms.listbox id="isGzipEnabled" :label="__('Gzip compression')"
                :helper="__('PocketBase keeps compression disabled so server-sent events continue to work.')"
                :disabled="true" :options="[
                    ['value' => true, 'label' => __('Enabled')],
                    ['value' => false, 'label' => __('Disabled')],
                ]" />
        @else
            <x-forms.listbox id="isGzipEnabled" :label="__('Gzip compression')"
                :options="[
                    ['value' => true, 'label' => __('Enabled')],
                    ['value' => false, 'label' => __('Disabled')],
                ]" />
        @endif
        <x-forms.listbox id="isStripprefixEnabled" :label="__('Path prefixes')"
            :options="[
                ['value' => true, 'label' => __('Strip prefixes')],
                ['value' => false, 'label' => __('Keep prefixes')],
            ]" />
        <x-forms.listbox id="excludeFromStatus" :label="__('Service status')"
            :options="[
                ['value' => false, 'label' => __('Include in status')],
                ['value' => true, 'label' => __('Exclude from status')],
            ]" />
        <x-forms.listbox id="isLogDrainEnabled" :label="__('Log drain')"
            :options="[
                ['value' => true, 'label' => __('Send logs to drain')],
                ['value' => false, 'label' => __('Do not drain logs')],
            ]" />
        <x-forms.input type="number" min="0" id="maxRestartCount" :label="__('Max restart count')"
            :helper="__('Maximum number of Docker restarts before Coolify stops this container. Set to 0 to disable the limit. Docker counts expected and unexpected restarts.')"
            canGate="update" :canResource="$serviceApplication" />
        @if ($showCertificateResolver)
            <x-forms.listbox id="certificateResolverMode" :label="__('Certificate resolver')" live
                :helper="__('Sets the Traefik certificate resolver for this service\'s HTTPS routes. Choose None for domains that use certificates from Traefik\'s dynamic configuration (for example Cloudflare Origin CA certificates). Restart or redeploy the service to apply the change.')"
                canGate="update" :canResource="$serviceApplication" :options="[
                    ['value' => 'server', 'label' => 'Server setting ('.$serverCertificateResolver.')'],
                    ['value' => 'none', 'label' => 'None (use your own certificates)'],
                    ['value' => 'custom', 'label' => 'Custom resolver'],
                ]" />
            @if ($certificateResolverMode === 'custom')
                <x-forms.input id="certificateResolverName" :label="__('Certificate resolver name')" placeholder="cloudflare"
                    required :helper="__('Name of a certificate resolver defined in the Traefik configuration of this server.')"
                    canGate="update" :canResource="$serviceApplication" />
            @endif
        @endif
    @else
        <x-forms.listbox id="excludeFromStatus" :label="__('Service status')"
            :options="[
                ['value' => false, 'label' => __('Include in status')],
                ['value' => true, 'label' => __('Exclude from status')],
            ]" />
        <x-forms.listbox id="isLogDrainEnabled" :label="__('Log drain')"
            :options="[
                ['value' => true, 'label' => __('Send logs to drain')],
                ['value' => false, 'label' => __('Do not drain logs')],
            ]" />
    @endif
</div>
