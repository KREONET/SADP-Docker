@extends('layouts.base')

@section('body')
    @php
        $errorMessage = \App\Support\ErrorExposure::make()->userMessage($exception);
    @endphp
    <body class="error-page-body text-black dark:text-inherit">
        <x-toast />
        <x-error-page
            code="400"
            :title="__('Bad request')"
            :description="$errorMessage ?: __('The request could not be understood by the server due to malformed syntax.')" />
    </body>
@endsection
