<?php

test('layout popups compile without Blade directive mistakes and without promotional links', function () {
    $view = file_get_contents(resource_path('views/livewire/layout-popups.blade.php'));
    $compiledView = Blade::compileString($view);

    expect($compiledView)
        ->toContain('@click="bannerVisible=false;disableRealtime()"')
        ->not->toContain('$__env->yieldSection()');

    expect(strtolower($view))
        ->not->toContain('sponsor')
        ->not->toContain('discord')
        ->not->toContain('opencollective')
        ->not->toContain('donate.stripe.com');
});

test('the user menu and instance settings offer no sponsorship or community chat links', function () {
    $files = [
        'resources/views/components/top-user-menu.blade.php',
        'resources/views/livewire/settings/advanced.blade.php',
        'app/Livewire/Settings/Advanced.php',
        'app/Livewire/Help.php',
        'app/Actions/Proxy/CheckProxy.php',
    ];

    foreach ($files as $file) {
        expect(strtolower(file_get_contents(base_path($file))))
            ->not->toContain('sponsor', "{$file} still mentions sponsorship")
            ->not->toContain('coolify.io/discord', "{$file} still links to Discord")
            ->not->toContain('on discord', "{$file} still points to Discord");
    }
});
