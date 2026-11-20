<div>
    <x-slot:title>
        {{ __('Server Variables') }} | {{ brand_name() }}
    </x-slot>

    <x-shared-variables.editor :resource="$server"
        :variables="$server->environment_variables"
        :readOnlyKeys="['COOLIFY_SERVER_UUID', 'COOLIFY_SERVER_NAME']"
        type="server" title="{{ $server->name }}"
        :view="$view" :variablesLabel="__('Server shared variables')" />
</div>
