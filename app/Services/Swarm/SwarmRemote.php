<?php

namespace App\Services\Swarm;

use App\Models\Server;

/**
 * Runs Swarm commands on a server over SSH. Tests swap it for a fake.
 */
class SwarmRemote
{
    /**
     * @param  list<string>  $commands
     */
    public function run(Server $server, array $commands, bool $throwError = true, ?int $timeout = null): string
    {
        return trim((string) instant_remote_process($commands, $server, $throwError, timeout: $timeout));
    }
}
