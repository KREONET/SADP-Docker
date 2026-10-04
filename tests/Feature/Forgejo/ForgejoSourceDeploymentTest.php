<?php

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\ForgejoApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Services\Platform\SourcePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0, 'forgejo_webhook_secret' => 'forgejo-test-secret']);
    Once::flush();
    Queue::fake();

    $this->source = ForgejoApp::factory()->create([
        'name' => 'company-forgejo',
        'html_url' => 'https://forge.example.com:3443/git',
        'access_token' => 'tok/en+secret',
    ]);
});

function forgejoSourcedApplication(ForgejoApp $source, string $repository = 'platform/api', string $branch = 'main', bool $functional = true): Application
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
        'name' => 'forgejo-sourced',
        'git_repository' => $repository,
        'git_branch' => $branch,
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'source_id' => $source->id,
        'source_type' => ForgejoApp::class,
    ]);
}

const FORGEJO_REPOSITORY_URL = 'https://oauth2:tok%2Fen%2Bsecret@forge.example.com:3443/git/platform/api.git';

test('the clone command uses the token of the source, the server port and the path prefix', function () {
    $application = forgejoSourcedApplication($this->source);

    $result = $application->generateGitImportCommands('deploy-uuid', exec_in_docker: false);

    expect($result['fullRepoUrl'])->toBe(FORGEJO_REPOSITORY_URL)
        ->and($result['branch'])->toBe('main')
        ->and($result['commands'])
        ->toContain('clone --depth=1')
        ->toContain("-b 'main'")
        ->toContain(escapeshellarg(FORGEJO_REPOSITORY_URL))
        // Same-host rewrite so submodules authenticate without writing the token to disk.
        ->toContain('insteadOf=https://forge.example.com:3443/git/');
});

test('the clone runs inside the helper container during a deployment', function () {
    $application = forgejoSourcedApplication($this->source);

    $result = $application->generateGitImportCommands('deploy-uuid');

    expect($result['commands'])->toContain('docker exec deploy-uuid')->toContain('clone --depth=1');
});

test('the generated clone and ls-remote commands are valid shell and keep the token out of the deployment log', function () {
    $application = forgejoSourcedApplication($this->source);

    $clone = $application->generateGitImportCommands('deploy-uuid', exec_in_docker: false)['commands'];
    $lsRemote = $application->generateGitLsRemoteCommands('deploy-uuid', exec_in_docker: false)['commands'];

    foreach ([$clone, $lsRemote] as $command) {
        exec('bash -n -c '.escapeshellarg($command).' 2>&1', $output, $exitCode);
        expect($exitCode)->toBe(0, implode("\n", $output));
        expect(remove_iip($command))->not->toContain('tok%2Fen%2Bsecret');
    }
    expect($lsRemote)->toContain('git ls-remote')->toContain(escapeshellarg(FORGEJO_REPOSITORY_URL));
});

test('a pull request preview fetches the pull ref of the repository', function () {
    $application = forgejoSourcedApplication($this->source);

    $result = $application->generateGitImportCommands('deploy-uuid', 7, exec_in_docker: false);

    expect($result['branch'])->toBe('pull/7/head:pr-7-coolify')
        ->and($result['commands'])->toContain("fetch origin 'pull/7/head:pr-7-coolify'");
});

test('a repository path cannot break out of the clone command', function () {
    $application = forgejoSourcedApplication($this->source, "platform/api'; touch /tmp/pwned; echo '");

    $command = $application->generateGitImportCommands('deploy-uuid', exec_in_docker: false)['commands'];
    // Run the command with git, cd and touch replaced by functions that report what the shell would execute.
    $script = 'git() { printf "GIT-ARGS:%s\n" "$*"; }; cd() { :; }; touch() { echo INJECTED; }; '.$command;
    exec('bash -c '.escapeshellarg($script).' 2>&1', $output, $exitCode);
    $output = implode("\n", $output);

    expect($exitCode)->toBe(0)
        ->and($output)->not->toContain('INJECTED')
        // The whole path stays one argument of git clone.
        ->and($output)->toContain("platform/api'; touch /tmp/pwned; echo '.git");
});

test('branch and commit links follow the forgejo url scheme', function () {
    $application = forgejoSourcedApplication($this->source);

    expect($application->git_branch_location)->toBe('https://forge.example.com:3443/git/platform/api/src/branch/main/')
        ->and($application->gitCommitLink('abc123'))->toBe('https://forge.example.com:3443/git/platform/api/commit/abc123');
});

test('the repository url of a forgejo sourced application resolves against the server', function () {
    $application = forgejoSourcedApplication($this->source);

    expect(SourcePolicy::applicationRepositoryUrl($application))->toBe('https://forge.example.com:3443/git/platform/api');
});

test('registered forgejo servers stay allowed while the allow-list is enforced', function () {
    InstanceSettings::get()->update(['is_source_allowlist_enabled' => true, 'allowed_git_sources' => 'github.com/acme']);
    $policy = SourcePolicy::current();
    $application = forgejoSourcedApplication($this->source);

    expect($policy->isGitRepositoryAllowed('https://forge.example.com:3443/git/platform/api'))->toBeTrue()
        ->and($policy->isGitRepositoryAllowed('https://github.com/acme/app'))->toBeTrue()
        ->and($policy->isGitRepositoryAllowed('https://other.example.com/platform/api'))->toBeFalse()
        ->and(fn () => $policy->assertApplicationAllowed($application))->not->toThrow(Exception::class);
});

test('removing a server from the instance removes its allowance', function () {
    InstanceSettings::get()->update(['is_source_allowlist_enabled' => true, 'allowed_git_sources' => 'github.com/acme']);
    $this->source->delete();

    expect(SourcePolicy::current()->isGitRepositoryAllowed('https://forge.example.com:3443/git/platform/api'))->toBeFalse();
});

test('a signed push redeploys applications created from a forgejo source', function () {
    $application = forgejoSourcedApplication($this->source);
    $payload = json_encode([
        'ref' => 'refs/heads/main',
        'after' => 'abc1234def',
        'commits' => [['message' => 'feat: add endpoint', 'added' => ['src/api.php'], 'removed' => [], 'modified' => []]],
        'repository' => [
            'full_name' => 'platform/api',
            'html_url' => 'https://forge.example.com:3443/git/platform/api',
            'clone_url' => 'https://forge.example.com:3443/git/platform/api.git',
            'ssh_url' => 'git@forge.example.com:platform/api.git',
        ],
    ]);

    $response = $this->call('POST', '/webhooks/source/forgejo/events', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X-Forgejo-Event' => 'push',
        'HTTP_X-Forgejo-Signature' => hash_hmac('sha256', $payload, 'forgejo-test-secret'),
    ], $payload);

    $response->assertOk();
    expect($response->getContent())->toContain('Deployment queued')
        ->and(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->exists())->toBeTrue();
});
