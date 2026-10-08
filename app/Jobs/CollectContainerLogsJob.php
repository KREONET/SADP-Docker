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
 * E-mail addresses, credentials and private keys in the lines are masked before they are stored
 * (see ContainerLogCollector and SensitiveDataMasker).
 *
 * [한국어] 서버 한 대의 컨테이너 새 로그를 읽어 DB 에 저장합니다. 서버마다 하나씩 실행됩니다 (ShouldBeUnique).
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
