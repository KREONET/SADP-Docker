<?php

namespace App\Services\Logging;

use App\Models\LogEntry;
use App\Models\Server;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Pulls new log lines of the running application and service containers on a server over SSH.
 *
 * [한국어] 서버에 SSH 로 접속해 실행 중인 앱·서비스 컨테이너의 새 로그를 가져옵니다.
 * 얼마나 읽었는지(마지막 시각)를 기억해 같은 줄을 두 번 저장하지 않습니다. 파싱은 ContainerLogParser 가 맡습니다.
 * 저장 전에 SensitiveDataMasker 로 이메일·비밀번호·토큰·개인키를 가립니다.
 */
class ContainerLogCollector
{
    public const MAX_LINES_PER_CONTAINER = 500;

    public const INITIAL_LOOKBACK_MINUTES = 5;

    private const RESOURCE_TYPES = ['application', 'service'];

    private const INSERT_CHUNK_SIZE = 200;

    /**
     * @var Closure(array<int, string>, Server): ?string
     */
    private Closure $runner;

    /**
     * @param  (Closure(array<int, string>, Server): ?string)|null  $runner
     */
    public function __construct(?Closure $runner = null)
    {
        $this->runner = $runner ?? fn (array $commands, Server $server): ?string => instant_remote_process($commands, $server, throwError: false);
    }

    /**
     * @return int The number of stored log lines.
     */
    public function collect(Server $server): int
    {
        $stored = 0;

        foreach ($this->runningContainers($server) as $container) {
            try {
                $stored += $this->collectContainer($server, $container);
            } catch (Throwable) {
                // One container that disappears mid-run must not stop the others.
            }
        }

        return $stored;
    }

    /**
     * @return array<int, string>
     */
    public static function listContainersCommand(): array
    {
        return ['docker ps --filter '.escapeshellarg('label=coolify.managed=true').' --format '.escapeshellarg('{{json .}}')];
    }

    /**
     * @return array<int, string>
     */
    public static function logsCommand(string $containerName, CarbonInterface $since): array
    {
        $sinceArgument = CarbonImmutable::instance($since)->utc()->format('Y-m-d\TH:i:s.u\Z');

        return [
            'docker logs --timestamps --since '.escapeshellarg($sinceArgument)
                .' --tail '.self::MAX_LINES_PER_CONTAINER
                .' '.escapeshellarg($containerName).' 2>&1',
        ];
    }

    /**
     * @return Collection<int, array{name: string, resource_type: string, resource_uuid: ?string, resource_name: ?string}>
     */
    private function runningContainers(Server $server): Collection
    {
        $output = ($this->runner)(self::listContainersCommand(), $server);
        if ($output === null || trim($output) === '') {
            return collect();
        }

        return format_docker_command_output_to_json($output)
            ->filter(fn (mixed $container): bool => is_array($container) && filled(data_get($container, 'Names')))
            ->map(function (array $container): ?array {
                $labels = self::parseLabels((string) data_get($container, 'Labels', ''));
                $type = $labels['coolify.type'] ?? match (true) {
                    isset($labels['coolify.applicationId']) => 'application',
                    isset($labels['coolify.serviceId']) => 'service',
                    default => null,
                };

                if (! in_array($type, self::RESOURCE_TYPES, true)) {
                    return null;
                }

                $name = (string) str((string) data_get($container, 'Names'))->before(',');

                return [
                    'name' => $name,
                    'resource_type' => $type,
                    'resource_uuid' => $labels["coolify.{$type}Uuid"] ?? $labels['com.docker.compose.project'] ?? null,
                    'resource_name' => $labels['coolify.resourceName'] ?? $labels['coolify.name'] ?? $name,
                ];
            })
            ->filter()
            ->unique('name')
            ->values();
    }

    /**
     * @param  array{name: string, resource_type: string, resource_uuid: ?string, resource_name: ?string}  $container
     */
    private function collectContainer(Server $server, array $container): int
    {
        $lastLoggedAt = LogEntry::query()
            ->where('source', LogEntry::SOURCE_CONTAINER)
            ->where('server_id', $server->id)
            ->where('container_name', $container['name'])
            ->max('logged_at');

        $after = $lastLoggedAt ? CarbonImmutable::parse($lastLoggedAt)->utc() : null;
        $since = $after ?? CarbonImmutable::now()->subMinutes(self::INITIAL_LOOKBACK_MINUTES);

        $lines = ContainerLogParser::parse(($this->runner)(self::logsCommand($container['name'], $since), $server), $after);
        if ($lines === []) {
            return 0;
        }

        $now = now();
        $messages = SensitiveDataMasker::maskLines(array_column($lines, 'message'));
        $rows = array_map(fn (array $line, string $message): array => [
            'source' => LogEntry::SOURCE_CONTAINER,
            'level' => $line['level'],
            'message' => LogEntry::truncateMessage($message),
            'context' => null,
            'team_id' => $server->team_id,
            'server_id' => $server->id,
            'resource_type' => $container['resource_type'],
            'resource_uuid' => $container['resource_uuid'],
            'resource_name' => $container['resource_name'],
            'container_name' => $container['name'],
            'logged_at' => $line['logged_at']->format('Y-m-d H:i:s.u'),
            'created_at' => $now,
        ], $lines, $messages);

        foreach (array_chunk($rows, self::INSERT_CHUNK_SIZE) as $chunk) {
            LogEntry::query()->insert($chunk);
        }

        return count($rows);
    }

    /**
     * @return array<string, string>
     */
    private static function parseLabels(string $labels): array
    {
        return collect(explode(',', $labels))
            ->map(fn (string $label): array => explode('=', $label, 2))
            ->filter(fn (array $pair): bool => count($pair) === 2 && $pair[0] !== '')
            ->mapWithKeys(fn (array $pair): array => [trim($pair[0]) => trim($pair[1])])
            ->all();
    }
}
