<?php

namespace App\Exceptions;

use App\Models\Server;
use RuntimeException;

/**
 * Thrown when a managed server presents an SSH host key that differs from the
 * key Coolify trusted on the first connection. The connection is refused until
 * an administrator verifies the server and resets the trusted key.
 */
class SshHostKeyChangedException extends RuntimeException
{
    public function __construct(public readonly Server $server)
    {
        parent::__construct(__('SSH host key changed for :host — verify and re-trust in server settings.', [
            'host' => (string) $server->ip,
        ]));
    }
}
