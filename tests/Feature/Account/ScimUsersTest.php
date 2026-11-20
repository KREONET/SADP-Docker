<?php

use App\Jobs\DeleteResourceJob;
use App\Livewire\SettingsOauth;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([DeleteResourceJob::class]);
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();
    $this->rootTeam = Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    $this->root = User::factory()->create(['id' => 0, 'name' => 'Root User', 'email' => 'root@example.com']);
    $this->rootTeam->members()->syncWithoutDetaching([$this->root->id => ['role' => 'owner']]);

    $this->oidc = OauthSetting::create(['provider' => 'oidc']);
    $this->token = $this->oidc->issueScimToken();
});

function scim(string $method, string $uri, array $data = [], ?string $token = null)
{
    $headers = ['Accept' => 'application/scim+json'];
    if ($token !== null) {
        $headers['Authorization'] = 'Bearer '.$token;
    }

    return test()->json($method, '/api/scim/v2'.$uri, $data, $headers);
}

it('rejects requests without a valid token', function () {
    scim('GET', '/Users')->assertUnauthorized()->assertJsonPath('status', '401');
    scim('GET', '/Users', [], 'scim_wrong')->assertUnauthorized();
});

it('rejects every token once the token is revoked', function () {
    $this->oidc->revokeScimToken();

    scim('GET', '/Users', [], $this->token)->assertUnauthorized();
});

it('stores only a hash of the token', function () {
    expect($this->oidc->fresh()->scim_token_hash)->toBe(hash('sha256', $this->token))
        ->and($this->oidc->fresh()->toArray())->not->toHaveKey('scim_token_hash');
});

it('serves the discovery documents', function () {
    scim('GET', '/ServiceProviderConfig', [], $this->token)->assertOk()->assertJsonPath('patch.supported', true);
    scim('GET', '/ResourceTypes', [], $this->token)->assertOk()->assertJsonPath('Resources.0.endpoint', '/Users');
    scim('GET', '/Schemas', [], $this->token)->assertOk()->assertJsonPath('totalResults', 1);
});

it('lists users without the root user and filters by userName', function () {
    $alice = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
    User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);

    $list = scim('GET', '/Users', [], $this->token)->assertOk();
    expect($list->headers->get('Content-Type'))->toContain('application/scim+json')
        ->and($list->json('totalResults'))->toBe(2)
        ->and(collect($list->json('Resources'))->pluck('userName'))->not->toContain('root@example.com');

    scim('GET', '/Users?filter='.urlencode('userName eq "ALICE@example.com"'), [], $this->token)
        ->assertOk()
        ->assertJsonPath('totalResults', 1)
        ->assertJsonPath('Resources.0.id', (string) $alice->id);

    scim('GET', '/Users?filter='.urlencode('displayName co "a"'), [], $this->token)
        ->assertStatus(400)->assertJsonPath('scimType', 'invalidFilter');
});

it('creates a user and refuses a duplicate userName', function () {
    $payload = [
        'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
        'userName' => 'New.User@example.com',
        'name' => ['givenName' => 'New', 'familyName' => 'User'],
        'emails' => [['value' => 'new.user@example.com', 'primary' => true]],
        'active' => true,
    ];

    $response = scim('POST', '/Users', $payload, $this->token)->assertCreated()
        ->assertJsonPath('userName', 'new.user@example.com')
        ->assertJsonPath('displayName', 'New User');

    $user = User::find((int) $response->json('id'));
    expect($user)->not->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->teams()->where('personal_team', true)->exists())->toBeTrue();

    scim('POST', '/Users', $payload, $this->token)->assertStatus(409)->assertJsonPath('scimType', 'uniqueness');
});

it('updates the display name with a patch operation', function () {
    $user = User::factory()->create(['name' => 'Old Name', 'email' => 'patch@example.com']);

    scim('PATCH', "/Users/{$user->id}", [
        'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
        'Operations' => [['op' => 'Replace', 'path' => 'displayName', 'value' => 'New Name']],
    ], $this->token)->assertOk()->assertJsonPath('displayName', 'New Name');

    expect($user->fresh()->name)->toBe('New Name');
});

