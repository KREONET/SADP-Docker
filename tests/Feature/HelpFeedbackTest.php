<?php

use App\Livewire\Help;
use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_feedback_forwarding_enabled' => true]);
    $this->actingAs(User::factory()->create());
});

it('shows success when the cloud api accepts feedback without email configured', function () {
    Http::fake(['app.coolify.io/*' => Http::response(['message' => 'Feedback sent.'], 200)]);

    Livewire::test(Help::class)
        ->set('subject', 'Test subject')
        ->set('description', 'This is a detailed feedback description.')
        ->call('submit')
        ->assertDispatched('success')
        ->assertNotDispatched('error');

    Http::assertSent(fn ($request) => $request->url() === 'https://app.coolify.io/api/feedback');
});

it('shows an error when the cloud api rejects feedback without email configured', function (int $status) {
    Http::fake(['app.coolify.io/*' => Http::response([], $status)]);

    Livewire::test(Help::class)
        ->set('subject', 'Test subject')
        ->set('description', 'This is a detailed feedback description.')
        ->call('submit')
        ->assertDispatched('error')
        ->assertNotDispatched('success')
        ->assertSet('subject', 'Test subject');
})->with([422, 429, 500, 502]);

it('shows an error when the cloud api is unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    Livewire::test(Help::class)
        ->set('subject', 'Test subject')
        ->set('description', 'This is a detailed feedback description.')
        ->call('submit')
        ->assertDispatched('error')
        ->assertNotDispatched('success');
});

it('does not forward feedback when feedback forwarding is disabled', function () {
    InstanceSettings::query()->whereKey(0)->update(['is_feedback_forwarding_enabled' => false]);
    Once::flush();
    Http::fake();
    Mail::fake();

    Livewire::test(Help::class)
        ->set('subject', 'Test subject')
        ->set('description', 'This is a detailed feedback description.')
        ->call('submit')
        ->assertDispatched('error', 'Feedback forwarding is disabled.')
        ->assertNotDispatched('success');

    Http::assertNothingSent();
    Mail::assertNothingSent();
});

it('shows a notice instead of the form when feedback forwarding is disabled', function () {
    InstanceSettings::query()->whereKey(0)->update(['is_feedback_forwarding_enabled' => false]);
    Once::flush();

    Livewire::test(Help::class)
        ->assertSee('Feedback forwarding is disabled.')
        ->assertDontSee('Send feedback');
});

it('defaults to not forwarding feedback on fresh instances', function () {
    expect(InstanceSettings::query()->whereKey(0)->exists())->toBeTrue();

    InstanceSettings::query()->whereKey(0)->delete();
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    expect(instanceSettings()->is_feedback_forwarding_enabled)->toBeFalse();
});
