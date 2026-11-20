<?php

use App\Http\Middleware\EnforceSessionTimeout;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

const SESSION_PROBE = '/__session-timeout/probe';

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    config()->set('security.session.idle_timeout_minutes', 30);
    config()->set('security.session.absolute_timeout_minutes', 720);
    config()->set('security.session.single_session', false);

    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();
    Cache::flush();
    RateLimiter::clear('login');

    $this->team = Team::factory()->create(['show_boarding' => false]);
    $this->user = User::factory()->create([
        'email' => 'session@example.com',
        'password' => Hash::make('password'),
    ]);
    $this->team->members()->attach($this->user->id, ['role' => 'member']);

    Route::middleware(['web', 'auth'])->get(SESSION_PROBE, fn () => response('ok'));
    Route::middleware(['web', 'auth'])->post(SESSION_PROBE, fn () => response()->json(['ok' => true]));

    Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function sessionTimestamps(int $authenticatedMinutesAgo, int $activeMinutesAgo): array
{
    return [
        EnforceSessionTimeout::AUTHENTICATED_AT_KEY => now()->subMinutes($authenticatedMinutesAgo)->getTimestamp(),
        EnforceSessionTimeout::LAST_ACTIVITY_KEY => now()->subMinutes($activeMinutesAgo)->getTimestamp(),
        EnforceSessionTimeout::SINGLE_SESSION_KEY => test()->user->id,
    ];
}

function sessionAuditEvents(): Collection
{
    return AuditEvent::query()->where('event', 'auth.session.expired')->get();
}

describe('timestamps', function () {
    test('the first authenticated request stamps login and activity times', function () {
        $this->actingAs($this->user)->get(SESSION_PROBE);

        expect(session(EnforceSessionTimeout::AUTHENTICATED_AT_KEY))->toBe(now()->getTimestamp())
            ->and(session(EnforceSessionTimeout::LAST_ACTIVITY_KEY))->toBe(now()->getTimestamp());
    });

    test('an active session stays signed in and refreshes the idle timer', function () {
        $this->actingAs($this->user)
            ->withSession(sessionTimestamps(60, 29))
            ->get(SESSION_PROBE);

        $this->assertAuthenticatedAs($this->user);
        expect(session(EnforceSessionTimeout::LAST_ACTIVITY_KEY))->toBe(now()->getTimestamp())
            ->and(session(EnforceSessionTimeout::AUTHENTICATED_AT_KEY))->toBe(now()->subMinutes(60)->getTimestamp());
    });

    test('a successful password login starts tracking in the login request', function () {
        $this->post('/login', ['email' => 'session@example.com', 'password' => 'password']);

        $this->assertAuthenticatedAs($this->user);
        expect(session(EnforceSessionTimeout::AUTHENTICATED_AT_KEY))->toBe(now()->getTimestamp());
    });
});

describe('expiry', function () {
    test('an idle session is logged out and redirected to the login page', function () {
        $this->actingAs($this->user)
            ->withSession(sessionTimestamps(31, 31) + ['_token' => 'old-csrf-token'])
            ->get(SESSION_PROBE)
            ->assertRedirect(route('login'));

        $this->assertGuest();
        expect(session()->token())->not->toBe('old-csrf-token')
            ->and(session(EnforceSessionTimeout::EXPIRED_NOTICE_KEY))->toBe('idle')
            ->and(session(EnforceSessionTimeout::AUTHENTICATED_AT_KEY))->toBeNull();

        $event = sessionAuditEvents()->sole();
        expect($event->metadata['reason'])->toBe('idle')
            ->and($event->metadata['result'])->toBe('success')
            ->and($event->actor_id)->toBe($this->user->id);
    });

    test('the login page shows the expiry message once', function () {
        $this->actingAs($this->user)
            ->withSession(sessionTimestamps(31, 31))
            ->get(SESSION_PROBE);

        $this->get('/login')
            ->assertSuccessful()
            ->assertSee('Your session expired due to inactivity. Please log in again.');

        $this->get('/login')
            ->assertSuccessful()
            ->assertDontSee('Your session expired due to inactivity.');
    });

    test('an old session is logged out by the absolute timeout even when active', function () {
        $this->actingAs($this->user)
            ->withSession(sessionTimestamps(721, 1))
            ->get(SESSION_PROBE)
            ->assertRedirect(route('login'));

        $this->assertGuest();
        expect(sessionAuditEvents()->sole()->metadata['reason'])->toBe('absolute');

        $this->get('/login')->assertSee('Your session has expired. Please log in again to continue.');
    });

    test('timeouts follow the security configuration', function () {
        config()->set('security.session.idle_timeout_minutes', 5);

        $this->actingAs($this->user)
            ->withSession(sessionTimestamps(6, 6))
            ->get(SESSION_PROBE)
            ->assertRedirect(route('login'));

        $this->assertGuest();
    });

    test('an expired livewire update gets a redirect the livewire client follows', function () {
        $response = $this->actingAs($this->user)
            ->withSession(sessionTimestamps(31, 31))
            ->withHeaders(['X-Livewire' => ''])
            ->postJson('/livewire/update', ['components' => []]);

        // Livewire 3 follows the redirect with fetch and then navigates to `response.url`.
        $response->assertRedirect(route('login'));
        $this->assertGuest();

        // The fetch that follows the redirect keeps the X-Livewire header and must not consume the notice.
        $this->withHeaders(['X-Livewire' => ''])->get('/login')
            ->assertDontSee('Your session expired due to inactivity.');

        expect(session(EnforceSessionTimeout::EXPIRED_NOTICE_KEY))->toBe('idle');
    });

    test('the browser navigation after a livewire redirect shows the expiry message', function () {
        $this->actingAs($this->user)
            ->withSession(sessionTimestamps(31, 31))
            ->withHeaders(['X-Livewire' => ''])
            ->postJson('/livewire/update', ['components' => []]);

        $this->withHeaders(['X-Livewire' => ''])->get('/login');

        $this->flushHeaders()->get('/login')
            ->assertSee('Your session expired due to inactivity. Please log in again.');
    });

    test('an expired json request gets a 401 with the login url', function () {
        $this->actingAs($this->user)
            ->withSession(sessionTimestamps(31, 31))
            ->postJson(SESSION_PROBE)
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Your session expired due to inactivity. Please log in again.',
                'redirect' => route('login'),
            ]);

        $this->assertGuest();
    });
});

