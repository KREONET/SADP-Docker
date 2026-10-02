<?php

namespace App\Console\Commands;

use App\Actions\Development\SeedDevelopmentDemo;
use Illuminate\Console\Command;

class SeedDevelopmentDemoCommand extends Command
{
    protected $signature = 'dev:demo
        {--loki-url=http://loki:3100 : Loki of the dev stack (observability profile); empty disables shipping}
        {--without-inventory : Skip the applications, services and databases of scripts/seed-transfer-demo.php}';

    protected $description = 'Fill a development instance with demo teams, users, resources and logs for every screen';

    public function handle(): int
    {
        if (! isDev()) {
            $this->error('This command may only run in development mode.');

            return self::FAILURE;
        }

        $result = SeedDevelopmentDemo::run((string) $this->option('loki-url'), ! $this->option('without-inventory'));

        $this->info('Demo data is ready.');
        $this->table(['Item', 'Value'], [
            ['Resources (applications, services, databases)', $result['inventory'] ? 'seeded' : 'skipped'],
            ['User activity events', $result['audit_events']],
            ['Coolify system log lines', $result['system_logs']],
            ['Container log lines', $result['container_logs']],
            ['Loki', $result['loki']],
        ]);
        $this->line('Log in as test@example.com (root owner) or one of these, all with password "'.SeedDevelopmentDemo::PASSWORD.'":');
        foreach (SeedDevelopmentDemo::USERS as $email => $user) {
            $team = $user['team'] === 'root' ? 'Root Team' : SeedDevelopmentDemo::TENANT_TEAM;
            $this->line("  {$email}  {$user['role']} of {$team}");
        }

        return self::SUCCESS;
    }
}
