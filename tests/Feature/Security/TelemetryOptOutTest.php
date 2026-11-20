<?php

use App\Console\Commands\Init;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config(['constants.coolify.self_hosted' => true]);
});

function freshTelemetrySettings(): InstanceSettings
{
    Once::flush();

    return instanceSettings();
}

it('opts out of telemetry by default', function () {
    expect(freshTelemetrySettings()->do_not_track)->toBeTrue()
        ->and(freshTelemetrySettings()->is_error_reporting_enabled)->toBeFalse()
        ->and(freshTelemetrySettings()->is_changelog_enabled)->toBeFalse()
        ->and(freshTelemetrySettings()->is_feedback_forwarding_enabled)->toBeFalse();
});

it('does not send the alive ping when do_not_track is enabled', function () {
    Http::fake();

    $sent = app(Init::class)->sendAliveSignalIfAllowed(freshTelemetrySettings());

    expect($sent)->toBeFalse();
    Http::assertNothingSent();
});

it('does not send the alive ping when settings are missing', function () {
    Http::fake();

    expect(app(Init::class)->sendAliveSignalIfAllowed(null))->toBeFalse();
    Http::assertNothingSent();
});

it('does not send the alive ping on cloud', function () {
    config(['constants.coolify.self_hosted' => false]);
    InstanceSettings::query()->whereKey(0)->update(['do_not_track' => false]);
    Http::fake();

    expect(app(Init::class)->sendAliveSignalIfAllowed(freshTelemetrySettings()))->toBeFalse();
    Http::assertNothingSent();
});

it('sends only the instance id and version when do_not_track is disabled', function () {
    InstanceSettings::query()->whereKey(0)->update(['do_not_track' => false]);
    config(['app.id' => 'instance-123', 'constants.coolify.version' => '4.0.0']);
    Http::fake(['undead.coolify.io/*' => Http::response('ok')]);

    expect(app(Init::class)->sendAliveSignalIfAllowed(freshTelemetrySettings()))->toBeTrue();

    Http::assertSentCount(1);
    Http::assertSent(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'https://undead.coolify.io/v4/alive')
            && $query === ['appId' => 'instance-123', 'version' => '4.0.0']
            && ! $request->hasHeader('Authorization');
    });
});
