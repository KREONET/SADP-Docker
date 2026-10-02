<?php

use App\Jobs\CollectContainerLogsJob;
use App\Jobs\ContainerLogCollectionManagerJob;
use App\Models\InstanceSettings;
use App\Models\LogEntry;
use App\Models\Server;
use App\Models\Team;
use App\Services\Logging\ContainerLogCollector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate([
        'id' => 0,
        'is_log_collection_enabled' => true,
        'is_container_log_collection_enabled' => true,
    ]);
    Once::flush();

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
});

function dockerPsLine(string $name, string $labels): string
{
    return json_encode(['ID' => md5($name), 'Names' => $name, 'Labels' => $labels]);
}

/**
 * @param  array<string, string>  $logsByContainer
 * @return array{0: ContainerLogCollector, 1: ArrayObject<int, string>}
 */
function fakeCollector(string $dockerPs, array $logsByContainer): array
{
    $commands = new ArrayObject;
    $collector = new ContainerLogCollector(function (array $commandLines, Server $server) use ($commands, $dockerPs, $logsByContainer): ?string {
        $command = implode("\n", $commandLines);
        $commands->append($command);

        if (str_starts_with($command, 'docker ps')) {
            return $dockerPs;
        }

        foreach ($logsByContainer as $container => $output) {
            if (str_contains($command, escapeshellarg($container))) {
                return $output;
            }
        }

        return null;
    });

    return [$collector, $commands];
}

test('new lines of running application and service containers are stored', function () {
    $dockerPs = implode("\n", [
        dockerPsLine('web-abc', 'coolify.managed=true,coolify.type=application,coolify.applicationUuid=app-uuid,coolify.resourceName=shop'),
        dockerPsLine('db-xyz', 'coolify.managed=true,coolify.type=service,coolify.serviceUuid=svc-uuid,coolify.resourceName=analytics'),
        dockerPsLine('coolify-proxy', 'coolify.managed=true,coolify.proxy=true'),
    ]);
    [$collector, $commands] = fakeCollector($dockerPs, [
        'web-abc' => "2026-10-02T09:30:00.100000000Z GET / 200\n2026-10-02T09:30:01.000000000Z Error: upstream timeout",
        'db-xyz' => '2026-10-02T09:30:02.000000000Z ready',
    ]);

    expect($collector->collect($this->server))->toBe(3);

    $entries = LogEntry::query()->orderBy('logged_at')->get();
    expect($entries)->toHaveCount(3)
        ->and($entries[0])->toMatchArray([
            'source' => 'container',
            'level' => 'info',
            'message' => 'GET / 200',
            'team_id' => $this->team->id,
            'server_id' => $this->server->id,
            'resource_type' => 'application',
            'resource_uuid' => 'app-uuid',
            'resource_name' => 'shop',
            'container_name' => 'web-abc',
        ])
        ->and($entries[1]->level)->toBe('error')
        ->and($entries[2]->resource_type)->toBe('service')
        ->and(collect($commands)->filter(fn (string $command) => str_contains($command, 'coolify-proxy')))->toBeEmpty();
});

test('the next run continues after the last stored line without duplicates', function () {
    $dockerPs = dockerPsLine('web-abc', 'coolify.type=application,coolify.applicationUuid=app-uuid');
    $output = "2026-10-02T09:30:00.100000000Z first\n2026-10-02T09:30:01.000000000Z second";

    [$collector] = fakeCollector($dockerPs, ['web-abc' => $output]);
    $collector->collect($this->server);

    [$collector, $commands] = fakeCollector($dockerPs, ['web-abc' => $output."\n2026-10-02T09:30:05.000000000Z third"]);
    expect($collector->collect($this->server))->toBe(1)
        ->and(LogEntry::query()->orderBy('logged_at')->pluck('message')->all())->toBe(['first', 'second', 'third'])
        ->and($commands[1])->toContain("--since '2026-10-02T09:30:01.000000Z'");
});

test('the first run only reads the recent lookback window', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-02T09:30:00Z'));
    [$collector, $commands] = fakeCollector(dockerPsLine('web-abc', 'coolify.type=application'), ['web-abc' => '']);

    $collector->collect($this->server);

    expect($commands[1])->toBe("docker logs --timestamps --since '2026-10-02T09:25:00.000000Z' --tail 500 'web-abc' 2>&1");
});

test('container names are shell-escaped', function () {
    $command = ContainerLogCollector::logsCommand("web'; rm -rf /; echo '", now())[0];

    expect($command)->toContain(escapeshellarg("web'; rm -rf /; echo '"))
        ->and($command)->not->toContain("'web'; rm");
});

test('collection commands keep working through the non-root sudo rewriter', function () {
    $server = new Server(['user' => 'ubuntu']);
    $commands = [
        ...ContainerLogCollector::listContainersCommand(),
        ...ContainerLogCollector::logsCommand('web-abc', CarbonImmutable::parse('2026-10-02T09:30:00Z')),
    ];

    $rewritten = parseCommandsByLineForSudo(collect($commands), $server);

    expect($rewritten[0])->toBe("sudo docker ps --filter 'label=coolify.managed=true' --format '{{json .}}'")
        ->and($rewritten[1])->toBe("sudo docker logs --timestamps --since '2026-10-02T09:30:00.000000Z' --tail 500 'web-abc' 2>&1");

    foreach ($rewritten as $command) {
        exec('bash -n -c '.escapeshellarg($command), $output, $exitCode);
        expect($exitCode)->toBe(0);
    }
});

test('the per-server job does nothing while container collection is off', function (array $settings) {
    InstanceSettings::query()->find(0)->update($settings);
    $called = false;
    app()->instance(ContainerLogCollector::class, new ContainerLogCollector(function () use (&$called): ?string {
        $called = true;

        return null;
    }));

    (new CollectContainerLogsJob($this->server))->handle(app(ContainerLogCollector::class));

    expect($called)->toBeFalse();
})->with([
    'master switch off' => [['is_log_collection_enabled' => false]],
    'container source off' => [['is_container_log_collection_enabled' => false]],
]);

test('the per-server job skips unreachable servers', function () {
    $this->server->settings->update(['is_reachable' => false]);
    $called = false;
    $collector = new ContainerLogCollector(function () use (&$called): ?string {
        $called = true;

        return null;
    });

    (new CollectContainerLogsJob($this->server->fresh()))->handle($collector);

    expect($called)->toBeFalse();
});

test('the manager queues one job per functional server only while collection is on', function () {
    Queue::fake();
    $unreachable = Server::factory()->create(['team_id' => $this->team->id]);

    (new ContainerLogCollectionManagerJob)->handle();

    Queue::assertPushed(CollectContainerLogsJob::class, 1);
    Queue::assertPushed(CollectContainerLogsJob::class, fn (CollectContainerLogsJob $job) => $job->server->is($this->server));
    Queue::assertNotPushed(CollectContainerLogsJob::class, fn (CollectContainerLogsJob $job) => $job->server->is($unreachable));

    InstanceSettings::query()->find(0)->update(['is_log_collection_enabled' => false]);
    Queue::fake();
    (new ContainerLogCollectionManagerJob)->handle();
    Queue::assertNothingPushed();
});
