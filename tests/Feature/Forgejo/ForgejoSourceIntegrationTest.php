<?php

use App\Livewire\Project\New\Select;
use App\Models\Application;
use App\Models\Environment;
use App\Models\ForgejoApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Forgejo\ForgejoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();
    Team::find(0) ?? Team::factory()->create(['id' => 0, 'name' => 'Root Team']);

    $this->team = Team::factory()->create(['name' => 'Tenant']);
    $this->user = User::factory()->create(['name' => 'Tenant Dev']);
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    Server::factory()->create(['team_id' => $this->team->id]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('the new resource page offers forgejo once a platform admin registered a server', function () {
    $ids = fn () => collect((new Select)->loadServices()['gitBasedApplications'])->pluck('id')->all();

    expect($ids())->not->toContain('private-forgejo-app');

    ForgejoApp::factory()->create();
    expect($ids())->toContain('private-forgejo-app');
});

test('platform admins see the forgejo option before any server exists so they can add one', function () {
    $admin = User::factory()->create(['name' => 'Platform Admin']);
    Team::find(0)->members()->attach($admin->id, ['role' => 'admin']);
    $this->actingAs($admin);
    session(['currentTeam' => Team::find(0)]);

    expect(collect((new Select)->loadServices()['gitBasedApplications'])->pluck('id')->all())->toContain('private-forgejo-app');
});

test('the create page renders the forgejo repository flow for the forgejo type', function () {
    ForgejoApp::factory()->create(['name' => 'company-forgejo']);
    $destination = Server::query()->firstOrFail()->standaloneDockers()->firstOrFail();

    $this->get(route('project.resource.create', ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid, 'type' => 'private-forgejo-app', 'destination' => $destination->uuid]))
        ->assertOk()
        ->assertSee('company-forgejo')
        ->assertSee('Choose Forgejo server');
});

test('the repository client maps forgejo repositories and reports whether more pages exist', function () {
    $items = array_map(fn (int $i) => ['id' => $i, 'name' => "r{$i}", 'full_name' => "org/r{$i}", 'default_branch' => $i === 1 ? '' : 'trunk', 'private' => $i % 2 === 0, 'html_url' => "https://93.184.216.34/org/r{$i}", 'ignored' => 'x'], range(1, 50));
    Http::fake(['93.184.216.34/api/v1/user/repos*' => Http::response($items, 200)]);

    $page = ForgejoClient::forSource(ForgejoApp::factory()->create())->repositories();

    expect($page['has_more'])->toBeTrue()
        ->and($page['repositories'])->toHaveCount(50)
        ->and($page['repositories'][0])->toBe(['id' => 1, 'name' => 'r1', 'full_name' => 'org/r1', 'default_branch' => 'main', 'private' => false, 'html_url' => 'https://93.184.216.34/org/r1'])
        ->and($page['repositories'][1]['default_branch'])->toBe('trunk');
});

test('the client sends the token and escapes repository names in the branches request', function () {
    Http::fake(['93.184.216.34/api/v1/repos/*' => Http::response([['name' => 'main', 'commit' => ['id' => 'abc']], ['name' => '']], 200)]);

    $branches = ForgejoClient::forSource(ForgejoApp::factory()->create(['access_token' => 'tkn']))->branches('own er', 'repo/../x');

    expect($branches)->toBe([['name' => 'main']]);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'token tkn')
        && str_starts_with($request->url(), 'https://93.184.216.34/api/v1/repos/own%20er/repo%2F..%2Fx/branches'));
});

test('the client explains authorization and lookup failures', function (int $status, string $message) {
    Http::fake(['93.184.216.34/api/v1/user/repos*' => Http::response('', $status)]);

    expect(fn () => ForgejoClient::forSource(ForgejoApp::factory()->create())->repositories())
        ->toThrow(RuntimeException::class, $message);
})->with([
    [401, 'access token is invalid'],
    [403, 'access token is invalid'],
    [404, 'Check the server URL'],
    [502, 'HTTP 502'],
]);

test('forgejo servers are stored encrypted and never serialized with their token', function () {
    $source = ForgejoApp::factory()->create(['access_token' => 'very-secret']);

    expect(json_encode($source))->not->toContain('very-secret')
        ->and(DB::table('forgejo_apps')->where('id', $source->id)->value('access_token'))->not->toBe('very-secret')
        ->and($source->fresh()->access_token)->toBe('very-secret');
});

test('a server in use cannot be deleted from the model either', function () {
    $source = ForgejoApp::factory()->create();
    $server = Server::query()->firstOrFail();
    $destination = $server->standaloneDockers()->firstOrFail();
    Application::create([
        'name' => 'forgejo-app', 'git_repository' => 'platform/api', 'git_branch' => 'main', 'build_pack' => 'nixpacks', 'ports_exposes' => '3000',
        'environment_id' => $this->environment->id, 'destination_id' => $destination->id, 'destination_type' => $destination->getMorphClass(),
        'source_id' => $source->id, 'source_type' => ForgejoApp::class,
    ]);

    expect(fn () => $source->delete())->toThrow(RuntimeException::class, 'being used by an application');
});