describe('background requests', function () {
    test('requests marked as background traffic do not refresh the idle timer', function () {
        $this->actingAs($this->user)
            ->withSession(sessionTimestamps(20, 20))
            ->withHeaders(['X-Livewire' => '', EnforceSessionTimeout::ACTIVITY_HEADER => '0'])
            ->postJson(SESSION_PROBE, ['components' => [['calls' => [['method' => 'refreshStatus', 'params' => []]], 'updates' => []]]]);

        $this->assertAuthenticatedAs($this->user);
        expect(session(EnforceSessionTimeout::LAST_ACTIVITY_KEY))->toBe(now()->subMinutes(20)->getTimestamp());
    });

    test('polling alone cannot keep a session alive past the idle timeout', function () {
        $this->actingAs($this->user)->withSession(sessionTimestamps(0, 0));

        $this->travel(20)->minutes();
        $this->withHeaders(['X-Livewire' => ''])
            ->postJson(SESSION_PROBE, ['components' => [['calls' => [], 'updates' => []]]]);
        $this->assertAuthenticatedAs($this->user);

        $this->travel(11)->minutes();
        $this->withHeaders(['X-Livewire' => ''])
            ->postJson(SESSION_PROBE, ['components' => [['calls' => [['method' => '$refresh', 'params' => []]], 'updates' => []]]])
            ->assertRedirect(route('login'));

        $this->assertGuest();
    });

    test('requests marked as user activity refresh the idle timer', function () {
        $this->actingAs($this->user)
            ->withSession(sessionTimestamps(20, 20))
            ->withHeaders(['X-Livewire' => '', EnforceSessionTimeout::ACTIVITY_HEADER => '1'])
            ->postJson(SESSION_PROBE, ['components' => [['calls' => [['method' => 'refreshStatus', 'params' => []]], 'updates' => []]]]);

        expect(session(EnforceSessionTimeout::LAST_ACTIVITY_KEY))->toBe(now()->getTimestamp());
    });

    test('livewire requests without the activity header are classified from the payload', function (array $components, bool $isActivity) {
        $request = Request::create('/livewire/update', 'POST', [], [], [], ['HTTP_X_LIVEWIRE' => ''], json_encode(['components' => $components]));
        $request->headers->set('Content-Type', 'application/json');

        expect((new EnforceSessionTimeout)->countsAsUserActivity($request))->toBe($isActivity);
    })->with([
        'bare wire:poll commit' => [[['snapshot' => '{}', 'updates' => [], 'calls' => []]], false],
        '$refresh poll' => [[['snapshot' => '{}', 'updates' => [], 'calls' => [['method' => '$refresh', 'params' => []]]]], false],
        'broadcast event listener' => [[['snapshot' => '{}', 'updates' => [], 'calls' => [['method' => '__dispatch', 'params' => ['refresh', []]]]]], false],
        'action call' => [[['snapshot' => '{}', 'updates' => [], 'calls' => [['method' => 'save', 'params' => []]]]], true],
        'property update' => [[['snapshot' => '{}', 'updates' => ['name' => 'x'], 'calls' => []]], true],
        'mixed batch' => [[
            ['snapshot' => '{}', 'updates' => [], 'calls' => []],
            ['snapshot' => '{}', 'updates' => [], 'calls' => [['method' => 'delete', 'params' => []]]],
        ], true],
    ]);

    test('ordinary page requests and broadcasting auth are classified', function () {
        $middleware = new EnforceSessionTimeout;

        expect($middleware->countsAsUserActivity(Request::create('/dashboard')))->toBeTrue()
            ->and($middleware->countsAsUserActivity(Request::create('/broadcasting/auth', 'POST')))->toBeFalse();
    });
});

