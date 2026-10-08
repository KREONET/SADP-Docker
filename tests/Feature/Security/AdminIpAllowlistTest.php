<?php

use App\Http\Middleware\RestrictAdminIps;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Support\AdminIpAllowlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Once;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TwoFactor;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->withoutDefer();
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();
    Cache::flush();

    $this->rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    $this->platformAdmin = TwoFactor::confirm(User::factory()->create());
    $this->rootTeam->members()->attach($this->platformAdmin->id, ['role' => 'admin']);

    $this->tenantTeam = Team::factory()->create(['name' => 'Tenant Team', 'show_boarding' => false]);
    $this->tenant = User::factory()->create();
    $this->tenantTeam->members()->attach($this->tenant->id, ['role' => 'owner']);

    $this->auditor = User::factory()->create();
    $this->auditor->forceFill(['is_auditor' => true])->save();
    $this->tenantTeam->members()->attach($this->auditor->id, ['role' => 'member']);
});

function restrictAdminsTo(string $ranges): void
{
    InstanceSettings::query()->whereKey(0)->update(['admin_allowed_ips' => $ranges]);
    Once::flush();
    Cache::flush();
}

function actAsAllowlistUser(User $user, Team $team): void
{
    test()->actingAs($user);
    session(['currentTeam' => $team]);
}

/**
 * Run the middleware on a hand-built request and report whether it reached the next handler.
 *
 * @param  array<string, mixed>  $server
 * @param  array<string, mixed>  $body
 */
function runRestrictAdminIps(string $uri, array $server = [], array $body = [], string $method = 'GET'): Response
{
    $request = Request::create($uri, $method, $body, [], [], $server);
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => auth()->user());

    return (new RestrictAdminIps)->handle($request, fn () => response('passed'));
}

/**
 * @return array<string, mixed>
 */
function livewireUpdateBody(string $path): array
{
    return ['components' => [[
        'snapshot' => json_encode(['data' => [], 'memo' => ['id' => 'abc', 'name' => 'component', 'path' => $path, 'method' => 'GET'], 'checksum' => 'x']),
        'updates' => [],
        'calls' => [],
    ]]];
}

test('without an allowlist administrators are not restricted', function () {
    actAsAllowlistUser($this->platformAdmin, $this->rootTeam);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
        ->get('/platform/logs')
        ->assertOk();
    $this->assertAuthenticatedAs($this->platformAdmin);
});

test('platform admins from an allowed address keep access', function () {
    restrictAdminsTo("198.51.100.0/24\n2001:db8::/32");
    actAsAllowlistUser($this->platformAdmin, $this->rootTeam);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get('/platform/logs')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::42'])->get('/platform/logs')->assertOk();
    $this->assertAuthenticatedAs($this->platformAdmin);
});

test('privileged users from other addresses are signed out with an explanation', function (string $who) {
    Log::spy();
    restrictAdminsTo('198.51.100.0/24');
    $user = $who === 'admin' ? $this->platformAdmin : $this->auditor;
    actAsAllowlistUser($user, $who === 'admin' ? $this->rootTeam : $this->tenantTeam);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
        ->get('/profile')
        ->assertForbidden()
        ->assertSee('Administrator access is restricted to approved IP addresses.')
        ->assertSee('You were signed out');

    $this->assertGuest();
    $event = AuditEvent::query()->where('event', 'auth.admin_ip.denied')->first();
    expect($event)->not->toBeNull()
        ->and($event->result)->toBe('denied')
        ->and($event->actor_id)->toBe($user->id)
        ->and(data_get($event->metadata, 'client_ip'))->toBe('203.0.113.5');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => ($context['type'] ?? null) === 'admin_ip_denied');
})->with(['admin', 'auditor']);

test('regular users are not affected outside the admin area', function () {
    restrictAdminsTo('198.51.100.0/24');
    actAsAllowlistUser($this->tenant, $this->tenantTeam);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])->get('/tags')->assertOk();
    $this->assertAuthenticatedAs($this->tenant);
    expect(AuditEvent::query()->where('event', 'auth.admin_ip.denied')->exists())->toBeFalse();
});

test('the admin area requires an allowed address for everyone', function (string $path) {
    restrictAdminsTo('198.51.100.0/24');
    actAsAllowlistUser($this->tenant, $this->tenantTeam);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
        ->get($path)
        ->assertForbidden()
        ->assertSee('This area can only be opened from the IP addresses an administrator approved.');
    $this->assertAuthenticatedAs($this->tenant);
    expect(AuditEvent::query()->where('event', 'auth.admin_ip.denied')->where('result', 'denied')->exists())->toBeTrue();
})->with(['/platform', '/platform/logs', '/settings', '/terminal']);

test('the login page stays reachable from any address', function () {
    restrictAdminsTo('198.51.100.0/24');

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])->get('/login')->assertOk();
});

test('a forwarded header from a direct public client is ignored', function () {
    restrictAdminsTo('198.51.100.0/24');
    actAsAllowlistUser($this->platformAdmin, $this->rootTeam);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
        ->withHeaders(['X-Forwarded-For' => '198.51.100.7'])
        ->get('/platform/logs')
        ->assertForbidden();
    $this->assertGuest();
});

