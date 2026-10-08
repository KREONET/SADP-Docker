<?php

namespace App\Livewire\Server\Security;

use App\Models\Server;
use App\Models\ServerSetting;
use App\Services\Ssh\KnownHosts;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * Shows the trusted SSH host key of a server and lets administrators reset the
 * trust, so the next connection learns the (verified) new key.
 *
 * Embed with `<livewire:server.security.host-key :server="$server" />`.
 */
class HostKey extends Component
{
    use AuthorizesRequests;

    public Server $server;

    /** @var list<string> */
    public array $fingerprints = [];

    public ?string $trustedAt = null;

    public function mount(Server $server): void
    {
        $this->server = $this->resolveServer($server->id);
        $this->authorize('update', $this->server);
        $this->syncData();
    }

    public function resetTrust(mixed $password = null): mixed
    {
        try {
            $server = $this->resolveServer($this->server->id);

            try {
                $this->authorize('update', $server);
            } catch (AuthorizationException $e) {
                auditLog('server.ssh_host_key.reset', [
                    'server_uuid' => $server->uuid,
                    'result' => 'denied',
                ], 'warning');

                throw $e;
            }

            if (! verifyPasswordConfirmation($password, $this)) {
                return __('The provided password is incorrect.');
            }

            $previous = KnownHosts::trustedFingerprints($server);
            KnownHosts::forget($server);

            auditLog('server.ssh_host_key.reset', [
                'server_uuid' => $server->uuid,
                'server_name' => $server->name,
                'server_ip' => $server->ip,
                'previous_fingerprint' => implode(', ', $previous) ?: null,
                'result' => 'success',
            ], 'warning');

            $this->server = $server;
            $this->syncData();
            $this->dispatch('success', __('SSH host key trust was reset. The next connection will trust the key the server presents.'));

            return true;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render(): View
    {
        return view('livewire.server.security.host-key');
    }

    private function resolveServer(int $serverId): Server
    {
        return Server::ownedByCurrentTeam()->whereKey($serverId)->firstOrFail();
    }

    private function syncData(): void
    {
        $settings = ServerSetting::query()
            ->where('server_id', $this->server->id)
            ->first(['ssh_host_key_fingerprint', 'ssh_host_key_trusted_at']);

        $this->fingerprints = array_values(array_filter(explode("\n", (string) $settings?->ssh_host_key_fingerprint)));
        $this->trustedAt = $settings?->ssh_host_key_trusted_at?->toDateTimeString();
    }
}
