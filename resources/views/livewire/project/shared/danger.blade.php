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
    @if ($resource instanceof \App\Models\Service && !$resource->server?->isFunctional())
        <x-callout type="warning" :title="__('Server is not reachable')" class="mb-4">
            {{ __('Coolify cannot remove or verify Docker resources on this server. The deletion dialog will default to removing this service from Coolify only. Its containers, volumes, networks, and configuration files may remain on the server.') }}
        </x-callout>
    @endif
    <x-application.settings-section id="danger-zone-section" :title="__('Danger zone')"
        :helper="__('Destructive resource actions cannot be undone.')">
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
                        @if ($resource instanceof \App\Models\Service)
                            <x-modal-confirmation :title="__('Remove service from Coolify only?')"
                                :buttonTitle="__('Remove from Coolify only')"
                                isErrorButton submitAction="deleteFromCoolifyOnly"
                                :actions="[
                                    __('Permanently remove this service from Coolify.'),
                                    __('Leave all containers, volumes, networks, and configuration files on the server.'),
                                ]"
                                :warningMessage="__('Coolify will no longer track or manage the Docker resources for this service. Use this only when normal cleanup cannot complete.')"
                                confirmationText="{{ $resourceName }}"
                                :confirmationLabel="__('Enter the resource name to confirm metadata-only deletion')"
                                :shortConfirmationLabel="__('Resource name')" />
                        @endif
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
