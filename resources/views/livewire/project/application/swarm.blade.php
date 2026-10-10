<form wire:submit="submit" class="application-settings-form flex flex-col gap-6">
    <x-unsaved-bar action="submit" />

    <x-application.settings-section :title="__('Swarm configuration')"
        :description="__('Replicas and node placement for Docker Swarm deployments. Set CPU and memory limits under Resource Limits so Swarm only places replicas on nodes with enough free resources.')">
        <div class="grid gap-4 lg:grid-cols-2">
            <x-forms.input id="swarmReplicas" :label="__('Replicas')" required canGate="update"
                :canResource="$application" />
            <x-forms.listbox canGate="update" :canResource="$application" id="isSwarmOnlyWorkerNodes" :label="__('Node placement')" live onChange="instantSave"
                :disabled="! auth()->user()->can('update', $application)" :options="[
                    ['value' => true, 'label' => __('Worker nodes only')],
                    ['value' => false, 'label' => __('Manager and worker nodes')],
                ]" />
            <div class="lg:col-span-2">
                <x-forms.textarea id="swarmPlacementConstraints" rows="8" :label="__('Custom placement constraints')"
                    placeholder="placement:
    constraints:
        - 'node.role == worker'" canGate="update" :canResource="$application" />
            </div>
        </div>
    </x-application.settings-section>
</form>
