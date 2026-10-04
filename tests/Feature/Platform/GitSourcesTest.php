<?php

use App\Livewire\Platform\GitSources;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\ForgejoApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->withoutDefer();
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $this->rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    $this->platformAdmin = User::factory()->create(['name' => 'Platform Admin']);
    $this->rootTeam->members()->attach($this->platformAdmin->id, ['role' => 'admin']);
    $this->rootMember = User::factory()->create(['name' => 'Root Member']);
    $this->rootTeam->members()->attach($this->rootMember->id, ['role' => 'member']);

    $this->tenant = Team::factory()->create(['name' => 'Tenant']);
    $this->tenantOwner = User::factory()->create(['name' => 'Tenant Owner']);
    $this->tenant->members()->attach($this->tenantOwner->id, ['role' => 'owner']);
});

function fakeForgejoUser(int $status = 200): void
{
    Http::fake(['93.184.216.34/api/v1/user' => Http::response($status === 200 ? ['login' => 'coolify-bot'] : ['message' => 'error'], $status)]);
}

function actAsGitSourceUser(User $user, Team $team): void
{
    test()->actingAs($user);
    session(['currentTeam' => $team]);
}

function fillGitSourceForm($component, array $values = [])
{
    $values = [...['name' => 'company-forgejo', 'htmlUrl' => 'https://93.184.216.34', 'accessToken' => 'fg-secret-token'], ...$values];

    return $component->set('name', $values['name'])->set('htmlUrl', $values['htmlUrl'])->set('accessToken', $values['accessToken']);
}

test('only platform admins can manage git sources', function (string $role) {
    $source = ForgejoApp::factory()->create();
    [$user, $team] = match ($role) {
        'root member' => [$this->rootMember, $this->rootTeam],
        'tenant owner' => [$this->tenantOwner, $this->tenant],
    };
    actAsGitSourceUser($user, $team);

    Livewire::test(GitSources::class)->assertForbidden();

    expect(ForgejoApp::query()->count())->toBe(1)->and($source->fresh())->not->toBeNull();
})->with(['root member', 'tenant owner']);

test('every git source action re-checks the permission of an open page', function (string $action) {
    $source = ForgejoApp::factory()->create();
    actAsGitSourceUser($this->platformAdmin, $this->rootTeam);
    $component = Livewire::test(GitSources::class);
    fillGitSourceForm($component);

    $this->rootTeam->members()->updateExistingPivot($this->platformAdmin->id, ['role' => 'member']);
    $this->platformAdmin->unsetRelation('teams');

    match ($action) {
        'add' => $component->call('add'),
        'test' => $component->call('test', $source->uuid),
        'remove' => $component->call('remove', $source->uuid),
        'rotate' => $component->call('startRotating', $source->uuid),
    };
    $component->assertForbidden();

    expect(ForgejoApp::query()->count())->toBe(1);
})->with(['add', 'test', 'remove', 'rotate']);

test('a platform admin adds a forgejo server after the token is verified', function () {
    fakeForgejoUser();
    actAsGitSourceUser($this->platformAdmin, $this->rootTeam);

    fillGitSourceForm(Livewire::test(GitSources::class))
        ->call('add')
        ->assertHasNoErrors()
        ->assertDispatched('success')
        ->assertSet('accessToken', '');

    $source = ForgejoApp::query()->firstOrFail();
    expect($source)
        ->name->toBe('company-forgejo')
        ->html_url->toBe('https://93.184.216.34')
        ->login->toBe('coolify-bot')
        ->access_token->toBe('fg-secret-token')
        ->is_system_wide->toBeTrue()
        ->team_id->toBe(0)
        ->and(DB::table('forgejo_apps')->value('access_token'))->not->toContain('fg-secret-token')
        ->and($source->toArray())->not->toHaveKey('access_token');

    Http::assertSent(fn ($request) => $request->url() === 'https://93.184.216.34/api/v1/user'
        && $request->hasHeader('Authorization', 'token fg-secret-token'));
    expect(AuditEvent::query()->where('event', 'ui.forgejo_app.created')->exists())->toBeTrue();
});

test('the server url may be given as the api url', function () {
    fakeForgejoUser();
    actAsGitSourceUser($this->platformAdmin, $this->rootTeam);

    fillGitSourceForm(Livewire::test(GitSources::class), ['htmlUrl' => 'https://93.184.216.34/api/v1/'])
        ->call('add')
        ->assertHasNoErrors();

    expect(ForgejoApp::query()->firstOrFail()->html_url)->toBe('https://93.184.216.34');
});