it('deactivates the user without deleting anything when the identity provider sets active to false', function (array $operation) {
    $user = User::factory()->create(['name' => 'Paused', 'email' => 'paused@example.com']);
    $team = $user->teams()->first();
    DB::table('sessions')->insert(['id' => 'paused-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);

    scim('PATCH', "/Users/{$user->id}", [
        'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
        'Operations' => [$operation],
    ], $this->token)->assertOk()->assertJsonPath('active', false);

    expect($user->fresh()->isDeactivated())->toBeTrue()
        ->and(Team::find($team->id))->not->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();
    Bus::assertNothingDispatched();
    scim('GET', "/Users/{$user->id}", [], $this->token)->assertOk()->assertJsonPath('active', false);
})->with([
    'path form' => [['op' => 'replace', 'path' => 'active', 'value' => false]],
    'value object form' => [['op' => 'replace', 'value' => ['active' => false]]],
    'string value' => [['op' => 'Replace', 'path' => 'active', 'value' => 'False']],
]);

it('deactivates the user when a full replace sets active to false and applies the other attributes', function () {
    $user = User::factory()->create(['name' => 'Paused', 'email' => 'replace@example.com']);

    scim('PUT', "/Users/{$user->id}", ['userName' => 'replace@example.com', 'displayName' => 'Renamed', 'active' => false], $this->token)
        ->assertOk()->assertJsonPath('active', false)->assertJsonPath('displayName', 'Renamed');

    expect($user->fresh())->not->toBeNull()
        ->and($user->fresh()->isDeactivated())->toBeTrue()
        ->and($user->fresh()->name)->toBe('Renamed');
});

it('reactivates a deactivated user when the identity provider sets active to true', function () {
    $user = User::factory()->create(['email' => 'back@example.com']);
    $user->forceFill(['deactivated_at' => now()])->save();

    scim('PATCH', "/Users/{$user->id}", [
        'Operations' => [['op' => 'replace', 'path' => 'active', 'value' => true]],
    ], $this->token)->assertOk()->assertJsonPath('active', true);

    expect($user->fresh()->isDeactivated())->toBeFalse();
});

it('deletes the user on DELETE', function () {
    $user = User::factory()->create(['name' => 'Leaving', 'email' => 'delete@example.com']);

    scim('DELETE', "/Users/{$user->id}", [], $this->token)->assertNoContent();

    expect(User::find($user->id))->toBeNull();
    scim('GET', "/Users/{$user->id}", [], $this->token)->assertNotFound()->assertJsonPath('status', '404');
});

it('never lets SCIM read or change the root user', function () {
    scim('GET', '/Users/0', [], $this->token)->assertForbidden();
    scim('DELETE', '/Users/0', [], $this->token)->assertForbidden();
    scim('PATCH', '/Users/0', ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]], $this->token)->assertForbidden();

    expect(User::find(0))->not->toBeNull();
});

it('returns 404 for unknown users', function () {
    scim('GET', '/Users/999999', [], $this->token)->assertNotFound();
    scim('GET', '/Users/not-a-number', [], $this->token)->assertNotFound();
});

it('lets platform admins generate and revoke the SCIM token', function () {
    $this->actingAs($this->root);
    session(['currentTeam' => $this->rootTeam]);

    $component = Livewire::test(SettingsOauth::class)->call('generateScimToken');
    $newToken = $component->get('newScimToken');

    expect($newToken)->toStartWith('scim_')
        ->and(OauthSetting::scimTokenMatches($newToken))->toBeTrue()
        ->and(OauthSetting::scimTokenMatches($this->token))->toBeFalse();

    $component->call('revokeScimToken');

    expect(OauthSetting::scimTokenMatches($newToken))->toBeFalse()
        ->and($this->oidc->fresh()->scim_token_hash)->toBeNull();
});

it('does not let other users manage the SCIM token', function () {
    $tenant = Team::factory()->create();
    $owner = User::factory()->create(['name' => 'Owner']);
    $tenant->members()->attach($owner->id, ['role' => 'owner']);

    $this->actingAs($owner);
    session(['currentTeam' => $tenant]);
    Livewire::test(SettingsOauth::class)->assertForbidden();

    // A page opened by an admin still re-checks the acting user on every action
    foreach (['generateScimToken', 'revokeScimToken'] as $action) {
        $this->actingAs($this->root);
        session(['currentTeam' => $this->rootTeam]);
        $component = Livewire::test(SettingsOauth::class);
        $this->actingAs($owner);
        session(['currentTeam' => $tenant]);
        $component->call($action)->assertForbidden();
    }

    expect(OauthSetting::scimTokenMatches($this->token))->toBeTrue();
});
