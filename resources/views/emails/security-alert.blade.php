<x-emails.layout>
{{ __('Coolify detected a security-relevant event: :title', ['title' => $title]) }}

- {{ __('Type') }}: {{ $type }}
- {{ __('Time') }}: {{ $occurredAt }}
@foreach ($details as $key => $value)
- {{ \Illuminate\Support\Str::headline($key) }}: {{ $value }}
@endforeach

{{ __('Review the audit log for details') }}: [{{ __('Audit log') }}]({{ $auditLogUrl }})
</x-emails.layout>
