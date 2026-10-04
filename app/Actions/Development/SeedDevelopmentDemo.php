<?php

namespace App\Actions\Development;

use App\Jobs\ShipLogsToLokiJob;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\LogEntry;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Fills a development instance with demo data for every screen: the transfer-demo inventory
 * (applications, services, databases, backups), a tenant team with users of each role, and three
 * days of user activity, Coolify system logs and container logs shipped to the dev Loki.
 *
 * Demo rows are marked with `demo: true` and replaced on every run.
 *
 * [한국어] 개발용 데모 데이터를 만듭니다. `./scripts/dev demo` 또는 `php artisan dev:demo` 로 실행합니다.
 * 여러 번 실행해도 안전합니다(데모 행은 `demo: true` 로 표시되어 매번 지우고 다시 만듭니다). 운영 환경에서는 쓰지 마세요.
 */
class SeedDevelopmentDemo
{
    use AsAction;

    public const PASSWORD = 'password';

    public const TENANT_TEAM = 'Demo Tenant';

    public const HISTORY_DAYS = 3;

    /** @var array<string, array{name: string, team: 'root'|'tenant', role: 'owner'|'admin'|'member'}> */
    public const USERS = [
        'owner@demo.test' => ['name' => 'Dana Owner', 'team' => 'tenant', 'role' => 'owner'],
        'dev@demo.test' => ['name' => 'Devon Developer', 'team' => 'tenant', 'role' => 'member'],
        'ops@demo.test' => ['name' => 'Olivia Ops', 'team' => 'root', 'role' => 'admin'],
        'support@demo.test' => ['name' => 'Sam Support', 'team' => 'root', 'role' => 'member'],
    ];

    private const AUDIT_EVENTS = 90;

    private const SYSTEM_LOGS = 45;

    private const CONTAINER_LOGS_PER_APP = 70;

    private const IPS = ['203.0.113.10', '203.0.113.24', '198.51.100.7', '192.0.2.55', '10.0.0.12'];

    private Randomizer $random;

    /**
     * @return array{inventory: bool, users: list<string>, audit_events: int, system_logs: int, container_logs: int, loki: string}
     */
    public function handle(string $lokiUrl, bool $withInventory = true): array
    {
        $this->assertBaseDataExists();

        // A fixed seed gives every developer the same demo history.
        $this->random = new Randomizer(new Mt19937(20261002));

        if ($withInventory) {
            $this->seedInventory();
        }

        $tenant = $this->seedTeamsAndUsers();
        $hadDemoLogs = $this->deletePreviousDemoLogs();
        $this->configureLogCollection($lokiUrl, $hadDemoLogs);

        $users = User::query()->whereIn('email', ['test@example.com', ...array_keys(self::USERS)])->get()->keyBy('email');
        $applications = $this->applicationNames();

        $auditEvents = $this->seedAuditEvents($users->all(), $tenant, $applications);
        $systemLogs = $this->seedSystemLogs();
        $containerLogs = $this->seedContainerLogs($applications);

        return [
            'inventory' => $withInventory,
            'users' => array_keys(self::USERS),
            'audit_events' => $auditEvents,
            'system_logs' => $systemLogs,
            'container_logs' => $containerLogs,
            'loki' => $this->shipToLoki($hadDemoLogs),
        ];
    }

    /**
     * The base development seeders (root team and user, localhost server, "My first project") create
     * rows with fixed ids that the demo data builds on. Creating demo rows first would take those ids.
     * Nothing is created before this check passes, so a too early run leaves no residue.
     */
    private function assertBaseDataExists(): void
    {
        if (Team::query()->find(0) && User::query()->find(0) && Server::query()->find(0)
            && Project::query()->where('team_id', 0)->exists()) {
            return;
        }

        throw new RuntimeException('The base development data does not exist yet. Wait until the instance has finished its first-time setup (init-setup: done in ./scripts/dev logs) and run dev:demo again.');
    }

    private function seedInventory(): void
    {
        ob_start();
        try {
            (static function (): void {
                require base_path('scripts/seed-transfer-demo.php');
            })();
        } finally {
            ob_end_clean();
        }
    }

    private function seedTeamsAndUsers(): Team
    {
        $tenant = Team::query()->firstOrCreate(
            ['name' => self::TENANT_TEAM],
            ['description' => 'Second team for multi-tenant and permission demos', 'personal_team' => false, 'show_boarding' => false],
        );
        Project::query()->firstOrCreate(
            ['name' => 'Tenant Shop', 'team_id' => $tenant->id],
            ['description' => 'Project owned by the demo tenant'],
        );
        $teams = ['root' => Team::query()->findOrFail(0), 'tenant' => $tenant];

        foreach (self::USERS as $email => $definition) {
            $user = User::query()->firstOrCreate(['email' => $email], [
                'name' => $definition['name'],
                'password' => Hash::make(self::PASSWORD),
            ]);
            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

            $team = $teams[$definition['team']];
            $team->members()->syncWithoutDetaching([$user->id => ['role' => $definition['role']]]);
            $user->update(['current_team_id' => $team->id]);
            // Skip onboarding for the personal team the User model creates.
            $user->teams()->where('personal_team', true)->update(['show_boarding' => false]);
        }

        return $tenant;
    }

