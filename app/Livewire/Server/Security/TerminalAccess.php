<?php

namespace App\Livewire\Server\Security;

use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;

class TerminalAccess extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public array $parameters = [];

    #[Validate(['boolean'])]
    public bool $isTerminalEnabled = false;

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->authorize('update', $this->server);
            $this->parameters = get_route_parameters();
            $this->syncData();

        } catch (\Throwable) {
            return redirect()->route('server.index');
        }
    }

    public function toggleTerminal($password, $selectedActions = [])
    {
        try {
            $this->authorize('update', $this->server);

            // Check if user is admin or owner
            if (! auth()->user()->isAdmin()) {
                throw new \Exception(__('Only team administrators and owners can modify terminal access.'));
            }

            // Verify password
            if (! verifyPasswordConfirmation($password, $this)) {
                $this->auditToggle(! $this->server->settings->is_terminal_enabled, 'failure', 'password_confirmation_failed');

                return __('The provided password is incorrect.');
            }

            // Toggle the terminal setting
            $this->server->settings->is_terminal_enabled = ! $this->server->settings->is_terminal_enabled;
            $this->server->settings->save();

            // Update the local property
            $this->isTerminalEnabled = $this->server->settings->is_terminal_enabled;

            $this->auditToggle($this->isTerminalEnabled, 'success');

            $this->dispatch('success', $this->isTerminalEnabled ? __('Terminal access has been enabled.') : __('Terminal access has been disabled.'));

            return true;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * Audit an attempt to enable or disable terminal access on this server.
     */
    private function auditToggle(bool $enable, string $result, ?string $reason = null): void
    {
        auditLog($enable ? 'ui.server.terminal_enabled' : 'ui.server.terminal_disabled', array_filter([
            'team_id' => $this->server->team_id,
            'resource' => 'server',
            'server_uuid' => $this->server->uuid,
            'server_name' => $this->server->name,
            'result' => $result,
            'reason' => $reason,
        ], fn (mixed $value): bool => $value !== null), $enable || $result !== 'success' ? 'warning' : 'info');
    }

    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->validate();
            // No other fields to sync for terminal access
        } else {
            $this->isTerminalEnabled = $this->server->settings->is_terminal_enabled;
        }
    }

    public function render()
    {
        return view('livewire.server.security.terminal-access');
    }
}
