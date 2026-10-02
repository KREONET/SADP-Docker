<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Logging\ContainerLogCollector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Stores new container log lines of one server for the instance log viewer and Loki.
 */
class CollectContainerLogsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public Server $server) {}

    public function uniqueId(): string
    {
        return (string) $this->server->id;
    }

    public function handle(ContainerLogCollector $collector): void
    {
        if (! instanceSettings()->isContainerLogCollectionActive()) {
            return;
        }

        if (! $this->server->isFunctional() || $this->server->isSwarm()) {
            return;
        }

        $collector->collect($this->server);
    }
}
