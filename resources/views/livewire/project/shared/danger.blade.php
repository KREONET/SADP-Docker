<div>
    @php
        $resourceLabel = match ($resource?->type()) {
            'application' => __('application'),
            'service' => __('service'),
            'service-application' => __('service application'),
            'service-database' => __('service database'),
            default => __('resource'),
        };
    @endphp
    @if ($resource && !data_get($resource, 'destination.server')?->isFunctional())
        <x-callout type="warning" title="Server is not reachable" class="mb-4">
            Coolify cannot stop or remove Docker resources on this server. Deleting removes this {{ $resourceLabel }}
            from Coolify only. Its containers, volumes, networks, and configuration files remain on the server and can
            start again when the server is back.
        </x-callout>
    @endif
    <x-application.settings-section id="danger-zone-section" :title="__('Danger zone')"
        :helper="__('Destructive resource actions cannot be undone.')">
        @if (in_array($resource?->type(), ['standalone-redis', 'standalone-keydb', 'standalone-dragonfly']))
            <x-danger-zone title="Flush all data" class="mb-4">
                <p>
                    Permanently remove every key from
                    <strong class="font-semibold text-black dark:text-fg">{{ $resourceName }}</strong>.
                    The database keeps running.
                </p>
                <x-slot:action>
                    <x-modal-confirmation title="Flush all data?" buttonTitle="Flush all data" isErrorButton
                        submitAction="flush" :actions="['Permanently remove every key from every logical database.']"
                        confirmationText="{{ $resourceName }}"
                        confirmationLabel="Enter the database name to confirm flushing all data"
                        shortConfirmationLabel="Database name" :disabled="auth()->user()->cannot('manage', $resource)"
                        disabledTooltip="You do not have permission to flush this database." />
                </x-slot:action>
            </x-danger-zone>
        @endif
        <x-danger-zone :title="__('Delete :resource', ['resource' => $resourceLabel])">
                    <p>
                        {!! __('Permanently delete :name, stop its containers, and remove the selected Docker resources and configuration.', ['name' => '<strong class="font-semibold text-black dark:text-fg">'.e($resourceName).'</strong>']) !!}
                    </p>
                    <ul class="space-y-1 text-xs">
                        <li>• {{ __('Active deployments will be stopped.') }}</li>
                        <li>• {{ __('Selected volumes and stored data may be permanently removed.') }}</li>
                        <li>• {{ __('This :resource cannot be restored from Coolify after deletion.', ['resource' => $resourceLabel]) }}</li>
                    </ul>
                <x-slot:action>
                    @if ($canDelete)
                        <x-modal-confirmation :title="__('Delete :resource?', ['resource' => $resourceLabel])"
                            :buttonTitle="__('Delete :resource', ['resource' => $resourceLabel])"
                            isErrorButton submitAction="delete" :checkboxes="$checkboxes"
                            :actions="[__('Permanently delete this resource and its selected Docker resources.')]"
                            confirmationText="{{ $resourceName }}"
                            :confirmationLabel="__('Enter the resource name to confirm permanent deletion')"
                            :shortConfirmationLabel="__('Resource name')" />
                    @else
                        <x-forms.button isError disabled :tooltip="__('You do not have permission to delete this resource.')">
                            {{ __('Delete :resource', ['resource' => $resourceLabel]) }}
                        </x-forms.button>
                    @endif
                </x-slot:action>
        </x-danger-zone>

        @if (!$canDelete)
            <div class="mt-4">
                <x-callout type="danger" :title="__('Insufficient permissions')">
                    {{ __('Contact a team administrator if this resource must be deleted.') }}
                </x-callout>
            </div>
        @endif
    </x-application.settings-section>
</div>
