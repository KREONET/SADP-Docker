<?php

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0, 'forgejo_webhook_secret' => 'forgejo-test-secret']);
    Queue::fake();
});

function forgejoApplication(string $repository = 'https://git.example.com/platform/api.git', string $branch = 'main', bool $functional = true): Application
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    if ($functional) {
        $server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    }
    $destination = $server->standaloneDockers()->firstOrFail();

    return Application::create([
        'name' => 'forgejo-app',
        'git_repository' => $repository,
        'git_branch' => $branch,
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

function sendForgejoPush(TestCase $test, array $overrides = [], ?string $secret = 'forgejo-test-secret', string $event = 'push'): TestResponse
{
    $payload = json_encode(array_replace_recursive([
        'ref' => 'refs/heads/main',
        'after' => 'abc1234def',
        'commits' => [['message' => 'feat: add endpoint', 'added' => ['src/api.php'], 'removed' => [], 'modified' => []]],
        'repository' => [
            'full_name' => 'platform/api',
            'html_url' => 'https://git.example.com/platform/api',
            'clone_url' => 'https://git.example.com/platform/api.git',
            'ssh_url' => 'git@git.example.com:platform/api.git',
        ],
    ], $overrides));

    return $test->call('POST', '/webhooks/source/forgejo/events', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X-Forgejo-Event' => $event,
        'HTTP_X-Forgejo-Signature' => $secret === null ? '' : hash_hmac('sha256', $payload, $secret),
    ], $payload);
}

test('a signed push deploys every application that builds the repository and branch', function () {
    $https = forgejoApplication('https://git.example.com/platform/api.git');
    $ssh = forgejoApplication('git@git.example.com:Platform/API.git');
    $otherBranch = forgejoApplication('https://git.example.com/platform/api.git', 'develop');
    $otherRepository = forgejoApplication('https://git.example.com/platform/web.git');

    $response = sendForgejoPush($this);

    $response->assertOk();
    expect($response->getContent())->toContain('Deployment queued');
    expect(ApplicationDeploymentQueue::query()->where('application_id', $https->id)->exists())->toBeTrue()
        ->and(ApplicationDeploymentQueue::query()->where('application_id', $ssh->id)->exists())->toBeTrue()
        ->and(ApplicationDeploymentQueue::query()->where('application_id', $otherBranch->id)->exists())->toBeFalse()
        ->and(ApplicationDeploymentQueue::query()->where('application_id', $otherRepository->id)->exists())->toBeFalse();
});

test('a push to the same path on another host deploys nothing', function () {
    $application = forgejoApplication('https://github.com/platform/api.git');

    sendForgejoPush($this)->assertOk();

    expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->exists())->toBeFalse();
});

test('an invalid or missing signature is rejected without deploying', function (?string $secret) {
    $application = forgejoApplication();

    sendForgejoPush($this, secret: $secret)->assertStatus(401);

    expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->exists())->toBeFalse();
})->with(['wrong-secret', null]);

test('the webhook is disabled until a secret is configured', function () {
    InstanceSettings::get()->update(['forgejo_webhook_secret' => null]);

    sendForgejoPush($this)->assertNotFound();
});

test('ping, unsupported events and branch deletions do nothing', function () {
    $application = forgejoApplication();

    expect(sendForgejoPush($this, event: 'ping')->getContent())->toBe('pong');
    expect(sendForgejoPush($this, event: 'issues')->getContent())->toContain('not supported');
    expect(sendForgejoPush($this, ['after' => '0000000000000000000000000000000000000000'])->getContent())->toContain('Branch deleted');
    expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->exists())->toBeFalse();
});

test('skip ci commits and disabled auto deploy are respected', function () {
    $application = forgejoApplication();

    expect(sendForgejoPush($this, ['commits' => [['message' => 'docs: typo [skip ci]']]])->getContent())->toContain('skip ci');

    $application->settings->update(['is_auto_deploy_enabled' => false]);
    expect(sendForgejoPush($this)->getContent())->toContain('Automatic deployments are disabled');

    expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->exists())->toBeFalse();
});
