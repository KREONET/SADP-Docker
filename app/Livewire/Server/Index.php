<?php

namespace App\Livewire\Server;

use App\Jobs\CheckAndStartSentinelJob;
use App\Livewire\Concerns\RemembersCanvasView;
use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

class Index extends Component
{
    use RemembersCanvasView;

    public ?Collection $servers = null;

    public ?Collection $sharedServers = null;

    public function mount(): void
    {
        if ($this->prefersCanvasView('servers')) {
            $this->redirectRoute('server.canvas.index', navigate: true);

            return;
        }

        $this->servers = Server::ownedByCurrentTeamCached();
        $this->sharedServers = Server::sharedWithCurrentTeam()->get();
    }

    /**
     * Servers of the current team on which the user can install Sentinel: regular, validated servers
     * that are not build servers, Swarm servers, disabled or transferred away.
     */
    public static function sentinelInstallableServers(): Collection
    {
        return Server::ownedByCurrentTeam()->with('settings')->get()
            ->filter(fn (Server $server) => auth()->user()?->can('manageSentinel', $server)
                && $server->isSentinelEnabled()
                && $server->isFunctional())
            ->values();
    }

    /**
     * Install Sentinel on every installable server. The queued check starts Sentinel where it is missing,
     * outdated or stalled, and leaves a running, current Sentinel alone.
     */
    public function installSentinelOnAllServers(): void
    {
        try {
            $servers = self::sentinelInstallableServers();
            if ($servers->isEmpty()) {
                $this->dispatch('info', __('No validated server needs a Sentinel installation.'));

                return;
            }

            $started = [];
            $failed = [];
            foreach ($servers as $server) {
                try {
                    // A queued job has no web request, so it cannot derive the Coolify URL from the request host.
                    // Resolve and save the push URL here, while the request of the browser is available.
                    $server->settings->ensureSentinelUrl();
                    $server->settings->ensureValidSentinelToken();
                    CheckAndStartSentinelJob::dispatch($server);
                    $started[] = $server->uuid;
                } catch (\Throwable $e) {
                    $server->rememberSentinelPushProblem('Sentinel could not be installed: '.$e->getMessage());
                    $failed[$server->name] = $e->getMessage();
                }
            }

            auditLog('ui.server.sentinel.bulk_installed', [
                'team_id' => currentTeam()?->id,
                'server_uuids' => $started,
                'failed_servers' => array_keys($failed),
            ]);

            if ($started !== []) {
                $this->dispatch('success', __('Sentinel installation started on :count servers.', ['count' => count($started)]));
            }
            if ($failed !== []) {
                $this->dispatch('error', __('Sentinel could not be installed on :servers.', ['servers' => implode(', ', array_keys($failed))]), implode("\n", array_unique($failed)));
            }
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.index');
    }
}
