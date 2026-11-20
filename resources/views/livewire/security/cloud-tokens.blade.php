<div>
    <x-slot:title>
        {{ __('Cloud Tokens') }} | {{ brand_name() }}
    </x-slot>

    <x-security.settings-layout>
        <livewire:security.cloud-provider-tokens />
    </x-security.settings-layout>
</div>
