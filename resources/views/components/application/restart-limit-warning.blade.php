@props(['application'])

@if (method_exists($application, 'stoppedAfterRestartLimit') && $application->stoppedAfterRestartLimit())
    @php($restartLimit = method_exists($application, 'restartLimitMaximum') ? $application->restartLimitMaximum() : ($application->max_restart_count ?? 0))
    @php($displayRestartCount = max($application->restart_count ?? 0, $restartLimit))
    <x-status-badge
        :status="__('Restart limit reached')"
        type="warning"
        :title="__('Container has crashed and Coolify stopped it after :count restart attempts.', ['count' => $displayRestartCount])" />
@endif
