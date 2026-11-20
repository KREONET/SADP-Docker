<?php

use App\Exceptions\DisallowedSourceException;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Services\Platform\SourcePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    $this->settings = InstanceSettings::forceCreate(['id' => 0]);
});

function allowlistApplication(array $overrides = []): Application
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();

    return Application::create(array_merge([
        'name' => 'allowlist-app',
        'git_repository' => 'https://git.example.com/platform/api.git',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ], $overrides));
}

test('git repository urls normalize to host and path', function (string $url, ?string $expected) {
    expect(SourcePolicy::normalizeGitRepository($url))->toBe($expected);
})->with([
    ['https://git.example.com/Platform/API.git', 'git.example.com/platform/api'],
    ['https://user:token@git.example.com:3000/platform/api', 'git.example.com/platform/api'],
    ['ssh://git@git.example.com:2222/platform/api.git', 'git.example.com/platform/api'],
    ['git@git.example.com:platform/api.git', 'git.example.com/platform/api'],
    ['git.example.com/platform', 'git.example.com/platform'],
    ['https://git.example.com/', 'git.example.com'],
    ['https://git.example.com/../etc', null],
    ['', null],
]);

test('docker images normalize to registry and repository', function (string $image, ?string $expected) {
    expect(SourcePolicy::normalizeDockerImage($image))->toBe($expected);
})->with([
    ['nginx', 'docker.io/library/nginx'],
    ['nginx:1.27', 'docker.io/library/nginx'],
    ['bitnami/redis:7', 'docker.io/bitnami/redis'],
    ['registry.example.com:5000/team/app:1.0', 'registry.example.com/team/app'],
    ['ghcr.io/org/app@sha256:abc', 'ghcr.io/org/app'],
    ['localhost/app', 'localhost/app'],
]);

test('every source is allowed while the allow-list is disabled', function () {
    $policy = SourcePolicy::current();

    expect($policy->isGitRepositoryAllowed('https://github.com/anyone/anything'))->toBeTrue()
        ->and($policy->isDockerImageAllowed('nginx'))->toBeTrue();
});

test('the allow-list matches prefixes on path segment boundaries', function () {
    $this->settings->update([
        'is_source_allowlist_enabled' => true,
        'allowed_git_sources' => "https://git.example.com/platform/\n# comment\nforge.example.org",
        'allowed_docker_registries' => 'registry.example.com, docker.io/library',
    ]);
    $policy = SourcePolicy::current();

    expect($policy->isGitRepositoryAllowed('https://git.example.com/platform/api.git'))->toBeTrue()
        ->and($policy->isGitRepositoryAllowed('git@git.example.com:platform/api.git'))->toBeTrue()
        ->and($policy->isGitRepositoryAllowed('https://forge.example.org/any/repo'))->toBeTrue()
        ->and($policy->isGitRepositoryAllowed('https://git.example.com/platformx/api'))->toBeFalse()
        ->and($policy->isGitRepositoryAllowed('https://git.example.com.evil.test/platform/api'))->toBeFalse()
        ->and($policy->isGitRepositoryAllowed('https://github.com/platform/api'))->toBeFalse()
        ->and($policy->isDockerImageAllowed('registry.example.com/team/app:1'))->toBeTrue()
        ->and($policy->isDockerImageAllowed('nginx:latest'))->toBeTrue()
        ->and($policy->isDockerImageAllowed('bitnami/redis'))->toBeFalse();
});

test('an enabled but empty allow-list denies every source', function () {
    $this->settings->update(['is_source_allowlist_enabled' => true]);

    expect(SourcePolicy::current()->isGitRepositoryAllowed('https://git.example.com/platform/api'))->toBeFalse()
        ->and(SourcePolicy::current()->isDockerImageAllowed('nginx'))->toBeFalse();
});

test('applications cannot be saved with a disallowed repository', function () {
    $this->settings->update([
        'is_source_allowlist_enabled' => true,
        'allowed_git_sources' => 'git.example.com',
    ]);

    $application = allowlistApplication();
    expect($application->exists)->toBeTrue();

    expect(fn () => $application->update(['git_repository' => 'https://github.com/someone/else']))
        ->toThrow(DisallowedSourceException::class);
    expect($application->fresh()->git_repository)->toBe('https://git.example.com/platform/api.git');

    expect(fn () => allowlistApplication(['git_repository' => 'git@github.com:someone/else.git']))
        ->toThrow(DisallowedSourceException::class);
});

test('docker image applications must come from an allowed registry', function () {
    $this->settings->update([
        'is_source_allowlist_enabled' => true,
        'allowed_git_sources' => 'git.example.com',
        'allowed_docker_registries' => 'registry.example.com',
    ]);

    expect(allowlistApplication([
        'build_pack' => 'dockerimage',
        'git_repository' => 'coollabsio/coolify',
        'docker_registry_image_name' => 'registry.example.com/team/app',
        'docker_registry_image_tag' => '1.0',
    ])->exists)->toBeTrue();

    expect(fn () => allowlistApplication([
        'build_pack' => 'dockerimage',
        'git_repository' => 'coollabsio/coolify',
        'docker_registry_image_name' => 'nginx',
        'docker_registry_image_tag' => 'latest',
    ]))->toThrow(DisallowedSourceException::class);
});
