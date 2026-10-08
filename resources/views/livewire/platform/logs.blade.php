<div>
    <x-slot:title>{{ $canManageLogs ? __('Logs').' | '.__('Platform Admin') : __('Audit logs') }} | {{ brand_name() }}</x-slot>

    {{-- Auditors get a read-only page without the platform admin menu or any setting forms. --}}
    <x-dynamic-component :component="$canManageLogs ? 'platform.layout' : 'platform.auditor-layout'">
        <div class="flex min-w-0 flex-col gap-6">
            @if ($canManageLogs)
                <form wire:submit="saveCollection">
                    <x-application.settings-section :title="__('Log collection')"
                        :description="__('User activity (audit events) is always collected. The master switch controls Coolify system logs (warning and above), application container logs and Loki shipping for every team.')">
                        <div class="grid gap-4">
                            <x-forms.checkbox id="isLogCollectionEnabled" :label="__('Enable log collection (master switch)')" />
                            <div class="grid gap-1 lg:grid-cols-3">
                                <x-forms.checkbox id="isAuditLogCollectionEnabled" :label="__('User activity (audit events)')" disabled
                                    :helper="__('Logins, failed logins and resource changes are a security record, so they are always collected and cannot be turned off. They are kept for :days days.', ['days' => $auditRetentionDays])" />
                                <x-forms.checkbox id="isSystemLogCollectionEnabled" :label="__('Coolify system logs')"
                                    :helper="__('Warnings and errors written by Coolify itself (web, queue workers, scheduler).')" />
                                <x-forms.checkbox id="isContainerLogCollectionEnabled" :label="__('Application container logs')"
                                    :helper="__('Every 5 minutes, new lines (up to 500 per container) of running application and service containers are read over SSH. Swarm servers are skipped.')" />
                            </div>
                            <div class="grid gap-4 lg:grid-cols-2">
                                <x-forms.input id="logRetentionDays" type="number" :min="$minLogRetentionDays" :max="$maxLogRetentionDays" required
                                    :label="__('Keep system and container logs (days)')"
                                    :helper="__('Older collected lines are deleted daily. The security policy requires at least :days days. Loki keeps its own retention.', ['days' => $minLogRetentionDays])" />
                            </div>
                            <div class="flex justify-end">
                                <x-forms.button type="submit" isHighlighted>{{ __('Save collection settings') }}</x-forms.button>
                            </div>
                        </div>
                    </x-application.settings-section>
                </form>

                <form wire:submit="saveLoki">
                    <x-application.settings-section :title="__('Loki / Grafana')"
                        :description="__('Send collected logs to Grafana Loki with the push API. In Grafana, add Loki as a data source and query {service_name=`coolify`} | json in Explore.')">
                        <div class="grid gap-4">
                            <x-forms.checkbox id="isLokiShippingEnabled" :label="__('Ship logs to Loki')" />
                            <div class="grid gap-4 lg:grid-cols-2">
                                <x-forms.input id="lokiUrl" :label="__('Loki URL')" placeholder="https://loki.example.com"
                                    :helper="__('Base URL or full push URL. /loki/api/v1/push is added when missing.')" />
                                <x-forms.input id="lokiTenantId" :label="__('Tenant ID (X-Scope-OrgID)')"
                                    :helper="__('Only for multi-tenant Loki. Leave empty otherwise.')" />
                                <x-forms.input id="lokiUsername" :label="__('Basic auth username')"
                                    :helper="__('For Grafana Cloud, use the Loki user ID. Leave empty when Loki has no authentication.')" />
                                <x-forms.input id="lokiPassword" type="password" allowToPeak :label="__('Basic auth password or API token')"
                                    :helper="__('Leave empty to keep the stored value.')" />
                            </div>
                            <div class="flex flex-wrap items-center gap-2 text-[11px] text-neutral-600 dark:text-fg-dim">
                                @if ($lokiActive)
                                    <x-status-badge :status="__('Shipping')" type="success" />
                                @else
                                    <x-status-badge :status="__('Not shipping')" type="neutral" />
                                @endif
                                @if ($hasLokiPassword)
                                    <x-status-badge :status="__('Password stored')" type="success" />
                                    <x-forms.button type="button" wire:click="clearLokiPassword" isError>{{ __('Remove password') }}</x-forms.button>
                                @endif
                                @if ($lokiActive)
                                    <span>{{ __('Waiting to send: :count', ['count' => number_format($lokiPending)]) }}</span>
                                    @if ($lokiParked > 0)
                                        <span>· {{ __('Rejected by Loki, not retried: :count', ['count' => number_format($lokiParked)]) }}</span>
                                    @endif
                                @endif
                                @if ($lokiLastPushedAt)
                                    <span title="{{ $lokiLastPushedAt->toDayDateTimeString() }}">· {{ __('Last push :time', ['time' => $lokiLastPushedAt->diffForHumans()]) }}</span>
                                @endif
                            </div>
                            @if ($lokiLastError)
                                <x-callout type="warning" :title="__('Last Loki error')">{{ $lokiLastError }}</x-callout>
                            @endif
                            <div class="flex flex-wrap justify-end gap-2">
                                <x-forms.button type="button" wire:click="testLokiConnection">{{ __('Send test line') }}</x-forms.button>
                                <x-forms.button type="submit" isHighlighted>{{ __('Save Loki settings') }}</x-forms.button>
                            </div>
                        </div>
                    </x-application.settings-section>
                </form>
            @endif

            <x-application.settings-section :title="__('Log viewer')" flush>
                <x-slot:actions>
                    <div class="flex flex-wrap gap-2">
                        <x-forms.button type="button" wire:click="export('ndjson')">{{ __('Export NDJSON') }}</x-forms.button>
                        <x-forms.button type="button" wire:click="export('csv')">{{ __('Export CSV') }}</x-forms.button>
                    </div>
                </x-slot:actions>

                <div class="flex flex-col gap-2 border-b border-neutral-200 p-3 sm:flex-row sm:items-center dark:border-white/[0.08]">
                    <div class="relative min-w-0 flex-1 sm:max-w-sm">
                        <x-reicon name="search"
                            class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                        <input wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('Search logs') }}"
                            aria-label="{{ __('Search logs') }}"
                            class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-8! pl-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-accent! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint">
                    </div>
                    <div class="grid grid-cols-2 gap-2 sm:flex">
                        <select class="input sm:w-44" wire:model.live="source" aria-label="{{ __('Log source') }}">
                            <option value="audit">{{ __('User activity') }}</option>
                            <option value="system">{{ __('Coolify system') }}</option>
                            <option value="container">{{ __('Application containers') }}</option>
                        </select>
                        <select class="input sm:w-32" wire:model.live="level" aria-label="{{ __('Log level') }}">
                            <option value="all">{{ __('All levels') }}</option>
                            @foreach ($levelOptions as $levelOption)
                                <option wire:key="level-{{ $levelOption }}" value="{{ $levelOption }}">{{ __(Str::headline($levelOption)) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                @unless ($sourceActive)
                    <div class="border-b border-neutral-200 p-3 dark:border-white/[0.08]">
                        <x-callout type="info" :title="__('Collection is off for this source')">
                            {{ __('Existing entries are shown. Turn on the master switch and this source above to collect new entries.') }}
                        </x-callout>
                    </div>
                @endunless

                @if ($entries->isNotEmpty())
                    <div class="overflow-x-auto">
                        <div class="data-table transition-opacity" wire:loading.class="opacity-50 pointer-events-none"
                            wire:target="search,source,level,setPage,previousPage,nextPage">
                            <div class="grid min-w-[760px] grid-cols-[10rem_5rem_12rem_minmax(0,1fr)] gap-4 border-b border-neutral-200 px-4 py-2 text-[11px] font-medium uppercase tracking-wide text-neutral-500 dark:border-white/[0.07] dark:text-fg-faint">
                                <span>{{ __('Time') }}</span>
                                <span>{{ __('Level') }}</span>
                                <span>{{ $source === 'audit' ? __('Actor') : __('Resource') }}</span>
                                <span>{{ __('Message') }}</span>
                            </div>
                            @foreach ($entries as $entry)
                                @php
                                    $time = $entry instanceof \App\Models\AuditEvent ? $entry->created_at : $entry->logged_at;
                                @endphp
                                <div wire:key="log-{{ $source }}-{{ $entry->id }}"
                                    class="grid min-w-[760px] grid-cols-[10rem_5rem_12rem_minmax(0,1fr)] gap-4 border-b border-neutral-200 px-4 py-2.5 last:border-b-0 dark:border-white/[0.07]">
                                    <time datetime="{{ $time?->toIso8601String() }}" title="{{ $time?->toDayDateTimeString() }}"
                                        class="text-[11px] text-neutral-500 dark:text-fg-faint">
                                        {{ $time?->format('Y-m-d H:i:s') }}
                                    </time>
                                    <div class="flex flex-col items-start gap-1">
                                        <x-status-badge :status="$entry->level" :type="match ($entry->level) {
                                            'info', 'debug', 'notice' => 'neutral',
                                            'warning' => 'warning',
                                            default => 'error',
                                        }" />
                                        @if ($entry instanceof \App\Models\AuditEvent && $entry->result)
                                            <x-status-badge :status="__(Str::headline($entry->result))" :type="match ($entry->result) {
                                                'success' => 'success',
                                                'denied' => 'warning',
                                                default => 'error',
                                            }" />
                                        @endif
                                    </div>
                                    <div class="min-w-0 text-[12px]">
                                        @if ($entry instanceof \App\Models\AuditEvent)
                                            <div class="truncate font-medium text-black dark:text-fg">{{ $entry->actor_name ?: __(Str::headline($entry->actor_type)) }}</div>
                                            <div class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $entry->actor_email ?: $entry->ip_address }}</div>
                                        @else
                                            <div class="truncate font-medium text-black dark:text-fg">{{ $entry->resource_name ?: 'Coolify' }}</div>
                                            @if ($entry->container_name)
                                                <div class="truncate font-mono text-[11px] text-neutral-500 dark:text-fg-faint">{{ $entry->container_name }}</div>
                                            @endif
                                        @endif
                                    </div>
                                    <div class="min-w-0">
                                        @if ($entry instanceof \App\Models\AuditEvent)
                                            <div class="truncate text-[12px] font-medium text-black dark:text-fg">{{ $entry->description }}</div>
                                            <div class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $entry->event }}</div>
                                        @else
                                            <pre class="whitespace-pre-wrap break-all font-mono text-[11px] text-neutral-700 dark:text-fg-dim">{{ Str::limit($entry->message, 1000) }}</pre>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <x-table-pagination :from="$entries->firstItem() ?? 0" :to="$entries->lastItem() ?? 0"
                        :total="$entries->total()" :current-page="$entries->currentPage()"
                        :last-page="$entries->lastPage()" wire-target="setPage,previousPage,nextPage"
                        previous-action="previousPage" next-action="nextPage" />
                @else
                    <x-empty :title="__('No log entries')" :description="__('No entries match this source and filter yet.')" icon-name="file-content" size="sm" />
                @endif
            </x-application.settings-section>
        </div>
    </x-dynamic-component>
</div>
