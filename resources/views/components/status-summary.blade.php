@props([
    'status',
    'title' => null,
    'containerName' => null,
    'align' => 'left',
    'healthcheckUrl' => null,
])

@php
    $rawStatus = str((string) $status)->lower()->trim()->value();
    $statusParts = explode(':', $rawStatus);
    $containerStatus = $statusParts[0] ?? 'unknown';
    $healthStatus = $statusParts[1] ?? null;
    $monitoringExcluded = in_array('excluded', $statusParts, true);

    if ($healthStatus === null && preg_match('/^([^()]+)\(([^)]+)\)$/', $rawStatus, $matches)) {
        $containerStatus = trim($matches[1]);
        $healthStatus = trim($matches[2]);
    }

    $title ??= __('Application status');
    $containerName ??= __('Container');

    $containerLabel = match ($containerStatus ?: 'unknown') {
        'running' => __('Running'),
        'exited' => __('Exited'),
        'stopped' => __('Stopped'),
        'starting' => __('Starting'),
        'restarting' => __('Restarting'),
        'degraded' => __('Degraded'),
        'paused' => __('Paused'),
        'created' => __('Created'),
        'dead' => __('Dead'),
        'removing' => __('Removing'),
        'unknown' => __('Unknown'),
        default => str($containerStatus)->headline()->value(),
    };
    $isHealthNotConfigured = ! $monitoringExcluded && ! in_array($healthStatus, ['healthy', 'unhealthy', 'starting'], true);
    $healthLabel = match (true) {
        $monitoringExcluded => __('Monitoring disabled'),
        $healthStatus === 'healthy' => __('Healthy'),
        $healthStatus === 'unhealthy' => __('Unhealthy'),
        $healthStatus === 'starting' => __('Starting'),
        default => __('Not configured'),
    };

    $containerType = match (true) {
        str($containerStatus)->startsWith('running') => 'success',
        str($containerStatus)->startsWith(['starting', 'restarting', 'degraded']) => 'warning',
        default => 'error',
    };

    $healthType = match (true) {
        $monitoringExcluded => 'warning',
        $healthStatus === 'healthy' => 'success',
        $healthStatus === 'unhealthy' => 'error',
        default => 'warning',
    };

    [$summaryLabel, $summaryType] = match (true) {
        $containerType === 'error' => [$containerLabel, 'error'],
        str($containerStatus)->startsWith('degraded') => [__('Degraded'), 'warning'],
        $healthType === 'error' => [__('Degraded'), 'error'],
        $containerType === 'warning' => [$containerLabel, 'warning'],
        $monitoringExcluded => [__(':status (monitoring disabled)', ['status' => $containerLabel]), 'warning'],
        $healthStatus === 'starting' => [__(':status (healthcheck starting)', ['status' => $containerLabel]), 'warning'],
        $healthType === 'warning' => [__(':status (no healthcheck)', ['status' => $containerLabel]), 'warning'],
        default => [$containerLabel, 'success'],
    };
@endphp

<div class="relative shrink-0" x-data="{ open: false }" @click.outside="open = false"
    @keydown.escape.window="open = false">
    <x-status-badge as="button" dynamic @click="open = !open" x-bind:aria-expanded="open" aria-haspopup="menu"
        class="cursor-pointer">
        <span @class([
            'size-1.5 shrink-0 rounded-full',
            'bg-success' => $summaryType === 'success',
            'bg-warning' => $summaryType === 'warning',
            'bg-error' => $summaryType === 'error',
        ])></span>
        <span>{{ $summaryLabel }}</span>
        <span class="inline-flex transition-transform" :class="open && 'rotate-180'">
            <x-reicon name="chevron-down" class="size-3 opacity-55" />
        </span>
    </x-status-badge>

    <div x-cloak x-show="open" x-transition.origin.top.{{ $align === 'right' ? 'right' : 'left' }}
        @class([
            'listbox-panel top-8! z-[90]! w-[min(16rem,calc(100vw-1.5rem))]! min-w-0! sm:w-64! sm:min-w-64!',
            'right-auto! left-0!' => $align !== 'right',
            'left-auto! right-0!' => $align === 'right',
        ]) role="menu">
        <div class="px-3 py-2 text-[11px] font-medium text-neutral-400 dark:text-fg-faint">{{ $title }}</div>
        <div class="listbox-option cursor-default! gap-2.5!">
            <span @class([
                'size-1.5 shrink-0 rounded-full',
                'bg-success' => $containerType === 'success',
                'bg-warning' => $containerType === 'warning',
                'bg-error' => $containerType === 'error',
            ])></span>
            <span class="flex-1">{{ $containerName }}</span>
            <span>{{ $containerLabel }}</span>
        </div>
        @php
            $healthDot = [
                'size-1.5 shrink-0 rounded-full',
                'bg-success' => $healthType === 'success',
                'bg-warning' => $healthType === 'warning',
                'bg-error' => $healthType === 'error',
            ];
            $healthHelper = __('No healthcheck is configured, so Coolify can only report the container state. Traffic can still be routed to the container, but Coolify cannot verify that the application inside it is ready to receive requests.');
        @endphp
        @if ($healthcheckUrl)
            <div class="listbox-option gap-0! p-0!">
                <a href="{{ $healthcheckUrl }}" {{ wireNavigate() }} role="menuitem"
                    class="flex min-h-8 flex-1 items-center gap-2.5 px-2 py-1.5">
                    <span @class($healthDot)></span>
                    <span class="flex-1">{{ __('Healthcheck') }}</span>
                    <span>{{ $healthLabel }}</span>
                </a>
                @if ($isHealthNotConfigured)
                    <span class="inline-flex pr-2">
                        <x-helper :label="__('About unconfigured healthchecks')" :helper="$healthHelper" />
                    </span>
                @endif
            </div>
        @else
            <div class="listbox-option cursor-default! gap-2.5!">
                <span @class($healthDot)></span>
                <span class="flex-1">{{ __('Healthcheck') }}</span>
                <span class="inline-flex items-center gap-1.5">
                    {{ $healthLabel }}
                    @if ($isHealthNotConfigured)
                        <x-helper :label="__('About unconfigured healthchecks')" :helper="$healthHelper" />
                    @endif
                </span>
            </div>
        @endif
    </div>
</div>
