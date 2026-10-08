<?php

namespace App\Actions\Server;

use App\Actions\Proxy\GetProxyConfiguration;
use App\Actions\Proxy\SaveProxyConfiguration;
use App\Enums\ProxyTypes;
use App\Jobs\RestartProxyJob;
use App\Models\Server;
use App\Services\ProxyPortParser;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class ApplyDnsChallenge
{
    use AsAction;

    private const STOPPED_PROXY_STATUSES = ['exited', 'stopped', 'stopping', 'dead'];

    /**
     * Writes the saved DNS-01 settings into the Traefik configuration (adding or removing the `dns`
     * resolver and its credentials, keeping everything else) and restarts the proxy to apply it.
     *
     * @return bool Whether a proxy restart was queued. A proxy the user stopped stays stopped; its next start applies the configuration.
     */
    public function handle(Server $server): bool
    {
        if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            throw new RuntimeException(__('Wildcard certificates through DNS-01 are available for the Traefik proxy only.'));
        }

        $configuration = GetProxyConfiguration::run($server);
        ProxyPortParser::fromConfiguration($configuration);
        SaveProxyConfiguration::run($server, applyDnsChallengeToProxyConfiguration($server, $configuration));

        $restart = ! $this->proxyIsStopped($server);
        if ($restart) {
            RestartProxyJob::dispatch($server);
        }

        return $restart;
    }

    private function proxyIsStopped(Server $server): bool
    {
        return (bool) $server->proxy->get('force_stop')
            || in_array($server->proxy->get('status'), self::STOPPED_PROXY_STATUSES, true);
    }
}
