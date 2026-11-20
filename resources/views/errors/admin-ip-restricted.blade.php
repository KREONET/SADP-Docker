@extends('layouts.base')

@section('body')
    <body class="error-page-body text-black dark:text-inherit">
        <x-toast />
        <x-error-page
            code="403"
            :title="__('Administrator access is restricted')"
            :description="$message ?? __('Administrator access is restricted to approved IP addresses.')"
            :show-go-back="false"
            :show-dashboard="! ($signedOut ?? false)"
            :show-contact="false"
            :primary-href="($signedOut ?? false) ? '/login' : null"
            :primary-label="($signedOut ?? false) ? __('Back to login') : null">
            @if ($signedOut ?? false)
                <p>{{ __('You were signed out because platform administrators and auditors may only use this instance from the IP addresses an administrator approved. Sign in again from an approved network, or ask an administrator to add your address.') }}</p>
            @else
                <p>{{ __('This area can only be opened from the IP addresses an administrator approved.') }}</p>
            @endif
        </x-error-page>
    </body>
@endsection
