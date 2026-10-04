<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > {{ __('Delete Server') }} | {{ brand_name() }}
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="danger" />

        <div class="application-settings-form w-full">
            @if (! $server->is_coolify_host)
                <x-application.settings-section id="server-danger-section" :title="__('Delete server')"
                    :helper="__('Permanently remove this server and its configuration from Coolify.')"
                    class="server-danger-section">
                    <x-danger-zone :title="__('This action cannot be undone')">
                        <p>
                        {{ __('The server will be removed from Coolify.') }}
                        @if ($server->definedResources()->count() > 0)
                            {{ __('It currently contains managed resources. Enable force deletion in the confirmation only if those resources should also be removed.') }}
                        @endif
                        </p>
                        <p>{{ __('Type the server name in the confirmation dialog to continue.') }}</p>
                        <x-slot:action>
                        <x-modal-confirmation :title="__('Confirm Server Deletion?')" isErrorButton
                            :buttonTitle="__('Delete server')" submitAction="delete"
                            :actions="[__('This server will be permanently deleted from Coolify.')]"
                            :checkboxes="$checkboxes" confirmationText="{{ $server->name }}"
                            :confirmationLabel="__('Please confirm by entering the Server Name below')"
                            :shortConfirmationLabel="__('Server Name')" />
                        </x-slot:action>
                    </x-danger-zone>
                </x-application.settings-section>
            @endif
        </div>
    </div>
</div>
