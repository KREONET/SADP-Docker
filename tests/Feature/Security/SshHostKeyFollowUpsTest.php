<?php

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Actions\Development\SeedDevelopmentQemuServer;
use App\Exceptions\SshHostKeyChangedException;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\ServerConnectionCheckJob;
use App\Livewire\Server\Security\HostKey;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\Ssh\KnownHosts;
use App\Traits\SshRetryable;
use Database\Seeders\PrivateKeySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const FOLLOW_UP_HOST_KEY_CHANGED_OUTPUT = "@    WARNING: REMOTE HOST IDENTIFICATION HAS CHANGED!     @\n"
    ."Host key for coolify-x has changed and you have requested strict checking.\n"
    .'Host key verification failed.';

class HostKeyFollowUpDeploymentJob extends ApplicationDeploymentJob
{
    public function __construct() {}
}

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config([
        'app.maintenance.store' => 'array',
        'cache.default' => 'array',
        'constants.ssh.mux_enabled' => false,
        'constants.ssh.max_retries' => 3,
        'constants.ssh.retry_base_delay' => 0,
        'security.ssh.known_hosts_directory' => sys_get_temp_dir().'/coolify-known-hosts-follow-up-'.uniqid(),
    ]);
    Cache::flush();
    Storage::fake('ssh-keys');
    Notification::fake();
    $this->withoutDefer();

    $this->rootTeam = Team::factory()->create(['id' => 0]);
    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'owner']);
    $this->admin->teams()->attach($this->rootTeam, ['role' => 'admin']);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'name' => 'follow-up-host',
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'user' => 'root',
    ]);
});

afterEach(function () {
    File::deleteDirectory(KnownHosts::directory());
});

/**
 * @return array{0: HostKeyFollowUpDeploymentJob, 1: ReflectionClass}
 */
function hostKeyFollowUpDeploymentJob(Server $server, bool $useBuildServer = false): array
{
    $project = Project::create(['name' => 'Host Key Project', 'team_id' => $server->team_id]);
    $environment = Environment::where('project_id', $project->id)->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'build_pack' => 'dockerfile',
    ]);
    $queue = ApplicationDeploymentQueue::create([
        'deployment_uuid' => 'host-key-deployment',
        'application_id' => $application->id,
        'server_id' => $server->id,
        'status' => 'in_progress',
    ]);

    $job = new HostKeyFollowUpDeploymentJob;
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);

    foreach ([
        'application' => $application->fresh(),
        'application_deployment_queue' => $queue,
        'server' => $server,
        'mainServer' => $server,
        'destination' => StandaloneDocker::forceCreate([
            'name' => 'host-key-network',
            'network' => 'host-key-network',
            'server_id' => $server->id,
        ]),
        'use_build_server' => $useBuildServer,
        'build_pack' => 'dockerfile',
        'pull_request_id' => 0,
        'basedir' => '/artifacts/host-key-deployment',
        'deployment_uuid' => 'host-key-deployment',
        'saved_outputs' => new Collection,
    ] as $property => $value) {
        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setValue($job, $value);
    }

    return [$job, $reflection];
}

/**
 * Fakes the build server and returns the commands that ran on it.
 *
 * @return ArrayObject<int, string>
 */
function fakeHostKeyFollowUpBuildServer(string $dockerConfig, ?callable $dockerRun = null): ArrayObject
{
    $commands = new ArrayObject;
    Process::fake(function (PendingProcess $process) use ($commands, $dockerConfig, $dockerRun) {
        $commands[] = $process->command;
        if ($dockerRun && str_contains($process->command, 'docker run -d')) {
            return $dockerRun();
        }
        if (str_contains($process->command, 'echo $HOME')) {
            return Process::result(output: '/home/builder');
        }
        if (str_contains($process->command, 'config.json && echo')) {
            return Process::result(output: $dockerConfig);
        }

        return Process::result();
    });

    return $commands;
}

function invokePrepareBuilderImage(object $job, ReflectionClass $reflection): void
{
    $reflection->getMethod('prepare_builder_image')->invoke($job);
}

describe('git known_hosts persistence on the build server', function () {
    it('creates the git known_hosts directory and mounts it into the helper container', function (bool $useBuildServer, string $dockerConfig) {
        [$job, $reflection] = hostKeyFollowUpDeploymentJob($this->server, $useBuildServer);
        $commands = fakeHostKeyFollowUpBuildServer($dockerConfig);

        invokePrepareBuilderImage($job, $reflection);

        $mkdir = collect($commands)->first(fn (string $command): bool => str_contains($command, 'mkdir -p /data/coolify/ssh/git'));
        $dockerRun = collect($commands)->first(fn (string $command): bool => str_contains($command, 'docker run -d'));
        $mkdirIndex = collect($commands)->search($mkdir);
        $dockerRunIndex = collect($commands)->search($dockerRun);

        expect($mkdir)->not->toBeNull()
            ->and($dockerRun)->toContain('-v /data/coolify/ssh/git:/data/coolify/ssh/git ')
            ->and($mkdirIndex)->toBeLessThan($dockerRunIndex);
    })->with([
        'build server' => [true, 'OK'],
        'docker config present' => [false, 'OK'],
        'no docker config' => [false, 'NOK'],
    ]);

    it('creates the directory with sudo for non-root users', function () {
        $this->server->update(['user' => 'builder']);
        [$job, $reflection] = hostKeyFollowUpDeploymentJob($this->server->fresh());
        $commands = fakeHostKeyFollowUpBuildServer('NOK');

        invokePrepareBuilderImage($job, $reflection);

        expect(collect($commands)->contains(fn (string $command): bool => str_contains($command, 'sudo mkdir -p /data/coolify/ssh/git')))->toBeTrue()
            ->and(collect($commands)->contains(fn (string $command): bool => str_contains($command, 'sudo docker run -d')
                && str_contains($command, '-v /data/coolify/ssh/git:/data/coolify/ssh/git ')))->toBeTrue();
    });

    it('mounts the directory of a configured git known_hosts file', function () {
        config(['security.ssh.git_known_hosts_file' => '/srv/coolify/git-hosts/known_hosts']);
        [$job, $reflection] = hostKeyFollowUpDeploymentJob($this->server);
        $commands = fakeHostKeyFollowUpBuildServer('NOK');

        invokePrepareBuilderImage($job, $reflection);

        expect(collect($commands)->contains(fn (string $command): bool => str_contains($command, 'mkdir -p /srv/coolify/git-hosts')))->toBeTrue()
            ->and(collect($commands)->contains(fn (string $command): bool => str_contains($command, '-v /srv/coolify/git-hosts:/srv/coolify/git-hosts ')))->toBeTrue();
    });
});

