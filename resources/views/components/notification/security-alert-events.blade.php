@props([
    'settings',
    'channel',
    'threaded' => false,
])

@php
    $property = 'securityAlert' . Str::studly($channel) . 'Notifications';
    $enabled = (bool) data_get($settings, 'security_alert_' . $channel . '_notifications');
    $threadModel = Str::camel(Str::studly($channel) . 'NotificationsSecurityAlertThreadId');
@endphp

<x-application.settings-section :title="__('Security alerts')"
    :description="__('Instance-wide security events, such as repeated failed sign-ins, locked accounts, changed security settings, or a changed SSH host key. Only the root team receives them.')">
    <div class="grid gap-4 lg:grid-cols-2">
        <div class="min-w-0">
            <x-notification.event-multiselect :settings="$settings" :id="$channel . '-security-events'"
                :label="__('Security')" :events="[
                    ['property' => $property, 'label' => __('Security alerts'), 'enabled' => $enabled],
                ]" />
        </div>
        @if ($threaded && $enabled)
            <div class="min-w-0">
                <x-forms.input wire:key="{{ $channel }}-thread-securityAlert" canGate="update" :canResource="$settings"
                    type="password" :id="$threadModel" :label="__('Security alerts topic ID')"
                    :placeholder="__('Optional')" />
            </div>
        @endif
    </div>
</x-application.settings-section>
