<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queues one CollectContainerLogsJob per usable server while container log collection is on.
 *
 * [한국어] '컨테이너 로그 수집' 이 켜져 있으면 사용 가능한 서버마다 CollectContainerLogsJob 을 하나씩 큐에 넣습니다. 꺼져 있으면 아무것도 하지 않습니다.
 */
class ContainerLogCollectionManagerJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 120;

    public function handle(): void
    {
        if (! instanceSettings()->isContainerLogCollectionActive()) {
            return;
        }

        Server::query()
            ->with('settings')
            ->chunkById(100, function ($servers): void {
                foreach ($servers as $server) {
                    if ($server->isFunctional() && ! $server->isSwarm()) {
                        CollectContainerLogsJob::dispatch($server);
                    }
                }
            });
    }
}
