<div>
    <x-slot:title>
        {{ $title }} | {{ brand_name() }}
    </x-slot>

    <livewire:server.create :selected-type="$type" :selected-token-uuid="$token_uuid" />
</div>
