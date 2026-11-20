<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('keeps the sidebar free of update badges even when an older instance flagged one', function (string $version) {
    config()->set('constants.coolify.version', $version);
    seedBrowserResourceStack(['instanceSettings' => ['new_version_available' => true]]);
    Cache::put('coolify:versions', ['coolify' => ['version' => '9.9.9']], 3600);

    $page = visit('/login')
        ->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->assertSee('Dashboard')
        ->assertNotPresent('header button[aria-label="Update available"]')
        ->assertNoJavaScriptErrors();

    $page->screenshot(filename: 'sidebar-without-update-badge');
})->with(['4.3.19', '4.0.0-beta.470']);