test('a token the server rejects is not saved', function () {
    fakeForgejoUser(401);
    actAsGitSourceUser($this->platformAdmin, $this->rootTeam);

    fillGitSourceForm(Livewire::test(GitSources::class))
        ->call('add')
        ->assertHasErrors('accessToken');

    expect(ForgejoApp::query()->count())->toBe(0);
});

test('server urls that point at internal addresses are refused', function (string $url) {
    Http::fake();
    actAsGitSourceUser($this->platformAdmin, $this->rootTeam);

    fillGitSourceForm(Livewire::test(GitSources::class), ['htmlUrl' => $url])
        ->call('add')
        ->assertHasErrors('htmlUrl');

    expect(ForgejoApp::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with(['http://127.0.0.1:3000', 'http://169.254.169.254', 'ftp://git.example.com', 'git.example.com']);

test('the form requires every field and a unique name', function () {
    ForgejoApp::factory()->create(['name' => 'company-forgejo']);
    actAsGitSourceUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(GitSources::class)->call('add')->assertHasErrors(['name', 'htmlUrl', 'accessToken']);
    fillGitSourceForm(Livewire::test(GitSources::class))->call('add')->assertHasErrors('name');
});

test('testing the connection refreshes the token user', function () {
    fakeForgejoUser();
    $source = ForgejoApp::factory()->create(['login' => 'old-login']);
    actAsGitSourceUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(GitSources::class)->call('test', $source->uuid)->assertDispatched('success');

    expect($source->fresh()->login)->toBe('coolify-bot');
});

test('a failing connection test shows an error', function () {
    fakeForgejoUser(500);
    $source = ForgejoApp::factory()->create();
    actAsGitSourceUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(GitSources::class)->call('test', $source->uuid)->assertDispatched('error');
});

test('the access token can be replaced after it is verified', function () {
    Http::fake(['93.184.216.34/api/v1/user' => Http::sequence()->push(['login' => 'coolify-bot'], 200)->push('', 401)]);
    $source = ForgejoApp::factory()->create(['access_token' => 'old-token']);
    actAsGitSourceUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(GitSources::class)
        ->call('startRotating', $source->uuid)
        ->set('newAccessToken', 'new-token')
        ->call('rotate')
        ->assertHasNoErrors()
        ->assertSet('rotatingUuid', null);

    expect($source->fresh()->access_token)->toBe('new-token');

    Livewire::test(GitSources::class)
        ->call('startRotating', $source->uuid)
        ->set('newAccessToken', 'bad-token')
        ->call('rotate')
        ->assertHasErrors('newAccessToken');

    expect($source->fresh()->access_token)->toBe('new-token');
});

test('a git source in use cannot be removed but an unused one can', function () {
    $used = ForgejoApp::factory()->create(['name' => 'used']);
    $unused = ForgejoApp::factory()->create(['name' => 'unused']);
    $project = Project::factory()->create(['team_id' => $this->tenant->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $this->tenant->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    Application::create([
        'name' => 'forgejo-app',
        'git_repository' => 'platform/api',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'source_id' => $used->id,
        'source_type' => ForgejoApp::class,
    ]);
    actAsGitSourceUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(GitSources::class)->call('remove', $used->uuid)->assertDispatched('error');
    Livewire::test(GitSources::class)->call('remove', $unused->uuid)->assertDispatched('success');

    expect(ForgejoApp::query()->pluck('name')->all())->toBe(['used']);
});

test('the page lists sources without exposing tokens', function () {
    ForgejoApp::factory()->create(['name' => 'visible-forgejo', 'access_token' => 'must-not-render']);
    actAsGitSourceUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(GitSources::class)
        ->assertSee('visible-forgejo')
        ->assertSee('https://93.184.216.34')
        ->assertDontSee('must-not-render');
});

test('the platform sources page shows the git sources panel to platform admins only', function () {
    actAsGitSourceUser($this->platformAdmin, $this->rootTeam);
    $this->get('/platform/sources')->assertOk()->assertSee('Add Forgejo server');

    actAsGitSourceUser($this->tenantOwner, $this->tenant);
    $this->get('/platform/sources')->assertForbidden();
});
