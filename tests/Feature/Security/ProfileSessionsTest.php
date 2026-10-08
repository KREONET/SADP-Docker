<?php

use App\Livewire\Profile\Sessions;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    config()->set('app.maintenance.store', 'array');
    config()->set('session.driver', 'database');
    app('session')->forgetDrivers();

    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();
    Cache::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create(['password' => Hash::make('correct-password')]);
    $this->team->members()->attach($this->user->id, ['role' => 'member']);
    $this->otherUser = User::factory()->create();
    RateLimiter::clear('logout-other-sessions:'.$this->user->id);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $row = fn (string $id, int $userId, string $ip, string $agent): array => [
        'id' => $id,
        'user_id' => $userId,
        'ip_address' => $ip,
        'user_agent' => $agent,
        'payload' => '',
        'last_activity' => now()->subMinutes(5)->getTimestamp(),
    ];

    DB::table('sessions')->insert([
        $row(session()->getId(), $this->user->id, '192.0.2.10', 'Current Browser'),
        $row('laptop-session', $this->user->id, '192.0.2.20', 'Laptop Browser'),
        $row('phone-session', $this->user->id, '192.0.2.30', 'Phone Browser'),
        $row('foreign-session', $this->otherUser->id, '198.51.100.99', 'Foreign Browser'),
    ]);
});

function remainingSessionIds(int $userId): array
{
    return DB::table('sessions')->where('user_id', $userId)->orderBy('id')->pluck('id')->all();
}

test('it lists only the sessions of the signed-in user and marks the current one', function () {
    Livewire::test(Sessions::class)
        ->assertSee('192.0.2.10')
        ->assertSee('Laptop Browser')
        ->assertSee('192.0.2.30')
        ->assertSee('This device')
        ->assertDontSee('198.51.100.99')
        ->assertDontSee('Foreign Browser')
        ->assertDontSee('laptop-session');
});

test('session ids are never exposed to the browser', function () {
    $component = Livewire::test(Sessions::class);

    expect($component->html())->not->toContain(session()->getId())
        ->and(json_encode($component->snapshot))->not->toContain('laptop-session');
});

test('the current session is listed even before its row is written', function () {
    DB::table('sessions')->where('id', session()->getId())->delete();

    Livewire::test(Sessions::class)->assertSee('This device');
});

test('a wrong password keeps the other sessions and is audited', function () {
    Livewire::test(Sessions::class)
        ->set('password', 'wrong-password')
        ->call('logoutOtherSessions')
        ->assertHasErrors('password');

    expect(remainingSessionIds($this->user->id))->toHaveCount(3);

    $event = AuditEvent::query()->where('event', 'auth.session.others_logged_out')->sole();
    expect($event->metadata['result'])->toBe('failure')
        ->and($event->level)->toBe('warning');
});

test('the correct password logs out every other session of the user', function () {
    $rememberToken = $this->user->remember_token;

    Livewire::test(Sessions::class)
        ->set('password', 'correct-password')
        ->call('logoutOtherSessions')
        ->assertHasNoErrors()
        ->assertSet('password', '')
        ->assertDispatched('success');

    expect(remainingSessionIds($this->user->id))->toBe([session()->getId()])
        ->and(remainingSessionIds($this->otherUser->id))->toBe(['foreign-session'])
        ->and($this->user->fresh()->remember_token)->not->toBe($rememberToken);

    $event = AuditEvent::query()->where('event', 'auth.session.others_logged_out')->sole();
    expect($event->metadata['result'])->toBe('success')
        ->and($event->metadata['terminated_sessions'])->toBe(2);
});

test('a password is required for users with a local password', function () {
    Livewire::test(Sessions::class)
        ->call('logoutOtherSessions')
        ->assertHasErrors(['password' => 'required']);

    expect(remainingSessionIds($this->user->id))->toHaveCount(3);
});

test('password attempts are rate limited', function () {
    $component = Livewire::test(Sessions::class);

    foreach (range(1, 5) as $attempt) {
        $component->set('password', 'wrong-password')->call('logoutOtherSessions');
    }

    $component->set('password', 'correct-password')
        ->call('logoutOtherSessions')
        ->assertHasErrors('password');

    expect(remainingSessionIds($this->user->id))->toHaveCount(3);
});

test('the component explains when the session driver cannot list sessions', function () {
    config()->set('session.driver', 'redis');

    Livewire::test(Sessions::class)
        ->assertSee('Active sessions can only be managed with the database session driver.')
        ->assertDontSee('Laptop Browser')
        ->call('logoutOtherSessions')
        ->assertDispatched('error');

    expect(remainingSessionIds($this->user->id))->toHaveCount(3);
});

test('guests cannot use the component', function () {
    auth()->logout();

    Livewire::test(Sessions::class)->assertForbidden();
});
