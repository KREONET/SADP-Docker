@props(['message' => 'This feature uses the Coolify API.'])

@unless (instanceSettings()->is_api_enabled)
    <x-callout type="warning" :title="__('API access is turned off')" {{ $attributes }}>
        {{ $message }}
        @if (isInstanceAdmin())
            <div class="mt-2">
                <a href="{{ route('settings.advanced') }}" class="button" {{ wireNavigate() }}>{{ __('Enable API access') }}</a>
            </div>
        @else
            {{ __('Ask an instance admin to enable API access.') }}
        @endif
    </x-callout>
@endunless
