<?php

namespace App\Console\Commands;

use App\Models\ControllerIngressRoute;
use App\Services\ControllerIngress\ControllerIngress;
use Illuminate\Console\Command;

class SyncControllerIngress extends Command
{
    protected $signature = 'paded:sync-controller-ingress';

    protected $description = 'Rebuild the relay containers on the controller for servers routed through it';

    public function handle(): int
    {
        ControllerIngress::sync();
        $this->info('Controller routes synced ('.ControllerIngressRoute::query()->where('is_active', true)->count().' active).');

        return self::SUCCESS;
    }
}
