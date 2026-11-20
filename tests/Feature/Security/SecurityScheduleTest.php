<?php

use App\Models\InstanceSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function scheduledSecurityEvent(string $needle): mixed
{
    return collect(app(Schedule::class)->events())->first(
        fn ($event) => str_contains((string) $event->command, $needle) || str_contains((string) $event->description, $needle)
    );
}

test('the audit chain is verified daily on one server', function () {
    $event = scheduledSecurityEvent('audit:verify');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 5 * * *')
        ->and($event->onOneServer)->toBeTrue();
});

test('the changelog is only pulled while the changelog is enabled', function () {
    $event = scheduledSecurityEvent('PullChangelog');
    expect($event)->not->toBeNull();

    InstanceSettings::query()->whereKey(0)->update(['is_changelog_enabled' => false]);
    Once::flush();
    expect($event->filtersPass(app()))->toBeFalse();

    InstanceSettings::query()->whereKey(0)->update(['is_changelog_enabled' => true]);
    Once::flush();
    expect($event->filtersPass(app()))->toBeTrue();
});
