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
                        @if ($server->isTransferredAway())
                            {{ __('This server was transferred to another Coolify instance. Only the records on this instance are deleted, also the records of its resources. The server, its containers, and its data stay as they are.') }}
                        @elseif ($resourcesToDelete->isNotEmpty())
                            {{ __('It currently contains managed resources. Enable force deletion in the confirmation only if those resources should also be removed.') }}
                        @endif
                        </p>
                        @if (! $server->isTransferredAway() && $resourcesToDelete->isNotEmpty())
                            <div>
                                <p>{{ __('Deleted with "Delete all resources":') }}</p>
                                <ul class="list-disc pl-5">
                                    @foreach ($resourcesToDelete as $resource)
                                        <li>{{ $resource->name }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        @if ($detachedApplications->isNotEmpty())
                            <div>
                                <p>{{ __('Removed only from this server (not deleted):') }}</p>
                                <ul class="list-disc pl-5">
                                    @foreach ($detachedApplications as $application)
                                        <li>{{ $application->name }} will keep running on {{ data_get($application, 'destination.server.name') }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        @if ($server->hostinger_virtual_machine_id && ! $server->isTransferredAway())
                            <p>
                            {{ __('Hostinger deletes the VPS only at the end of its paid period, and only if you select this in the confirmation dialog.') }}
                            <a class="underline" href="https://hpanel.hostinger.com/" target="_blank"
                                rel="noopener noreferrer">{{ __('Manage the VPS and billing in hPanel.') }}</a>
                            </p>
                        @endif
                        <p>{{ __('Type the server name in the confirmation dialog to continue.') }}</p>
                        <x-slot:action>
                        @php
                            $deleteActions = $server->isTransferredAway()
                                ? ['This server and the records of its resources will be permanently deleted from this Coolify instance. Nothing on the server changes.']
                                : ['This server will be permanently deleted from Coolify.'];
                            foreach ($detachedApplications as $application) {
                                $deleteActions[] = "Application {$application->name} will be removed from this server and will keep running on ".data_get($application, 'destination.server.name').'.';
                            }
                            $deleteWarningMessage = null;
                            if ($server->isTransferredAway()) {
                                $deleteWarningMessage = '<strong>This removes the option to undo the transfer.</strong><br>'
                                    .'This archive is the copy that "Transfer back" and "Take back" use. '
                                    .'Delete it only when the transfer works and you do not need to undo it.';
                            }
                            if ($server->hostinger_virtual_machine_id && ! $server->isTransferredAway()) {
                                $deleteWarningMessage = '<strong>Hostinger deletes a VPS only at the end of its paid period.</strong><br>'
                                    .'If you select to delete the VPS from Hostinger, Coolify turns off its auto-renewal. '
                                    .'The VPS keeps running until the paid period ends. Then Hostinger deletes it with all its data. '
                                    .'To delete it now or to request a refund, '
                                    .'<a class="underline font-semibold" href="https://hpanel.hostinger.com/" target="_blank" rel="noopener noreferrer">cancel the VPS in hPanel</a>.';
                            }
                        @endphp
                        <x-modal-confirmation :title="__('Confirm Server Deletion?')" isErrorButton
                            :buttonTitle="__('Delete server')" submitAction="delete"
                            :actions="$deleteActions" :warningMessage="$deleteWarningMessage"
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