describe('remember me', function () {
    test('a posted remember flag does not issue a remember-me cookie', function () {
        $response = $this->post('/login', [
            'email' => 'session@example.com',
            'password' => 'password',
            'remember' => '1',
        ]);

        $this->assertAuthenticatedAs($this->user);
        $response->assertCookieMissing(Auth::guard()->getRecallerName());
        expect(Auth::guard()->viaRemember())->toBeFalse();
    });

    test('a remember-me cookie issued earlier does not sign the user in', function () {
        $this->user->forceFill(['remember_token' => 'legacy-remember-token'])->save();
        $recaller = $this->user->id.'|legacy-remember-token|'.$this->user->password;

        $this->withCookie(Auth::guard()->getRecallerName(), $recaller)
            ->get(SESSION_PROBE)
            ->assertRedirect(route('login'));

        $this->assertGuest();
        expect($this->user->fresh()->remember_token)->not->toBe('legacy-remember-token')
            ->and(AuditEvent::query()->where('event', 'auth.session.remember_rejected')->exists())->toBeTrue();
    });
});

describe('single session', function () {
    beforeEach(function () {
        config()->set('session.driver', 'database');
        app('session')->forgetDrivers();

        $other = User::factory()->create();
        DB::table('sessions')->insert([
            ['id' => 'old-session-a', 'user_id' => $this->user->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'A', 'payload' => '', 'last_activity' => now()->getTimestamp()],
            ['id' => 'old-session-b', 'user_id' => $this->user->id, 'ip_address' => '10.0.0.2', 'user_agent' => 'B', 'payload' => '', 'last_activity' => now()->getTimestamp()],
            ['id' => 'other-user-session', 'user_id' => $other->id, 'ip_address' => '10.0.0.3', 'user_agent' => 'C', 'payload' => '', 'last_activity' => now()->getTimestamp()],
        ]);
    });

    test('logging in removes the other sessions of the user when enabled', function () {
        config()->set('security.session.single_session', true);

        $this->post('/login', ['email' => 'session@example.com', 'password' => 'password']);

        $this->assertAuthenticatedAs($this->user);
        expect(DB::table('sessions')->whereIn('id', ['old-session-a', 'old-session-b'])->count())->toBe(0)
            ->and(DB::table('sessions')->where('id', 'other-user-session')->exists())->toBeTrue()
            ->and(DB::table('sessions')->where('user_id', $this->user->id)->pluck('id')->all())->toBe([session()->getId()])
            ->and(AuditEvent::query()->where('event', 'auth.session.single_session_enforced')->sole()->metadata['terminated_sessions'])->toBe(2);
    });

    test('other sessions are kept when single session is disabled', function () {
        $this->post('/login', ['email' => 'session@example.com', 'password' => 'password']);

        $this->assertAuthenticatedAs($this->user);
        expect(DB::table('sessions')->whereIn('id', ['old-session-a', 'old-session-b'])->count())->toBe(2);
    });

    test('the other sessions are removed only once per login', function () {
        config()->set('security.session.single_session', true);

        $this->post('/login', ['email' => 'session@example.com', 'password' => 'password']);
        DB::table('sessions')->insert([
            'id' => 'later-session', 'user_id' => $this->user->id, 'ip_address' => '10.0.0.9', 'user_agent' => 'D', 'payload' => '', 'last_activity' => now()->getTimestamp(),
        ]);
        $this->get(SESSION_PROBE);

        expect(DB::table('sessions')->where('id', 'later-session')->exists())->toBeTrue();
    });
});

describe('session configuration', function () {
    test('sessions default to the idle timeout and end when the browser closes', function () {
        $config = require config_path('session.php');

        expect($config['lifetime'])->toBe(30)
            ->and($config['expire_on_close'])->toBeTrue();
    });

    test('the secure cookie flag defaults to true for https app urls', function (string $appUrl, ?string $override, ?bool $expected) {
        $keys = ['APP_URL', 'SESSION_SECURE_COOKIE'];
        $backup = collect($keys)->mapWithKeys(fn (string $key) => [$key => [$_SERVER[$key] ?? null, $_ENV[$key] ?? null]]);

        try {
            foreach (['APP_URL' => $appUrl, 'SESSION_SECURE_COOKIE' => $override] as $key => $value) {
                if ($value === null) {
                    unset($_SERVER[$key], $_ENV[$key]);
                } else {
                    $_SERVER[$key] = $_ENV[$key] = $value;
                }
            }

            $config = require config_path('session.php');

            expect($config['secure'])->toBe($expected);
        } finally {
            foreach ($backup as $key => [$server, $env]) {
                if ($server === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }
                if ($env === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $env;
                }
            }
        }
    })->with([
        'https app url' => ['https://pased.example.com', null, true],
        'http app url' => ['http://localhost', null, null],
        'explicit override wins' => ['https://pased.example.com', 'false', false],
        'explicit true on http' => ['http://localhost', 'true', true],
    ]);
});
