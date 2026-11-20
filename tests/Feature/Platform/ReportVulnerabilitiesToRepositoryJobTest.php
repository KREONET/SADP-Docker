<?php

use App\Jobs\ReportVulnerabilitiesToRepositoryJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\VulnerabilityScan;
use App\Rules\SafeExternalUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Http::macro('GitSource', fn (string $url) => Http::withOptions(SafeExternalUrl::httpClientOptions(
        $url,
        resolver: fn (string $host): array => ['93.184.216.34'],
    )));
    InstanceSettings::forceCreate([
        'id' => 0,
        'forgejo_base_url' => 'https://git.example.com',
        'forgejo_bot_token' => 'bot-token',
    ]);

    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $application = Application::create([
        'name' => 'reported-app',
        'git_repository' => 'git@git.example.com:platform/api.git',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $this->scan = VulnerabilityScan::create([
        'application_id' => $application->id,
        'deployment_uuid' => 'deploy123',
        'repository' => $application->git_repository,
        'branch' => 'main',
        'commit' => 'abc1234',
        'status' => VulnerabilityScan::STATUS_BLOCKED,
        'critical_count' => 1,
        'findings' => [[
            'target' => 'package-lock.json',
            'id' => 'CVE-2024-0001',
            'package' => 'lodash',
            'installed_version' => '4.17.20',
            'fixed_version' => '4.17.21',
            'severity' => 'CRITICAL',
            'title' => 'Prototype | pollution',
            'url' => 'https://avd.aquasec.com/nvd/cve-2024-0001',
        ]],
    ]);
});

test('the bot opens an issue in the forgejo repository', function () {
    Http::fake([
        'git.example.com/api/v1/repos/platform/api/issues?*' => Http::response([]),
        'git.example.com/api/v1/repos/platform/api/issues' => Http::response(['number' => 7, 'html_url' => 'https://git.example.com/platform/api/issues/7'], 201),
    ]);

    (new ReportVulnerabilitiesToRepositoryJob($this->scan))->handle();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://git.example.com/api/v1/repos/platform/api/issues'
        && $request->hasHeader('Authorization', 'token bot-token')
        && str_contains($request['title'], 'main')
        && str_contains($request['body'], 'CVE-2024-0001')
        && str_contains($request['body'], 'Prototype \| pollution')
        && str_contains($request['body'], '배포가 중단되었습니다')
        && str_contains($request['body'], '| 1 / 0 / 0 / 0 |'));
    expect($this->scan->fresh()->report_url)->toBe('https://git.example.com/platform/api/issues/7');
});

test('an open issue with the same title receives a comment instead of a duplicate', function () {
    $title = ReportVulnerabilitiesToRepositoryJob::issueTitle($this->scan);
    Http::fake([
        'git.example.com/api/v1/repos/platform/api/issues?*' => Http::response([['number' => 3, 'title' => $title, 'html_url' => 'https://git.example.com/platform/api/issues/3']]),
        'git.example.com/api/v1/repos/platform/api/issues/3/comments' => Http::response(['html_url' => 'https://git.example.com/platform/api/issues/3#issuecomment-9'], 201),
    ]);

    (new ReportVulnerabilitiesToRepositoryJob($this->scan))->handle();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/issues/3/comments'));
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/repos/platform/api/issues'));
    expect($this->scan->fresh()->report_url)->toContain('issuecomment-9');
});

test('repositories outside the configured forgejo instance are not reported', function () {
    Http::fake();
    $this->scan->update(['repository' => 'https://github.com/platform/api.git']);

    (new ReportVulnerabilitiesToRepositoryJob($this->scan))->handle();

    Http::assertNothingSent();
});

test('nothing is reported without a bot token', function () {
    Http::fake();
    InstanceSettings::get()->update(['forgejo_bot_token' => null]);

    (new ReportVulnerabilitiesToRepositoryJob($this->scan))->handle();

    Http::assertNothingSent();
});
