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
