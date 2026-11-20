<?php

use App\Livewire\Settings\Index;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0]);
    $this->rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    $this->server = Server::factory()->create(['id' => 0, 'team_id' => 0, 'ip' => '203.0.113.10']);
    $this->admin = User::factory()->create();
    $this->rootTeam->members()->attach($this->admin->id, ['role' => 'admin']);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->rootTeam]);
    Cache::flush();
});

it('saves and reloads the public domain through instance settings', function () {
    Livewire::test(Index::class)
        ->set('public_domain', 'https://apps.example.com/')
        ->call('instantSave')
        ->assertHasNoErrors();

    expect(InstanceSettings::get()->public_domain)->toBe('https://apps.example.com');
    Livewire::test(Index::class)->assertSet('public_domain', 'https://apps.example.com');
    expect(generateUrl($this->server, 'my-app'))->toBe('https://my-app.apps.example.com');
});

it('uses the public domain for URL and compose domain generation', function (string $scheme, bool $forceHttps) {
    InstanceSettings::get()->update(['public_domain' => $scheme.'://apps.example.com']);
    config()->set('constants.coolify.version', '4.0.0-beta.420.7');
    $expectedScheme = $forceHttps ? 'https' : $scheme;

    expect(generateUrl($this->server, 'my-app', $forceHttps))->toBe($expectedScheme.'://my-app.apps.example.com')
        ->and(generateFqdn($this->server, 'my-app', $forceHttps, 4))->toBe($expectedScheme.'://my-app.apps.example.com')
        ->and(generateFqdn($this->server, 'my-app', $forceHttps, 5))->toBe('my-app.apps.example.com');
})->with([
    ['http', false],
    ['https', false],
    ['http', true],
]);

it('preserves the server wildcard domain override', function () {
    InstanceSettings::get()->update(['public_domain' => 'https://apps.example.com']);
    $this->server->settings->update(['wildcard_domain' => 'http://server.example.net']);

    expect(generateUrl($this->server, 'my-app'))->toBe('http://my-app.server.example.net')
        ->and(generateFqdn($this->server, 'my-app', parserVersion: 4))->toBe('http://my-app.server.example.net');
});

it('uses sslip when the public domain is cleared', function () {
    InstanceSettings::get()->update(['public_domain' => 'https://apps.example.com']);
    Livewire::test(Index::class)
        ->set('public_domain', '')
        ->call('instantSave')
        ->assertHasNoErrors();

    expect(InstanceSettings::get()->public_domain)->toBeNull()
        ->and(generateUrl($this->server, 'my-app'))->toBe('http://my-app.203.0.113.10.sslip.io');
});

it('rejects public domains that cannot serve as a base URL', function (string $domain) {
    Livewire::test(Index::class)
        ->set('public_domain', $domain)
        ->call('instantSave')
        ->assertHasErrors('public_domain');

    expect(InstanceSettings::get()->public_domain)->toBeNull();
})->with([
    'apps.example.com',
    'ftp://apps.example.com',
    'https://apps.example.com/path',
    'https://apps.example.com?query=1',
    'https://apps.example.com#fragment',
    'https://user:password@apps.example.com',
    'https://apps.example.com:8080',
    'https://*.example.com',
]);

it('denies public domain changes after the current user loses instance admin access', function (bool $otherTeam) {
    $component = Livewire::test(Index::class)->set('public_domain', 'https://hijacked.example.com');
    $user = User::factory()->create();
    $team = $otherTeam ? Team::factory()->create() : $this->rootTeam;
    $team->members()->attach($user->id, ['role' => $otherTeam ? 'owner' : 'member']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);
    Cache::flush();

    $component->call('instantSave')->assertForbidden();
    expect(InstanceSettings::get()->public_domain)->toBeNull();
})->with([false, true]);

it('saves the public domain from the settings form submit action', function () {
    config()->set('constants.coolify.self_hosted', false);

    Livewire::test(Index::class)
        ->set('public_domain', 'https://apps.example.com')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect(InstanceSettings::get()->public_domain)->toBe('https://apps.example.com');
});
