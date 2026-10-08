<?php

use App\Http\Controllers\ProxyAuthController;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\ProxyAuth\ApplicationAccessTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0, 'fqdn' => 'https://panel.example.org']);
    Once::flush();
    Cache::flush();

    $this->user = User::factory()->create(['email' => 'visitor@example.org']);
    Team::query()->update(['show_boarding' => false]);
    $this->application = signedInOnlyApplication('https://app.example.org,https://alt.example.org/docs');
    $this->otherApplication = signedInOnlyApplication('https://other.example.org');
});

function signedInOnlyApplication(string $fqdn, string $mode = 'login'): Application
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();

    return Application::factory()->createOne([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => $fqdn,
        'exposure_mode' => $mode,
    ]);
}

/**
 * A request as the proxy forwards it to the verify endpoint.
 */
function proxyVerify(string $host, string $uri = '/', ?string $cookie = null, string $proto = 'https'): TestResponse
{
    $headers = [
        'X-Forwarded-Host' => $host,
        'X-Forwarded-Uri' => $uri,
        'X-Forwarded-Proto' => $proto,
        'X-Forwarded-Method' => 'GET',
    ];
    if ($cookie !== null) {
        $headers['Cookie'] = 'theme=dark; '.ApplicationAccessTokens::COOKIE_NAME.'='.$cookie;
    }

    return test()->withHeaders($headers)->get('/proxy-auth/verify');
}

it('refuses hosts that are not a signed-in users only application', function () {
    signedInOnlyApplication('https://public.example.org', 'public');

    proxyVerify('unknown.example.org')->assertForbidden();
    proxyVerify('public.example.org')->assertForbidden();
    proxyVerify('app.example.org.evil.test')->assertForbidden();
});

it('sends visitors without a session to the panel sign-in with the page to return to', function () {
    $response = proxyVerify('APP.example.org:443', '/reports?month=10');

    $response->assertRedirect('https://panel.example.org/proxy-auth/login?'.http_build_query([
        'app' => $this->application->uuid,
        'host' => 'app.example.org',
        'rd' => '/reports?month=10',
    ]));
    expect($response->headers->getCookies())->toBeEmpty();
});

it('lets a valid session through with the user headers', function () {
    $session = app(ApplicationAccessTokens::class)->issueSession($this->user->id, $this->application, 'app.example.org');

    proxyVerify('app.example.org', '/', $session)
        ->assertOk()
        ->assertHeader('X-Paded-User', (string) $this->user->id)
        ->assertHeader('X-Paded-Email', 'visitor@example.org');
});

it('rejects tampered, expired, other application, and other host sessions', function () {
    $tokens = app(ApplicationAccessTokens::class);
    $valid = $tokens->issueSession($this->user->id, $this->application, 'app.example.org');
    [$payload, $signature] = explode('.', $valid);
    $tampered = rtrim(strtr(base64_encode(json_encode(['u' => 999, 'a' => $this->application->uuid, 'h' => 'app.example.org', 'e' => now()->addHour()->timestamp])), '+/', '-_'), '=').'.'.$signature;

    proxyVerify('app.example.org', '/', $tampered)->assertRedirect();
    proxyVerify('app.example.org', '/', $payload.'.'.str_repeat('0', 64))->assertRedirect();
    proxyVerify('other.example.org', '/', $tokens->issueSession($this->user->id, $this->application, 'other.example.org'))->assertRedirect();
    proxyVerify('alt.example.org', '/', $valid)->assertRedirect();

    $this->travel(ApplicationAccessTokens::SESSION_TTL_SECONDS + 60)->seconds();
    proxyVerify('app.example.org', '/', $valid)->assertRedirect();
});

it('rejects the session of a deleted user', function () {
    $session = app(ApplicationAccessTokens::class)->issueSession($this->user->id, $this->application, 'app.example.org');
    $this->user->forceDelete();

    proxyVerify('app.example.org', '/', $session)->assertRedirect();
});