test('behind the local proxy the right-most public forwarded address is used', function () {
    restrictAdminsTo('198.51.100.0/24');
    actAsAllowlistUser($this->platformAdmin, $this->rootTeam);

    $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.2'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9, 198.51.100.7, 10.0.0.3'])
        ->get('/platform/logs')
        ->assertOk();
    $this->assertAuthenticatedAs($this->platformAdmin);

    // A client prepending an allowed address does not help: the proxy appended the real one.
    $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.2'])
        ->withHeaders(['X-Forwarded-For' => '198.51.100.7, 203.0.113.9'])
        ->get('/platform/logs')
        ->assertForbidden();
    $this->assertGuest();
});

test('the client address resolver', function (array $server, ?string $forwardedFor, ?string $expected) {
    $request = Request::create('/', 'GET', [], [], [], $server);
    if ($forwardedFor !== null) {
        $request->headers->set('X-Forwarded-For', $forwardedFor);
    }

    expect(AdminIpAllowlist::clientIp($request))->toBe($expected);
})->with([
    'direct public client' => [['REMOTE_ADDR' => '203.0.113.5'], null, '203.0.113.5'],
    'direct public client with spoofed header' => [['REMOTE_ADDR' => '203.0.113.5'], '198.51.100.7', '203.0.113.5'],
    'loopback proxy' => [['REMOTE_ADDR' => '127.0.0.1'], '198.51.100.7', '198.51.100.7'],
    'docker proxy with private hops' => [['REMOTE_ADDR' => '172.18.0.2'], '203.0.113.9, 198.51.100.7, 10.0.0.3', '198.51.100.7'],
    'proxy without header' => [['REMOTE_ADDR' => '172.18.0.2'], null, '172.18.0.2'],
    'lan client through proxy' => [['REMOTE_ADDR' => '172.18.0.2'], '192.168.1.20', '192.168.1.20'],
    'garbage in header' => [['REMOTE_ADDR' => '172.18.0.2'], '198.51.100.7, not-an-ip', '172.18.0.2'],
    'ports and brackets' => [['REMOTE_ADDR' => '10.0.0.2'], '[2001:db8::1]:443, 198.51.100.7:5555', '198.51.100.7'],
    'ipv4 mapped ipv6 peer' => [['REMOTE_ADDR' => '::ffff:203.0.113.5'], '198.51.100.7', '203.0.113.5'],
]);

test('allowlist matching accepts addresses and CIDR ranges and ignores invalid entries', function () {
    $ranges = AdminIpAllowlist::ranges("198.51.100.7, 10.0.0.0/8\nnonsense 2001:db8::/32");

    expect($ranges)->toBe(['198.51.100.7', '10.0.0.0/8', '2001:db8::/32'])
        ->and(AdminIpAllowlist::allows('198.51.100.7', $ranges))->toBeTrue()
        ->and(AdminIpAllowlist::allows('10.20.30.40', $ranges))->toBeTrue()
        ->and(AdminIpAllowlist::allows('2001:db8::5', $ranges))->toBeTrue()
        ->and(AdminIpAllowlist::allows('198.51.100.8', $ranges))->toBeFalse()
        ->and(AdminIpAllowlist::allows(null, $ranges))->toBeFalse()
        ->and(AdminIpAllowlist::allows('198.51.100.7', []))->toBeFalse();
});

test('livewire updates of admin area components require an allowed address', function () {
    restrictAdminsTo('198.51.100.0/24');
    actAsAllowlistUser($this->tenant, $this->tenantTeam);
    $server = ['REMOTE_ADDR' => '203.0.113.5'];

    expect(runRestrictAdminIps('/livewire/update', $server, livewireUpdateBody('platform/logs'), 'POST')->getStatusCode())->toBe(403)
        ->and(runRestrictAdminIps('/livewire/update', $server, livewireUpdateBody('settings/advanced'), 'POST')->getStatusCode())->toBe(403)
        ->and(runRestrictAdminIps('/livewire/update', $server, livewireUpdateBody('tags'), 'POST')->getContent())->toBe('passed')
        ->and(runRestrictAdminIps('/livewire/update', $server, ['components' => 'junk'], 'POST')->getContent())->toBe('passed');
});

test('livewire updates from allowed addresses pass', function () {
    restrictAdminsTo('198.51.100.0/24');
    actAsAllowlistUser($this->platformAdmin, $this->rootTeam);

    expect(runRestrictAdminIps('/livewire/update', ['REMOTE_ADDR' => '198.51.100.7'], livewireUpdateBody('platform/logs'), 'POST')->getContent())->toBe('passed');
});

test('internal terminal server calls on loopback are not blocked', function () {
    restrictAdminsTo('198.51.100.0/24');
    actAsAllowlistUser($this->platformAdmin, $this->rootTeam);

    expect(runRestrictAdminIps('/terminal/auth/ips', ['REMOTE_ADDR' => '127.0.0.1'], [], 'POST')->getContent())->toBe('passed');
    $this->assertAuthenticatedAs($this->platformAdmin);

    // The same endpoint reached through the proxy is checked like any other request.
    expect(runRestrictAdminIps('/terminal/auth/ips', ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5'], [], 'POST')->getStatusCode())->toBe(403);
});
