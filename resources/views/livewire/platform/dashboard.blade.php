<div>
    <x-slot:title>{{ __('Platform Admin') }} | {{ brand_name() }}</x-slot>

    <x-platform.layout>
        <div class="flex min-w-0 flex-col gap-6">
            <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    ['label' => __('Teams'), 'value' => $stats['teams'], 'href' => route('platform.groups')],
                    ['label' => __('Projects'), 'value' => $stats['projects'], 'href' => route('platform.groups')],
                    ['label' => __('Applications'), 'value' => $stats['applications'], 'href' => route('platform.applications')],
                    ['label' => __('Running'), 'value' => $stats['running'], 'href' => route('platform.applications')],
                    ['label' => __('Not running'), 'value' => $stats['stopped'], 'href' => route('platform.applications')],
                    ['label' => __('Services'), 'value' => $stats['services'], 'href' => null],
                    ['label' => __('Servers'), 'value' => $stats['servers'], 'href' => null],
                    ['label' => __('Blocked deployments (7d)'), 'value' => $stats['blocked_scans'], 'href' => route('platform.security', ['status' => 'blocked'])],
                ] as $card)
                    <a wire:key="platform-stat-{{ str($card['label'])->slug() }}" @if ($card['href']) href="{{ $card['href'] }}" {{ wireNavigate() }} @endif
                        class="rounded-[10px] border border-neutral-200 bg-white p-4 dark:border-white/[0.07] dark:bg-surface">
                        <div class="text-xs font-medium text-neutral-500 dark:text-fg-dim">{{ $card['label'] }}</div>
                        <div class="mt-2 text-2xl font-semibold tracking-tight text-black dark:text-fg">{{ $card['value'] }}</div>
                    </a>
                @endforeach
            </section>

            <x-application.settings-section :title="__('Guardrails')">
                <div class="grid gap-3 lg:grid-cols-2">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm">{{ __('SSO-only login') }}</span>
                        <x-status-badge :status="$guardrails['sso_only'] ? __('Enforced') : __('Off')" :type="$guardrails['sso_only'] ? 'success' : 'warning'" />
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm">{{ __('Source allow-list') }}</span>
                        <x-status-badge :status="$guardrails['allowlist'] ? __(':count git sources', ['count' => $guardrails['allowed_git_sources']->count()]) : __('Off')" :type="$guardrails['allowlist'] ? 'success' : 'warning'" />
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm">{{ __('Forgejo auto-deploy webhook') }}</span>
                        <x-status-badge :status="$guardrails['forgejo'] ? __('Configured') : __('Not configured')" :type="$guardrails['forgejo'] ? 'success' : 'warning'" />
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm">{{ __('Trivy vulnerability scan') }}</span>
                        <x-status-badge :status="$guardrails['trivy'] ? ($guardrails['trivy_blocking'] ? __('Blocking') : __('Report only')) : __('Off')" :type="$guardrails['trivy'] ? 'success' : 'warning'" />
                    </div>
                </div>
            </x-application.settings-section>

            <div class="grid min-w-0 gap-6 2xl:grid-cols-2">
                <x-application.settings-section :title="__('Recent deployments')" flush>
                    @forelse ($recentDeployments as $deployment)
                        <div wire:key="platform-deployment-{{ $deployment->deployment_uuid }}"
                            class="flex items-center justify-between gap-3 border-b border-neutral-200 px-4 py-2.5 last:border-b-0 dark:border-white/[0.06]">
                            <div class="min-w-0">
                                <div class="truncate text-[12px] font-semibold">{{ $deployment->application_name ?? __('Application #:id', ['id' => $deployment->application_id]) }}</div>
                                <div class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ str($deployment->commit)->limit(12, '') }} · {{ $deployment->created_at?->diffForHumans() }}</div>
                            </div>
                            <x-status-badge :status="$deployment->status" :type="match ($deployment->status) { 'finished' => 'success', 'failed' => 'error', 'cancelled-by-user' => 'neutral', default => 'warning' }" />
                        </div>
                    @empty
                        <x-empty size="sm" :title="__('No deployments yet')" />
                    @endforelse
                </x-application.settings-section>

                <x-application.settings-section :title="__('Recent vulnerability scans')" flush>
                    @forelse ($recentScans as $scan)
                        <div wire:key="platform-scan-{{ $scan->uuid }}"
                            class="flex items-center justify-between gap-3 border-b border-neutral-200 px-4 py-2.5 last:border-b-0 dark:border-white/[0.06]">
                            <div class="min-w-0">
                                <div class="truncate text-[12px] font-semibold">{{ $scan->application?->name ?? __('Deleted application') }}</div>
                                <div class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                    {{ $scan->branch }} · C {{ $scan->critical_count }} / H {{ $scan->high_count }} / M {{ $scan->medium_count }} · {{ $scan->created_at?->diffForHumans() }}
                                </div>
                            </div>
                            <x-status-badge :status="$scan->status" :type="match ($scan->status) { 'passed' => 'success', 'found' => 'warning', default => 'error' }" />
                        </div>
                    @empty
                        <x-empty size="sm" :title="__('No scans yet')" :description="__('Enable Trivy scanning under Security.')" />
                    @endforelse
                </x-application.settings-section>
            </div>
        </div>
    </x-platform.layout>
</div>
