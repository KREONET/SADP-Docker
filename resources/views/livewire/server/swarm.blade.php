<div @if ($isManager) wire:init="loadNodes" @endif>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > {{ __('Swarm') }} | {{ brand_name() }}
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="swarm" />

        <div class="application-settings-form flex w-full flex-col gap-6">
            @if ($isWorker)
                <x-application.settings-section id="server-swarm-section" :title="__('Docker Swarm')"
                    :helper="__('This server is a worker node. The manager places application tasks on it.')">
                    <x-slot:actions>
                        <x-status-badge :status="__('Worker node')" type="success" />
                    </x-slot:actions>

                    <p class="text-sm leading-6 text-neutral-600 dark:text-fg-dim">
                        @if ($manager)
                            {{ __('Manager:') }}
                            <a class="font-medium underline" href="{{ route('server.swarm', ['server_uuid' => $manager->uuid]) }}"
                                wire:navigate>{{ $manager->name }}</a>.
                            {{ __('Remove this node from the manager\'s Swarm page.') }}
                        @else
                            {{ __('The manager of this cluster is not available in this team.') }}
                        @endif
                    </p>
                </x-application.settings-section>
            @elseif ($isManager)
                <x-application.settings-section id="server-swarm-section" :title="__('Docker Swarm cluster')"
                    :helper="__('This server is the manager and also runs workloads. Applications deployed to its Swarm destination are spread across all nodes, and Swarm moves them when a node fails or lacks the reserved resources.')"
                    flush>
                    <x-slot:actions>
                        <x-status-badge :status="__('Manager node')" type="success" />
                        <x-forms.button wire:click="loadNodes">{{ __('Refresh') }}</x-forms.button>
                    </x-slot:actions>

                    @if ($nodesError)
                        <div class="p-4">
                            <x-callout type="danger" :title="__('Nodes unavailable')">{{ $nodesError }}</x-callout>
                        </div>
                    @endif

                    <div class="divide-y divide-neutral-200 dark:divide-white/[0.07]">
                        @forelse ($nodes as $node)
                            <div wire:key="swarm-node-{{ $node['id'] }}"
                                class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-sm">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-semibold text-black dark:text-fg">
                                        {{ $node['server_name'] ?? $node['hostname'] }}
                                    </span>
                                    <span class="block text-[11px] text-neutral-500 dark:text-fg-faint">
                                        {{ $node['address'] }} · {{ $node['hostname'] }} ·
                                        {{ __(':cpus CPU', ['cpus' => $node['cpus']]) }} ·
                                        {{ __(':memory GB memory', ['memory' => round($node['memory_bytes'] / 1073741824, 1)]) }}
                                    </span>
                                </span>
                                <x-status-badge :status="$node['role'] === 'manager' ? __('Manager + worker') : __('Worker')"
                                    :type="$node['role'] === 'manager' ? 'success' : 'neutral'" />
                                <x-status-badge :status="$node['state'] === 'ready' ? __('Ready') : __($node['state'])"
                                    :type="$node['state'] === 'ready' ? 'success' : 'warning'" />
                                @if ($node['availability'] !== 'active')
                                    <x-status-badge :status="__($node['availability'])" type="warning" />
                                @endif
                            </div>
                        @empty
                            @unless ($nodesError)
                                <p class="px-4 py-3 text-sm text-neutral-500 dark:text-fg-faint" wire:loading.remove
                                    wire:target="loadNodes">{{ __('Loading nodes...') }}</p>
                            @endunless
                        @endforelse
                    </div>
                </x-application.settings-section>

                <x-application.settings-section id="server-swarm-workers-section" :title="__('Worker nodes')"
                    :helper="__('Add servers of this team as worker nodes. They must be validated and have no resources of their own.')">
                    <x-callout type="info" :title="__('Open these ports between the nodes')">
                        {{ implode(', ', $requiredPorts) }}
                    </x-callout>

                    @can('update', $server)
                        <div class="mt-4 flex flex-wrap items-end gap-3">
                            <div class="min-w-64 flex-1">
                                <x-forms.listbox id="workerServerUuid" :label="__('Server')"
                                    :placeholder="__('Select a server')"
                                    :emptyText="__('No server can join. Add and validate a server without resources first.')"
                                    :options="$workerCandidates
                                        ->map(fn ($candidate) => ['value' => $candidate->uuid, 'label' => $candidate->name.' ('.$candidate->ip.')'])
                                        ->values()
                                        ->all()" />
                            </div>
                            <x-forms.button wire:click="addWorker" :disabled="$workerCandidates->isEmpty()">
                                {{ __('Add worker node') }}
                            </x-forms.button>
                        </div>
                    @endcan

                    <div class="mt-4 divide-y divide-neutral-200 dark:divide-white/[0.07]">
                        @foreach ($workers as $worker)
                            <div wire:key="swarm-worker-{{ $worker->uuid }}" class="flex items-center gap-4 py-3 text-sm">
                                <span class="min-w-0 flex-1">
                                    <a class="block truncate font-semibold text-black underline dark:text-fg"
                                        href="{{ route('server.show', ['server_uuid' => $worker->uuid]) }}"
                                        wire:navigate>{{ $worker->name }}</a>
                                    <span class="block text-[11px] text-neutral-500 dark:text-fg-faint">{{ $worker->ip }}</span>
                                </span>
                                @can('update', $server)
                                    <x-modal-confirmation :title="__('Remove :server from the cluster?', ['server' => $worker->name])"
                                        :buttonTitle="__('Remove')" isErrorButton
                                        submitAction="removeWorker('{{ $worker->uuid }}')" :actions="[
                                            __('Running tasks on this node move to the other nodes.'),
                                            __('The server leaves the Swarm and becomes a regular server again.'),
                                        ]"
                                        confirmationText="{{ $worker->name }}"
                                        :confirmationLabel="__('Please confirm by entering the Server Name below')"
                                        :shortConfirmationLabel="__('Server Name')" />
                                @endcan
                            </div>
                        @endforeach
                    </div>
                </x-application.settings-section>

                <x-application.settings-section id="server-swarm-registry-section" :title="__('Cluster registry')"
                    :helper="__('Images built on the manager are pushed here so every node can pull them. Applications with their own registry image keep using it.')">
                    <p class="text-sm leading-6 text-neutral-600 dark:text-fg-dim">
                        {{ __('Every node reaches the registry at :address. It requires a password that only the manager knows.', ['address' => $registryAddress]) }}
                    </p>
                    @can('update', $server)
                        <div class="mt-4">
                            <x-forms.button wire:click="repairRegistry">{{ __('Set up registry again') }}</x-forms.button>
                        </div>
                    @endcan
                </x-application.settings-section>

                @can('update', $server)
                    <x-application.settings-section id="server-swarm-dissolve-section" :title="__('Remove cluster')"
                        :helper="__('Possible after every worker node and every resource in the cluster is removed.')">
                        <x-modal-confirmation :title="__('Remove the Swarm cluster?')" :buttonTitle="__('Remove cluster')"
                            isErrorButton submitAction="dissolve" :actions="[
                                __('The proxy and the cluster registry stop and the registry images are no longer used.'),
                                __('This server leaves Swarm mode and runs the proxy as a regular container again.'),
                            ]"
                            confirmationText="{{ $server->name }}"
                            :confirmationLabel="__('Please confirm by entering the Server Name below')"
                            :shortConfirmationLabel="__('Server Name')" />
                    </x-application.settings-section>
                @endcan
            @else
                <x-application.settings-section id="server-swarm-section" :title="__('Docker Swarm')"
                    :helper="__('Turn this server into the manager of a Swarm cluster. It keeps running workloads, and other servers can join it as worker nodes so applications run on several servers and move when one fails.')">
                    @if (count($managerBlockers) > 0)
                        <x-callout type="warning" :title="__('This server cannot start a cluster')">
                            <ul class="list-disc ps-5">
                                @foreach ($managerBlockers as $blocker)
                                    <li>{{ $blocker }}</li>
                                @endforeach
                            </ul>
                        </x-callout>
                    @else
                        <x-callout type="info" :title="__('What changes')">
                            <ul class="list-disc ps-5">
                                <li>{{ __('Docker on this server switches to Swarm mode and a private registry starts on it.') }}</li>
                                <li>{{ __('The proxy restarts as a Swarm stack. New applications use the Swarm destination.') }}</li>
                                <li>{{ __('Databases and services cannot run on a Swarm server. Keep them on regular servers.') }}</li>
                                <li>{{ __('Open these ports between the nodes:') }} {{ implode(', ', $requiredPorts) }}</li>
                            </ul>
                        </x-callout>

                        @can('update', $server)
                            <div class="mt-4 flex flex-wrap items-end gap-3">
                                <div class="min-w-64 flex-1">
                                    <x-forms.input id="advertiseAddress" :label="__('Advertise address')" required
                                        :helper="__('IP address the other nodes use to reach this server. Use a private network address when the servers share one.')" />
                                </div>
                                <x-forms.button wire:click="initialize">{{ __('Create Swarm cluster') }}</x-forms.button>
                            </div>
                        @endcan
                    @endif
                </x-application.settings-section>
            @endif
        </div>
    </div>
</div>
