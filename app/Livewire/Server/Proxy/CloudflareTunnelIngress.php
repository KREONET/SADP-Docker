<?php

namespace App\Livewire\Server\Proxy;

use App\Actions\Server\ConfigureCloudflareTunnelIngress;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Public traffic for the server's proxy through a Cloudflare Tunnel, for setups without a public IP
 * or open ports. The tunnel token is write-only: it never goes back to the browser, and a blank
 * field keeps the stored token.
 */
class CloudflareTunnelIngress extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public bool $isEnabled = false;

    public ?string $token = null;

    public bool $hasStoredToken = false;

    public ?string $tunnelId = null;

    public function mount(): void
    {
        $this->authorize('view', $this->server);
        $this->syncFromSettings();
    }

    public function applyAndRestart(): void
    {
        try {
            $this->authorize('update', $this->server);

            $token = filled($this->token) ? trim($this->token) : null;
            $this->validate(['isEnabled' => ['boolean'], 'token' => ['nullable', 'string', 'max:4000']]);
            if ($token !== null && ConfigureCloudflareTunnelIngress::tunnelIdFromToken($token) === null) {
                throw ValidationException::withMessages(['token' => __('Paste the token of the tunnel from the Cloudflare dashboard (the long value after --token).')]);
            }
            if ($this->isEnabled && $token === null && ! $this->hasStoredToken) {
                throw ValidationException::withMessages(['token' => __('Enter the tunnel token.')]);
            }

            $settings = $this->server->settings;
            $settings->is_cloudflare_tunnel_ingress_enabled = $this->isEnabled;
            if ($token !== null) {
                $settings->cloudflare_tunnel_ingress_token = $token;
            }
            $settings->save();
            $this->server->refresh();

            $restarted = ConfigureCloudflareTunnelIngress::run($this->server);
            auditLog('ui.proxy.cloudflare_tunnel_ingress_applied', [
                'team_id' => $this->server->team_id,
                'server_uuid' => $this->server->uuid,
                'enabled' => $this->isEnabled,
            ]);
            $this->syncFromSettings();

            $message = $restarted
                ? __('Configuration saved. The proxy is restarting to apply it.')
                : __('Configuration saved. It applies the next time the proxy starts.');
            $this->dispatch('success', $message.' '.__('Redeploy applications and restart services so their proxy labels follow the change.'));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    private function syncFromSettings(): void
    {
        $settings = $this->server->settings;
        $stored = $settings->cloudflare_tunnel_ingress_token;

        $this->isEnabled = (bool) $settings->is_cloudflare_tunnel_ingress_enabled;
        $this->hasStoredToken = filled($stored);
        $this->tunnelId = ConfigureCloudflareTunnelIngress::tunnelIdFromToken($stored);
        $this->token = null;
    }

    public function render()
    {
        return view('livewire.server.proxy.cloudflare-tunnel-ingress', [
            'blockers' => ConfigureCloudflareTunnelIngress::blockers($this->server),
        ]);
    }
}
