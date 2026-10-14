<?php

namespace App\Jobs;

use App\Services\ControllerIngress\ControllerIngress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Updates the relay containers on the controller after routes of workers changed. Unique until it
 * starts, so a burst of deployments queues one sync, and a change during a sync queues the next one.
 */
class SyncControllerIngressJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 120;

    public $backoff = [5, 15];

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('sync-controller-ingress'))->expireAfter(180)->releaseAfter(5),
        ];
    }

    public function handle(): void
    {
        ControllerIngress::sync();
    }
}
