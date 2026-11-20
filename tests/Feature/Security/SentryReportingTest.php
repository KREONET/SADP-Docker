<?php

use App\Models\InstanceSettings;
use App\Models\User;
use App\Support\SentryEventFilter;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Sentry\UserDataBag;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->originalSentryHub = SentrySdk::getCurrentHub();
});

afterEach(function () {
    SentrySdk::setCurrentHub($this->originalSentryHub);
});

function setErrorReporting(bool $enabled, bool $doNotTrack = true): void
{
    InstanceSettings::query()->whereKey(0)->update([
        'is_error_reporting_enabled' => $enabled,
        'do_not_track' => $doNotTrack,
    ]);
    Once::flush();
}

it('does not report to sentry when error reporting is disabled, even if do_not_track is off', function () {
    setErrorReporting(enabled: false, doNotTrack: false);

    $hub = Mockery::mock(HubInterface::class);
    $hub->shouldNotReceive('captureException');
    $hub->shouldNotReceive('configureScope');
    SentrySdk::setCurrentHub($hub);

    app(ExceptionHandler::class)->report(new LogicException('boom'));
});

it('reports to sentry without user or admin emails when error reporting is enabled', function () {
    setErrorReporting(enabled: true);
    $this->actingAs(User::factory()->create(['email' => 'member@example.com']));

    $hub = Mockery::mock(HubInterface::class);
    $hub->shouldReceive('captureException')->once();
    $hub->shouldNotReceive('configureScope');
    SentrySdk::setCurrentHub($hub);

    app(ExceptionHandler::class)->report(new LogicException('boom'));
});

it('drops events in before_send unless error reporting is enabled', function () {
    setErrorReporting(enabled: false, doNotTrack: false);

    expect(SentryEventFilter::beforeSend(Event::createEvent()))->toBeNull()
        ->and(SentryEventFilter::beforeSendTransaction(Event::createTransaction()))->toBeNull();
});

it('strips user data in before_send when error reporting is enabled', function () {
    setErrorReporting(enabled: true);

    $event = Event::createEvent();
    $event->setUser(UserDataBag::createFromArray(['email' => 'admin@example.com', 'ip_address' => '10.0.0.1']));

    $filtered = SentryEventFilter::beforeSend($event);

    expect($filtered)->toBe($event)
        ->and($filtered->getUser())->toBeNull();
});

it('denies reporting when the settings cannot be read', function () {
    InstanceSettings::query()->whereKey(0)->delete();
    Once::flush();

    expect(SentryEventFilter::reportingEnabled())->toBeFalse();
});

it('never sends personal data or sql bindings to sentry', function () {
    expect(config('sentry.send_default_pii'))->toBeFalse()
        ->and(config('sentry.breadcrumbs.sql_bindings'))->toBeFalse()
        ->and(config('sentry.before_send'))->toBe([SentryEventFilter::class, 'beforeSend'])
        ->and(config('sentry.before_send_transaction'))->toBe([SentryEventFilter::class, 'beforeSendTransaction'])
        ->and(is_callable(config('sentry.before_send')))->toBeTrue();
});