    private function deletePreviousDemoLogs(): bool
    {
        $deleted = AuditEvent::query()->where('metadata->demo', true)->delete()
            + LogEntry::query()->where('context->demo', true)->delete();

        return $deleted > 0;
    }

    private function configureLogCollection(string $lokiUrl, bool $hadDemoLogs): void
    {
        $settings = instanceSettings();
        $settings->update([
            'is_log_collection_enabled' => true,
            'is_audit_log_collection_enabled' => true,
            'is_system_log_collection_enabled' => true,
            'is_container_log_collection_enabled' => true,
            'is_loki_shipping_enabled' => filled($lokiUrl),
            'loki_url' => filled($lokiUrl) ? $lokiUrl : $settings->loki_url,
            // Loki already holds the history of an earlier run and rejects samples far behind its
            // newest line, so a re-run only ships what happens from now on.
            'loki_shipping_started_at' => $hadDemoLogs ? now() : now()->subDays(self::HISTORY_DAYS)->subHour(),
            'loki_last_error' => null,
        ]);
    }

    /**
     * @return list<string>
     */
    private function applicationNames(): array
    {
        $names = Application::query()->orderBy('id')->limit(4)->pluck('name')->all();

        return $names !== [] ? $names : ['shop-web', 'billing-api'];
    }

    /**
     * @param  array<string, User>  $users
     * @param  list<string>  $applications
     */
    private function seedAuditEvents(array $users, Team $tenant, array $applications): int
    {
        $templates = [
            ['auth.user.login_succeeded', 'info', 'user', 'User login succeeded'],
            ['auth.user.login_failed', 'warning', 'user', 'Login failed'],
            ['ui.application.deployment_started', 'info', 'application', 'Deployment started'],
            ['ui.application.restarted', 'info', 'application', 'Restarted'],
            ['api.application.deployment_started', 'info', 'application', 'Deployment started via API'],
            ['ui.application.environment_variable_updated', 'info', 'application', 'Environment variable updated'],
            ['ui.database.backup_started', 'info', 'database', 'Backup started'],
            ['ui.server.updated', 'info', 'server', 'Server settings updated'],
            ['webhook.github.signature_failed', 'warning', 'webhook', 'GitHub webhook signature failed'],
            ['ui.application.deleted', 'warning', 'application', 'Deleted'],
        ];

        $rows = [];
        for ($i = 0; $i < self::AUDIT_EVENTS; $i++) {
            [$event, $level, $resourceType, $description] = $this->pick($templates);
            $user = $this->pick(array_values($users));
            $source = Str::before($event, '.');
            $isTenantUser = $user->current_team_id === $tenant->id;
            $resourceName = match ($resourceType) {
                'application' => $this->pick($applications),
                'database' => 'postgres-main',
                'server' => 'localhost',
                default => null,
            };
            $failedLogin = $event === 'auth.user.login_failed';

            $rows[] = [
                'team_id' => $isTenantUser ? $tenant->id : 0,
                'event' => $event,
                'source' => $source,
                'action' => Str::afterLast($event, '.'),
                'level' => $level,
                'actor_type' => match (true) {
                    $source === 'webhook' => 'webhook',
                    $source === 'api' => 'api_token',
                    default => 'user',
                },
                'actor_id' => $failedLogin || $source === 'webhook' ? null : $user->id,
                'actor_name' => $failedLogin || $source === 'webhook' ? null : $user->name,
                'actor_email' => $source === 'webhook' ? null : $user->email,
                'actor_token_name' => $source === 'api' ? 'ci-deploy' : null,
                'resource_type' => $resourceType,
                'resource_name' => $resourceName,
                'description' => trim(($resourceName ? $resourceName.' ' : '').$description),
                'metadata' => ['demo' => true],
                'ip_address' => $this->pick(self::IPS),
                'user_agent' => $source === 'api' ? 'curl/8.11.1' : 'Mozilla/5.0 (Macintosh; Intel Mac OS X 15_0) Chrome/140.0',
                'created_at' => $this->randomTime(),
            ];
        }

        foreach ($this->chronological($rows) as $row) {
            AuditEvent::query()->create($row);
        }

        return count($rows);
    }

