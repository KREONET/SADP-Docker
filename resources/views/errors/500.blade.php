@extends('layouts.base')

@section('body')
    @php
        $errorMessage = \App\Support\ErrorExposure::make()->userMessage($exception);
    @endphp
    <body class="error-page-body text-black dark:text-inherit">
        <x-toast />
        <x-error-page
            code="500"
            :title="__('Wait, this is not cool...')"
            :description="__('There has been an error with the following error message:')"
            tone="danger">
            @if (filled($errorMessage))
                <div class="error-message">
                    {!! Purify::clean($errorMessage) !!}
                </div>
            @endif
        </x-error-page>
    </body>
@endsection
