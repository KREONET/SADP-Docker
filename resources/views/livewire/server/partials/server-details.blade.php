@php($meta = $server->server_metadata)
<dl
    class="mt-4 grid gap-x-6 gap-y-5 border-t border-neutral-200 pt-4 sm:grid-cols-2 lg:grid-cols-3 dark:border-white/[0.08]">
    @foreach ([
        __('Operating system') => $meta['os'] ?? __('N/A'),
        __('Architecture') => $meta['arch'] ?? __('N/A'),
        __('Kernel') => $meta['kernel'] ?? __('N/A'),
        __('CPU cores') => $meta['cpus'] ?? __('N/A'),
        __('Memory') => isset($meta['memory_bytes']) ? round($meta['memory_bytes'] / 1073741824, 1) . ' GB' : __('N/A'),
        __('Docker version') => $server->dockerVersion() ?? __('N/A'),
        __('Compose version') => $server->composeVersion() ?? __('N/A'),
        __('Up since') => $meta['uptime_since'] ?? __('N/A'),
    ] as $detailLabel => $detailValue)
        <div>
            <dt class="text-xs font-medium text-neutral-500 dark:text-fg-dim">
                {{ $detailLabel }}
            </dt>
            <dd class="mt-1 text-sm font-medium text-neutral-950 dark:text-fg">
                {{ $detailValue }}
            </dd>
        </div>
    @endforeach
</dl>