    private function seedSystemLogs(): int
    {
        $templates = [
            ['warning', 'SSH connection to server "build-01" timed out after 10s, retrying', ['server_name' => 'build-01', 'attempt' => 2]],
            ['warning', 'Deployment queue is full; new deployments are waiting', ['queued' => 12]],
            ['error', 'Failed to pull image ghcr.io/acme/shop-web:latest: manifest unknown', ['image' => 'ghcr.io/acme/shop-web:latest']],
            ['error', 'Backup upload to S3 storage "offsite" failed: 403 Forbidden', ['s3_storage' => 'offsite', 'access_key' => 'AKIADEMO']],
            ['warning', 'Proxy configuration reload took 4.2s', ['proxy' => 'traefik']],
            ['critical', 'Server "edge-02" is unreachable; marking it unusable', ['server_name' => 'edge-02']],
            ['error', 'Webhook delivery to https://hooks.example.com/coolify failed: cURL error 28', ['status' => null]],
        ];

        $rows = [];
        for ($i = 0; $i < self::SYSTEM_LOGS; $i++) {
            [$level, $message, $context] = $this->pick($templates);
            $rows[] = $this->logRow([
                'source' => LogEntry::SOURCE_SYSTEM,
                'level' => $level,
                'message' => $message,
                'context' => AuditEvent::redactContext([...$context, 'demo' => true]),
            ]);
        }

        LogEntry::query()->insert($this->chronological($rows));

        return count($rows);
    }

    /**
     * @param  list<string>  $applications
     */
    private function seedContainerLogs(array $applications): int
    {
        $paths = ['/', '/api/orders', '/api/cart', '/health', '/login', '/static/app.js'];
        $rows = [];

        foreach ($applications as $application) {
            $slug = Str::slug($application);
            $container = $slug.'-'.substr(md5($application), 0, 6);

            for ($i = 0; $i < self::CONTAINER_LOGS_PER_APP; $i++) {
                $roll = $this->random->getInt(1, 100);
                [$level, $message] = match (true) {
                    $roll <= 6 => ['error', 'Error: connect ECONNREFUSED 10.0.1.5:5432 (database connection lost)'],
                    $roll <= 14 => ['warning', 'WARN slow request: GET /api/orders took 2.4s'],
                    $roll <= 18 => ['info', "Server listening on 0.0.0.0:3000 ({$slug} v1.4.2)"],
                    default => ['info', sprintf(
                        '%s - "GET %s HTTP/1.1" %d %dms',
                        $this->pick(self::IPS),
                        $this->pick($paths),
                        $this->pick([200, 200, 200, 201, 304, 404]),
                        $this->random->getInt(2, 180),
                    )],
                };

                $rows[] = $this->logRow([
                    'source' => LogEntry::SOURCE_CONTAINER,
                    'level' => $level,
                    'message' => $message,
                    'context' => ['demo' => true],
                    'team_id' => 0,
                    'server_id' => 0,
                    'resource_type' => 'application',
                    'resource_uuid' => null,
                    'resource_name' => $application,
                    'container_name' => $container,
                ]);
            }
        }

        foreach (array_chunk($this->chronological($rows), 200) as $chunk) {
            LogEntry::query()->insert($chunk);
        }

        return count($rows);
    }

    private function shipToLoki(bool $hadDemoLogs): string
    {
        $settings = instanceSettings();
        if (! $settings->isLokiShippingActive()) {
            return 'Loki shipping is off (no Loki URL).';
        }

        (new ShipLogsToLokiJob)->handle();
        $error = $settings->fresh()->loki_last_error;

        if ($error !== null) {
            return 'Loki: '.$error;
        }

        return $hadDemoLogs
            ? 'Loki already has the demo history from an earlier run; new activity is shipped every minute.'
            : 'Shipped the demo history to Loki.';
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function logRow(array $attributes): array
    {
        $loggedAt = $this->randomTime();

        return [
            'team_id' => null,
            'server_id' => null,
            'resource_type' => null,
            'resource_uuid' => null,
            'resource_name' => null,
            'container_name' => null,
            ...$attributes,
            'message' => LogEntry::truncateMessage($attributes['message']),
            'context' => json_encode($attributes['context']),
            'logged_at' => $loggedAt->format('Y-m-d H:i:s.u'),
            'created_at' => $loggedAt->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Ids then follow time, which keeps Loki batches in order.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function chronological(array $rows): array
    {
        usort($rows, fn (array $a, array $b): int => strcmp((string) ($a['logged_at'] ?? $a['created_at']), (string) ($b['logged_at'] ?? $b['created_at'])));

        return $rows;
    }

    private function randomTime(): CarbonImmutable
    {
        $seconds = $this->random->getInt(60, self::HISTORY_DAYS * 86400);

        return CarbonImmutable::now()->subSeconds($seconds)->setMicrosecond($this->random->getInt(0, 999999));
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $items
     * @return T
     */
    private function pick(array $items): mixed
    {
        return $items[$this->random->pickArrayKeys($items, 1)[0]];
    }
}
