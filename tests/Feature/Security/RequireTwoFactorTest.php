<?php

use App\Http\Middleware\RequireTwoFactor;
use App\Models\InstanceSettings;
use App\Models\OauthIdentity;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TwoFactor;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0]);
    Cache::flush();

    $this->rootTeam = Team::factory()->create(['id' => 0, 'name' => 'Root Team', 'show_boarding' => false]);
    $this->admin = User::factory()->create();
    $this->rootTeam->members()->attach($this->admin->id, ['role' => 'admin']);

    $this->tenantTeam = Team::factory()->create(['show_boarding' => false]);
    $this->tenant = User::factory()->create();
    $this->tenantTeam->members()->attach($this->tenant->id, ['role' => 'owner']);
});

/**
 * Run the middleware alone on a request matched against the real route table.
 */
function runRequireTwoFactor(User $user, string $method, string $uri): Response
{
    $request = Request::create($uri, $method);
    $route = app('router')->getRoutes()->match($request);
    $request->setRouteResolver(fn () => $route);
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession(app('session')->driver());

    return (new RequireTwoFactor)->handle($request, fn () => response('passed'));
}

test('platform admins without 2FA are redirected to the profile 2FA section when required for admins', function () {
    config()->set('security.two_factor.required_for', 'admins');

    $this->actingAs($this->admin)
        ->withSession(['currentTeam' => $this->rootTeam])
        ->get('/platform')
        ->assertRedirect(route('profile').'#two-factor')
        ->assertSessionHas(RequireTwoFactor::SESSION_FLASH_KEY);
});

test('regular users are not affected when 2FA is required only for admins', function () {
    config()->set('security.two_factor.required_for', 'admins');

    expect(RequireTwoFactor::mustUseTwoFactor($this->tenant))->toBeFalse()
        ->and(RequireTwoFactor::mustUseTwoFactor($this->admin))->toBeTrue();

    expect(runRequireTwoFactor($this->tenant, 'GET', '/projects')->getContent())->toBe('passed');
});

test('every user must set up 2FA when required for all', function () {
    config()->set('security.two_factor.required_for', 'all');

    $this->actingAs($this->tenant)
        ->withSession(['currentTeam' => $this->tenantTeam])
        ->get('/projects')
        ->assertRedirect(route('profile').'#two-factor');
});

test('nobody is redirected when the policy is none', function () {
    config()->set('security.two_factor.required_for', 'none');

    expect(RequireTwoFactor::mustUseTwoFactor($this->admin))->toBeFalse();
});

test('a confirmed 2FA setup satisfies the policy', function () {
    config()->set('security.two_factor.required_for', 'all');
    TwoFactor::confirm($this->admin);

    expect(RequireTwoFactor::mustUseTwoFactor($this->admin->fresh()))->toBeFalse();

    $this->admin->forceFill(['two_factor_confirmed_at' => null])->save();

    expect(RequireTwoFactor::mustUseTwoFactor($this->admin->fresh()))->toBeTrue();
});

test('the profile page and the setup routes stay reachable', function (string $method, string $uri) {
    config()->set('security.two_factor.required_for', 'all');

    expect(runRequireTwoFactor($this->admin, $method, $uri)->getContent())->toBe('passed');
})->with([
    'profile' => ['GET', '/profile'],
    'enable 2fa' => ['POST', '/user/two-factor-authentication'],
    'confirm 2fa' => ['POST', '/user/confirmed-two-factor-authentication'],
    'qr code' => ['GET', '/user/two-factor-qr-code'],
    'secret key' => ['GET', '/user/two-factor-secret-key'],
    'recovery codes' => ['GET', '/user/two-factor-recovery-codes'],
    'regenerate recovery codes' => ['POST', '/user/two-factor-recovery-codes'],
    'confirm password page' => ['GET', '/user/confirm-password'],
    'confirm password' => ['POST', '/user/confirm-password'],
    'livewire update' => ['POST', '/livewire/update'],
    'logout' => ['POST', '/logout'],
    'force password reset' => ['GET', '/force-password-reset'],
]);

test('other pages are blocked by the middleware', function (string $method, string $uri) {
    config()->set('security.two_factor.required_for', 'all');

    expect(runRequireTwoFactor($this->admin, $method, $uri)->isRedirect(route('profile').'#two-factor'))->toBeTrue();
})->with([
    'dashboard' => ['GET', '/'],
    'platform' => ['GET', '/platform'],
    'profile appearance' => ['GET', '/profile/appearance'],
]);

test('fortify can start the 2FA setup while the policy applies', function () {
    config()->set('security.two_factor.required_for', 'all');

    // Fortify asks for the password first; it must not bounce back to the profile.
    $this->actingAs($this->admin)
        ->withSession(['currentTeam' => $this->rootTeam])
        ->post('/user/two-factor-authentication')
        ->assertRedirect(route('password.confirm'));

    $this->actingAs($this->admin)
        ->withSession(['currentTeam' => $this->rootTeam])
        ->get('/user/confirm-password')
        ->assertOk();

    $this->actingAs($this->admin)
        ->withSession(['currentTeam' => $this->rootTeam, 'auth.password_confirmed_at' => time()])
        ->post('/user/two-factor-authentication')
        ->assertRedirect();

    expect($this->admin->fresh()->two_factor_secret)->not->toBeNull();
});

test('logout stays reachable', function () {
    config()->set('security.two_factor.required_for', 'all');

    $this->actingAs($this->admin)
        ->withSession(['currentTeam' => $this->rootTeam])
        ->post('/logout')
        ->assertRedirect();

    $this->assertGuest();
});

test('json requests get a 403 instead of a redirect', function () {
    config()->set('security.two_factor.required_for', 'all');

    $this->actingAs($this->admin)
        ->withSession(['currentTeam' => $this->rootTeam])
        ->getJson('/platform')
        ->assertForbidden();
});

test('api token requests are not affected', function () {
    config()->set('security.two_factor.required_for', 'all');
    InstanceSettings::query()->whereKey(0)->update(['is_api_enabled' => true]);
    session(['currentTeam' => $this->rootTeam]);
    $token = $this->admin->createToken('ci', ['read'], now()->addDay());

    $this->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken])
        ->getJson('/api/v1/projects')
        ->assertOk();
});

test('sso accounts without a usable local password are exempt', function () {
    config()->set('security.two_factor.required_for', 'all');
    OauthIdentity::create([
        'user_id' => $this->admin->id,
        'provider' => 'oidc',
        'issuer' => 'https://idp.example.com',
        'provider_user_id' => 'subject-1',
        'email' => $this->admin->email,
    ]);

    expect(RequireTwoFactor::mustUseTwoFactor($this->admin->fresh()))->toBeFalse();
});

test('platform admins with confirmed 2FA reach platform pages', function () {
    config()->set('security.two_factor.required_for', 'admins');
    TwoFactor::confirm($this->admin);

    expect(runRequireTwoFactor($this->admin->fresh(), 'GET', '/platform')->getContent())->toBe('passed');
});

test('auditors must use 2FA when it is required for admins', function () {
    config()->set('security.two_factor.required_for', 'admins');
    $this->tenant->forceFill(['is_auditor' => true])->save();

    expect(RequireTwoFactor::mustUseTwoFactor($this->tenant->fresh()))->toBeTrue();
});
