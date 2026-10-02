<?php
$tabButtonBase = 'h-7 rounded-md px-2.5 text-[12px] font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-40';
$tabButtonActive = 'bg-white text-black shadow-sm ring-1 ring-neutral-200 dark:bg-white/[0.09] dark:text-fg dark:ring-white/[0.08]';
$tabButtonInactive = 'text-neutral-500 hover:text-black dark:text-fg-faint dark:hover:text-fg';

$dimensionLabels = [
    'referer' => __('Referrers'),
    'browser' => __('Browsers'),
    'os' => __('Operating systems'),
];
$dimensionHelpers = [
    'referer' => __('Top referrers by request count for the selected range.'),
    'browser' => __('Top browsers by request count for the selected range.'),
    'os' => __('Top operating systems by request count for the selected range.'),
];
// Shared by the application and service analytics tabs. A resource with several Sentinel
// keys (compose services, previews) is merged, so latency and uniques become approximate.
$approxBadge = fn (string $tooltip) => '<span title="'.e($tooltip).'" class="ml-1.5 inline-flex items-center rounded-full bg-amber-100 px-1.5 py-0.5 text-[9px] font-medium tracking-wide text-amber-700 uppercase dark:bg-amber-500/10 dark:text-amber-400">'.e(__('~ approximate')).'</span>';
?>
<div class="flex flex-col gap-6">
    @if (! $enabled)
        <x-application.settings-section id="analytics-section" :title="__('Analytics')"
            :helper="__('Inspect traffic statistics reported by Sentinel.')">
            @if ($analyticsServerUuid)
                <x-slot:actions>
                    <a class="button" href="{{ route('server.analytics', ['server_uuid' => $analyticsServerUuid]) }}"
                        {{ wireNavigate() }}>
                        {{ __('Server analytics') }}
                        <x-external-link />
                    </a>
                </x-slot:actions>
            @endif
            <x-empty size="sm" :title="__('Traffic analytics is not enabled')"
                :description="__('Enable Sentinel traffic analytics for this server to start collecting request analytics.')"
                icon-name="network" />
        </x-application.settings-section>
    @elseif (! $overview)
        <x-application.settings-section id="analytics-section" :title="__('Analytics')"
            :helper="__('Inspect traffic statistics reported by Sentinel.')">
            <x-empty size="sm" :title="__('No analytics data yet')"
                :description="__('We could not load traffic analytics for the selected range. Try a different range or check back shortly.')"
                icon-name="network" />
        </x-application.settings-section>
    @else
        @if ($this->isLivePollable())
            <div wire:poll.60s="loadData" class="hidden"></div>
        @endif

        <x-application.settings-section id="analytics-range-section" :title="__('Analytics')"
            :helper="__('Inspect traffic statistics reported by Sentinel.')">
            <x-slot:actions>
                <div class="flex items-center gap-2">
                    @include('livewire.traffic._live-toggle')
                    <div class="inline-flex items-center gap-0.5 rounded-lg bg-neutral-100 p-1 dark:bg-white/[0.04]">
                        <button type="button" wire:click="setRange('24h')"
                            @class([$tabButtonBase, $range === '24h' ? $tabButtonActive : $tabButtonInactive])>
                            {{ __('24 hours') }}
                        </button>
                        <button type="button" wire:click="setRange('7d')"
                            @class([$tabButtonBase, $range === '7d' ? $tabButtonActive : $tabButtonInactive])>
                            {{ __('7 days') }}
                        </button>
                        <button type="button" wire:click="setRange('30d')"
                            @class([$tabButtonBase, $range === '30d' ? $tabButtonActive : $tabButtonInactive])>
                            {{ __('30 days') }}
                        </button>
                    </div>
                </div>
            </x-slot:actions>

            <div class="grid grid-cols-2 gap-px overflow-hidden rounded-lg bg-neutral-200 sm:grid-cols-3 lg:grid-cols-5 dark:bg-white/[0.07]">
                <div class="flex flex-col bg-[var(--coollabs-base)] px-4 py-3">
                    <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">{{ __('Requests') }}</span>
                    <span class="mt-1 text-xl font-semibold text-black tabular-nums dark:text-fg">{{ number_format($overview['requests'] ?? 0) }}</span>
                    <div class="mt-auto pt-3">
                        @include('livewire.traffic._sparkline', [
                            'id' => $chartId.'-spark-requests',
                            'initial' => $this->requestsSpark(),
                            'colorVar' => '--chart-status-3xx',
                            'event' => 'refreshChartData-'.$chartId.'-status',
                            'key' => 'requestsSpark',
                        ])
                    </div>
                </div>
                <div class="flex flex-col bg-[var(--coollabs-base)] px-4 py-3">
                    <span class="flex items-center text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">
                        {{ __('Unique visitors') }}
                        @if ($uniquesApproximate)
                            {!! $approxBadge(__('Summed across services; visitors of more than one service may be double-counted.')) !!}
                        @endif
                    </span>
                    <span class="mt-1 text-xl font-semibold text-black tabular-nums dark:text-fg">{{ number_format($overview['uniqueVisitors'] ?? 0) }}</span>
                    <div class="mt-auto pt-3">
                        @include('livewire.traffic._sparkline', [
                            'id' => $chartId.'-spark-visitors',
                            'initial' => $this->uniquesSpark(),
                            'colorVar' => '--chart-status-2xx',
                            'event' => 'refreshChartData-'.$chartId.'-status',
                            'key' => 'uniquesSpark',
                        ])
                    </div>
                </div>
                <div class="flex flex-col bg-[var(--coollabs-base)] px-4 py-3">
                    <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">{{ __('Bandwidth') }}</span>
                    <span class="mt-1 text-xl font-semibold text-black tabular-nums dark:text-fg">{{ formatBytes($this->bandwidthBytes()) }}</span>
                    <div class="mt-auto pt-3">
                        @include('livewire.traffic._sparkline', [
                            'id' => $chartId.'-spark-bandwidth',
                            'initial' => $this->bandwidthSpark(),
                            'colorVar' => '--chart-spark-bandwidth',
                            'event' => 'refreshChartData-'.$chartId.'-status',
                            'key' => 'bandwidthSpark',
                        ])
                    </div>
                </div>
                <div class="flex flex-col bg-[var(--coollabs-base)] px-4 py-3">
                    <span class="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">{{ __('Error rate') }}</span>
                    <span class="mt-1 text-xl font-semibold text-black tabular-nums dark:text-fg">{{ $this->errorRate() }}%</span>
                    <div class="mt-auto pt-3">
                        @include('livewire.traffic._sparkline', [
                            'id' => $chartId.'-spark-errors',
                            'initial' => $this->errorsSpark(),
                            'colorVar' => '--chart-status-5xx',
                            'event' => 'refreshChartData-'.$chartId.'-status',
                            'key' => 'errorsSpark',
                        ])
                    </div>
                </div>
                <div class="col-span-2 flex flex-col bg-[var(--coollabs-base)] px-4 py-3 sm:col-span-1">
                    <span class="flex items-center text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">
                        {{ __('p95 latency') }}
                        @if ($latencyApproximate)
                            {!! $approxBadge(__('Highest p95 latency across services; not a true merged percentile.')) !!}
                        @endif
                    </span>
                    <span class="mt-1 text-xl font-semibold text-black tabular-nums dark:text-fg">{{ number_format($overview['latencyP95'] ?? 0, 1) }} ms</span>
                    <div class="mt-auto pt-3">
                        @include('livewire.traffic._sparkline', [
                            'id' => $chartId.'-spark-latency',
                            'initial' => $this->latencySpark(),
                            'colorVar' => '--chart-status-4xx',
                            'event' => 'refreshChartData-'.$chartId.'-status',
                            'key' => 'latencySpark',
                        ])
                    </div>
                </div>
            </div>
        </x-application.settings-section>

        <x-application.settings-section id="analytics-requests-section" :title="__('Requests')" flush
            :helper="__('Total request volume over time for the selected range.')">
            @include('livewire.traffic._requests-chart')
        </x-application.settings-section>

        <x-application.settings-section id="analytics-status-codes-section" :title="__('Status codes')"
            :helper="__('Share of responses by HTTP status class for the selected range.')">
            @include('livewire.traffic._status-codes')
        </x-application.settings-section>

        <x-application.settings-section id="analytics-paths-section" :title="__('Top paths')"
            :helper="__('Most requested paths for the selected range.')" flush>
            @include('livewire.traffic._paths-list', ['paths' => $topPaths])
        </x-application.settings-section>

        <x-application.settings-section id="analytics-country-section" :title="__('Countries')"
            :helper="__('Request volume by visitor country for the selected range.')" flush>
            @include('livewire.traffic._geo', [
                'countries' => data_get($breakdowns, 'country', []),
                'attribution' => $attribution,
            ])
        </x-application.settings-section>

        {{-- Requests by device type (donut) + HTTP versions / cache / status. --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <x-application.settings-section id="analytics-device-section" :title="__('Requests by device type')"
                :helper="__('Share of requests by client device class for the selected range.')">
                @php $deviceChart = $this->deviceChartData(); @endphp
                @include('livewire.traffic._device-chart', [
                    'labels' => $deviceChart['labels'],
                    'series' => $deviceChart['series'],
                ])
            </x-application.settings-section>

            @include('livewire.traffic._breakdown-section', [
                'dimension' => 'protocol',
                'label' => __('Top HTTP versions'),
                'rows' => data_get($breakdowns, 'protocol', []),
                'helper' => __('Request volume by negotiated HTTP protocol version.'),
            ])
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            @include('livewire.traffic._breakdown-section', [
                'dimension' => 'cache',
                'label' => __('Top cache statuses'),
                'rows' => data_get($breakdowns, 'cache', []),
                'helper' => __('Reverse-proxy cache outcome (hit, miss, bypass, …) by request count.'),
            ])
            @include('livewire.traffic._breakdown-section', [
                'dimension' => 'status',
                'label' => __('Top status codes'),
                'rows' => data_get($breakdowns, 'status', []),
                'helper' => __('Most frequent HTTP response status codes for the selected range.'),
            ])
        </div>

        {{-- Referrers / browsers / OS / AI agents / IPs, two per row. --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            @foreach ($dimensionLabels as $dimension => $label)
                @include('livewire.traffic._breakdown-section', [
                    'dimension' => $dimension,
                    'label' => $label,
                    'rows' => data_get($breakdowns, $dimension, []),
                    'helper' => $dimensionHelpers[$dimension],
                ])
            @endforeach
            @include('livewire.traffic._breakdown-section', [
                'dimension' => 'agent',
                'label' => __('AI agents & bots'),
                'rows' => data_get($breakdowns, 'agent', []),
                'helper' => __('Bot and AI-crawler traffic (GPTBot, ClaudeBot, Googlebot, …) by request count.'),
            ])
            @include('livewire.traffic._breakdown-section', [
                'dimension' => 'ip',
                'label' => __('Top IPs'),
                'rows' => data_get($breakdowns, 'ip', []),
                'helper' => __('Busiest client IPs (real visitor IP, resolved behind Cloudflare / reverse proxies).'),
            ])
        </div>

        {{-- User agents (full width — raw UA strings are long). --}}
        @include('livewire.traffic._breakdown-section', [
            'dimension' => 'useragent',
            'label' => __('Top user agents'),
            'rows' => data_get($breakdowns, 'useragent', []),
            'helper' => __('Most frequent raw User-Agent strings for the selected range.'),
        ])
    @endif
</div>
