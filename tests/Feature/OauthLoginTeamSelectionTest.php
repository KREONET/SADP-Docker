<?php

use App\Models\InstanceSettings;
use App\Models\OauthIdentity;
use App\Models\OauthSetting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    config()->set('app.maintenance.driver', 'file');

    InstanceSettings::forceCreate([
        'id' => 0,
        'is_registration_enabled' => false,
    ]);

    Once::flush();

    OauthSetting::create([
        'provider' => 'authentik',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri' => 'https://coolify.example.com/auth/authentik/callback',
        'base_url' => 'https://authentik.example.com',
        'enabled' => true,
    ]);
});

/**
 * @return array{0: User, 1: Team, 2: Team}
 */
function oauthUserWithTwoTeams(): array
{
    $user = User::factory()->create(['email' => 'multi-team@example.com']);
    $personal = $user->teams->first();
    $personal->update(['show_boarding' => false]);

    $second = Team::factory()->create(['show_boarding' => false]);
    $user->teams()->attach($second, ['role' => 'owner']);

    OauthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'authentik',
        'issuer' => 'https://authentik.example.com',
        'provider_user_id' => 'multi-team-authentik-id',
        'email' => $user->email,
    ]);

    return [$user->refresh(), $personal, $second];
}

function fakeAuthentikTeamSelectionLogin(User $user, string $providerUserId): void
{
    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn((object) [
        'email' => $user->email,
        'name' => $user->name,
        'id' => $providerUserId,
        'user' => ['email_verified' => true],
    ]);

    Socialite::shouldReceive('driver')->with('authentik')->andReturn($provider);
}

it('restores the stored last team after an OAuth login', function () {
    [$user, , $second] = oauthUserWithTwoTeams();
    $user->forceFill(['current_team_id' => $second->id])->save();
    fakeAuthentikTeamSelectionLogin($user, 'multi-team-authentik-id');

    $this->get(route('auth.callback', 'authentik'))->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    expect(data_get(session('currentTeam'), 'id'))->toBe($second->id);

    Cache::flush();
    $this->get('/')->assertSuccessful();

    expect($user->fresh()->current_team_id)->toBe($second->id);
});

it('sends a multi-team OAuth user without a stored team to the team selection screen', function () {
    [$user] = oauthUserWithTwoTeams();
    expect($user->current_team_id)->toBeNull();
    fakeAuthentikTeamSelectionLogin($user, 'multi-team-authentik-id');

    $this->get(route('auth.callback', 'authentik'))->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    expect(session('currentTeam'))->toBeNull();

    $this->get('/')->assertRedirect(route('team.select'));

    expect($user->fresh()->current_team_id)->toBeNull();
});

it('does not overwrite a stored last team with the first team after an OAuth login', function () {
    [$user, $personal, $second] = oauthUserWithTwoTeams();
    $user->forceFill(['current_team_id' => $second->id])->save();
    fakeAuthentikTeamSelectionLogin($user, 'multi-team-authentik-id');

    $this->get(route('auth.callback', 'authentik'));
    Cache::flush();
    $this->get('/');

    expect($user->fresh()->current_team_id)->not->toBe($personal->id)
        ->and($user->fresh()->current_team_id)->toBe($second->id);
});

it('activates the only team of a single-team OAuth user', function () {
    $user = User::factory()->create(['email' => 'single-team@example.com']);
    $team = $user->teams->first();
    $team->update(['show_boarding' => false]);
    OauthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'authentik',
        'issuer' => 'https://authentik.example.com',
        'provider_user_id' => 'single-team-authentik-id',
        'email' => $user->email,
    ]);
    fakeAuthentikTeamSelectionLogin($user, 'single-team-authentik-id');

    $this->get(route('auth.callback', 'authentik'))->assertRedirect('/');

    expect(data_get(session('currentTeam'), 'id'))->toBe($team->id);
});