describe('changed host keys', function () {
    it('does not treat a failed host key verification as a retryable ssh error', function () {
        $checker = new class
        {
            use SshRetryable;

            public function check(string $error): bool
            {
                return $this->isRetryableSshError($error);
            }
        };

        expect($checker->check('Host key verification failed.'))->toBeFalse()
            ->and($checker->check('kex_exchange_identification: Connection reset by peer'))->toBeTrue();
    });

    it('stops a deployment command on a changed host key without retrying', function () {
        [$job, $reflection] = hostKeyFollowUpDeploymentJob($this->server);
        $commands = fakeHostKeyFollowUpBuildServer('NOK', fn () => Process::result(errorOutput: FOLLOW_UP_HOST_KEY_CHANGED_OUTPUT, exitCode: 255));

        expect(fn () => invokePrepareBuilderImage($job, $reflection))->toThrow(SshHostKeyChangedException::class);

        expect(collect($commands)->filter(fn (string $command): bool => str_contains($command, 'docker run -d')))->toHaveCount(1)
            ->and(AuditEvent::query()->where('event', 'server.ssh_host_key.changed')->count())->toBe(1);
    });

    it('reports a changed host key from a remote process task', function () {
        Queue::fake();
        Process::fake(fn () => Process::result(errorOutput: FOLLOW_UP_HOST_KEY_CHANGED_OUTPUT, exitCode: 255));
        $activity = remote_process(['echo ok'], $this->server);

        $run = new RunRemoteProcess(activity: Activity::query()->findOrFail($activity->id));

        expect(fn () => $run())->toThrow(SshHostKeyChangedException::class);
        expect(AuditEvent::query()->where('event', 'server.ssh_host_key.changed')->count())->toBe(1);
    });

    it('keeps the plain error for other remote process failures', function () {
        Queue::fake();
        Process::fake(fn () => Process::result(errorOutput: 'Host key verification failed.', exitCode: 128));
        $activity = remote_process(['git ls-remote git@example.com:a/b.git'], $this->server);

        $run = new RunRemoteProcess(activity: Activity::query()->findOrFail($activity->id));

        $caught = null;
        try {
            $run();
        } catch (Throwable $e) {
            $caught = $e;
        }

        expect($caught)->toBeInstanceOf(RuntimeException::class)
            ->not->toBeInstanceOf(SshHostKeyChangedException::class);
    });

    it('marks the server unreachable with a host key error in the connection check', function () {
        Process::fake(fn () => Process::result(errorOutput: FOLLOW_UP_HOST_KEY_CHANGED_OUTPUT, exitCode: 255));

        (new ServerConnectionCheckJob($this->server, disableMux: false))->handle();

        expect($this->server->settings->fresh()->is_reachable)->toBeFalse()
            ->and(AuditEvent::query()->where('event', 'server.ssh_host_key.changed')->count())->toBe(1);
    });
});

describe('development VMs', function () {
    it('forgets the trusted host key when a development VM is seeded again', function () {
        config(['app.env' => 'local']);
        $this->seed(PrivateKeySeeder::class);
        $server = SeedDevelopmentQemuServer::run('ubuntu-root');
        ServerSetting::query()->where('server_id', $server->id)->update([
            'ssh_host_keys' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHbTkfh0p6Yv8Q7SxS7r4M0eEhkS3ZcqvJQ3Hn2qzH3A',
            'ssh_host_key_fingerprint' => 'SHA256:previous',
            'ssh_host_key_trusted_at' => now(),
        ]);
        Process::fake();

        SeedDevelopmentQemuServer::run('ubuntu-root');

        expect(KnownHosts::trustedFingerprints($server))->toBe([])
            ->and(ServerSetting::query()->where('server_id', $server->id)->value('ssh_host_keys'))->toBeNull();
    });
});

describe('server security page', function () {
    it('shows the SSH host key section to a platform administrator', function () {
        config(['security.two_factor.required_for' => 'none']);
        Team::query()->update(['show_boarding' => false]);
        Cache::flush();

        $this->get(route('server.security.terminal-access', ['server_uuid' => $this->server->uuid]))
            ->assertSuccessful()
            ->assertSeeLivewire(HostKey::class)
            ->assertSee(__('SSH host key'));
    });
});
