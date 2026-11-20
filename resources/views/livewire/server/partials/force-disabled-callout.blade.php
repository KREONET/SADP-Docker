@if ($server->canBeReEnabled())
    <x-callout type="danger" :title="__('Server disabled')" class="mb-4">
        {{ __('Coolify disabled this server because it was unreachable for more than 7 days. Coolify does not connect to it. Correct the connection settings, then click') }} <strong>{{ __('Validate connection') }}</strong>{{ __('. When the connection works, the server is enabled again.') }}
    </x-callout>
@endif