it('exchanges a one-time code on the application domain for a session cookie', function () {
    $code = app(ApplicationAccessTokens::class)->issueCode($this->user, $this->application, 'app.example.org', '/reports');

    $response = proxyVerify('app.example.org', ProxyAuthController::CALLBACK_PATH.'?code='.$code);

    $response->assertRedirect('/reports');
    $cookie = collect($response->headers->getCookies())->firstWhere(fn ($cookie) => $cookie->getName() === ApplicationAccessTokens::COOKIE_NAME);
    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax')
        ->and($cookie->getDomain())->toBeNull()
        ->and($cookie->getPath())->toBe('/');

    proxyVerify('app.example.org', '/', $cookie->getValue())->assertOk();
    proxyVerify('app.example.org', ProxyAuthController::CALLBACK_PATH.'?code='.$code)->assertForbidden();
});

it('refuses a code on another application or host', function () {
    $tokens = app(ApplicationAccessTokens::class);

    $code = $tokens->issueCode($this->user, $this->application, 'app.example.org', '/');
    proxyVerify('other.example.org', ProxyAuthController::CALLBACK_PATH.'?code='.$code)->assertForbidden();

    $code = $tokens->issueCode($this->user, $this->application, 'app.example.org', '/');
    proxyVerify('alt.example.org', '/docs'.ProxyAuthController::CALLBACK_PATH.'?code='.$code)->assertForbidden();

    proxyVerify('app.example.org', ProxyAuthController::CALLBACK_PATH.'?code=made-up')->assertForbidden();
});

it('requires a panel sign-in before issuing a code', function () {
    $this->get('/proxy-auth/login?app='.$this->application->uuid)->assertRedirect('/login');
});

it('sends the signed-in user back to the application domain with a code that returns to the page', function () {
    $this->actingAs($this->user);

    $response = $this->get('/proxy-auth/login?'.http_build_query(['app' => $this->application->uuid, 'host' => 'alt.example.org', 'rd' => '/docs/page?x=1']));

    $location = $response->headers->get('Location');
    expect($location)->toStartWith('https://alt.example.org/docs/__paded_auth/callback?code=');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect(app(ApplicationAccessTokens::class)->redeemCode($query['code'], $this->application, 'alt.example.org'))
        ->toBe(['user_id' => $this->user->id, 'return_path' => '/docs/page?x=1']);
});

it('only sends users to the application\'s own domains and paths', function (string $host, string $rd) {
    $this->actingAs($this->user);

    $location = $this->get('/proxy-auth/login?'.http_build_query(['app' => $this->application->uuid, 'host' => $host, 'rd' => $rd]))
        ->headers->get('Location');

    expect($location)->toStartWith('https://app.example.org/__paded_auth/callback?code=');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect(app(ApplicationAccessTokens::class)->redeemCode($query['code'], $this->application, 'app.example.org')['return_path'])->toBe('/');
})->with([
    ['evil.example.org', '//evil.example.org/'],
    ['other.example.org', 'https://evil.example.org/'],
    ['', '/\\evil.example.org'],
    ['app.example.org', ProxyAuthController::CALLBACK_PATH.'?code=loop'],
]);

it('does not issue codes for applications that are not signed-in users only', function () {
    $this->actingAs($this->user);
    $public = signedInOnlyApplication('https://public.example.org', 'public');

    $this->get('/proxy-auth/login?app='.$public->uuid)->assertNotFound();
    $this->get('/proxy-auth/login?app=missing')->assertNotFound();
});

it('keeps only same-site return paths', function (string $uri, string $expected) {
    expect(ProxyAuthController::safeReturnPath($uri))->toBe($expected);
})->with([
    ['/dashboard?tab=1', '/dashboard?tab=1'],
    ['//evil.example.org', '/'],
    ['https://evil.example.org', '/'],
    ['/\\evil.example.org', '/'],
    ["/path\r\nSet-Cookie: x=1", '/'],
    ['relative', '/'],
]);
