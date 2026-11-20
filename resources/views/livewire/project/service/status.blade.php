<div wire:poll.10000ms="refreshStatus" class="flex items-center gap-1">
    @php($displayStatus = $selectedResource?->status ?? $service->status)
    <x-status-summary :status="$displayStatus" :title="$selectedResource ? __('Resource status') : __('Service status')"
        :container-name="$selectedResource ? __('Container') : __('Containers')" />
    @if ($selectedResource)
        <x-application.restart-limit-warning :application="$selectedResource" />
    @endif
</div>
