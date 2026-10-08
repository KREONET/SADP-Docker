<?php

namespace App\Services;

use App\Helpers\SshMultiplexingHelper;
use App\Models\Server;
use App\Models\User;
use App\Support\ValidationPatterns;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

class TerminalSessionService
{
    private const TOKEN_TTL_SECONDS = 60;

    public function issue(User $user, Server $server, ?string $container = null): string
    {
        $token = Str::random(64);

        Cache::put($this->cacheKey($token), [
            'user_id' => $user->id,
            'team_id' => $user->currentTeam()->id,
            'server_uuid' => $server->uuid,
            'container' => $container,
        ], now()->addSeconds(self::TOKEN_TTL_SECONDS));

        auditLog('terminal.session.issued', $this->auditContext($user, $server, $container) + ['result' => 'success']);

        return $token;
    }

    /**
     * Redeem a one-time terminal token and build the SSH command for it.
     * Every redemption is audited, including rejected ones.
     */
    public function redeem(User $user, string $token): string
    {
        $payload = null;
        $server = null;

        try {
            $command = $this->redeemPayload($user, $token, $payload, $server);
        } catch (Throwable $exception) {
            $ownPayload = is_array($payload) && data_get($payload, 'user_id') === $user->id ? $payload : [];

            auditLog('terminal.session.redeemed', $this->auditContext(
                $user,
                $server,
                is_string($ownPayload['container'] ?? null) ? $ownPayload['container'] : null,
                is_string($ownPayload['server_uuid'] ?? null) ? $ownPayload['server_uuid'] : null,
            ) + [
                'result' => 'denied',
                'reason' => $exception instanceof AccessDeniedHttpException ? $exception->getMessage() : class_basename($exception),
            ], 'warning');

            throw $exception;
        }

        auditLog('terminal.session.redeemed', $this->auditContext($user, $server, $payload['container']) + [
            'command' => maskAuditCommand($this->remoteCommand($server, $payload['container'])),
            'result' => 'success',
        ]);

        return $command;
    }

    /**
     * @param  mixed  $payload  Set to the cached token payload.
     * @param  Server|null  $server  Set to the resolved target server.
     */
    private function redeemPayload(User $user, string $token, mixed &$payload, ?Server &$server): string
    {
        $payload = Cache::lock($this->cacheKey($token).':lock', 5)
            ->get(fn () => Cache::pull($this->cacheKey($token)));

        if (! is_array($payload)
            || data_get($payload, 'user_id') !== $user->id
            || data_get($payload, 'team_id') !== $user->currentTeam()->id
            || ! is_string(data_get($payload, 'server_uuid'))
            || ! array_key_exists('container', $payload)) {
            throw new AccessDeniedHttpException('Invalid or expired terminal token.');
        }

        $server = Server::terminalAccessibleBy($user)
            ->whereUuid(data_get($payload, 'server_uuid'))
            ->with('privateKey', 'settings')
            ->firstOrFail();

        if (! $user->can('openTerminal', $server)
            || ! $server->isTerminalEnabled()
            || $server->isForceDisabled()
            || ! $server->privateKey
            || $server->privateKey->team_id !== $server->team_id) {
            throw new AccessDeniedHttpException('Terminal target is not authorized.');
        }

        $container = $payload['container'];

        if ($container !== null) {
            if (! is_string($container)
                || ! ValidationPatterns::isValidContainerName($container)
                || getContainerStatus($server, $container) !== 'running') {
                throw new AccessDeniedHttpException('Terminal container is not authorized.');
            }
        }

        return SshMultiplexingHelper::generateSshCommand(
            $server,
            $this->remoteCommand($server, $container),
            commandTimeout: (int) config('constants.terminal.command_timeout')
        );
    }

    /**
     * The command run on the server: a login shell, or a shell inside the container.
     */
    private function remoteCommand(Server $server, ?string $container): string
    {
        $command = $this->shellCommand();

        if ($container === null) {
            return $command;
        }

        $dockerCommand = 'docker exec -it '.escapeshellarg($container).' sh -c '.escapeshellarg($command);

        return $server->isNonRoot() ? "sudo {$dockerCommand}" : $dockerCommand;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditContext(User $user, ?Server $server, ?string $container, ?string $serverUuid = null): array
    {
        return [
            'team_id' => $server?->team_id ?? $user->currentTeam()?->id,
            'resource' => 'server',
            'server_uuid' => $server?->uuid ?? $serverUuid,
            'server_name' => $server?->name,
            'container' => $container,
            'actor_id' => $user->id,
            'actor_name' => $user->name,
            'actor_email' => $user->email,
        ];
    }

    private function shellCommand(): string
    {
        return 'PATH=$PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin && '.
            'if [ -f ~/.profile ]; then . ~/.profile; fi && '.
            'if [ -n "$SHELL" ] && [ -x "$SHELL" ]; then exec $SHELL; else sh; fi';
    }

    private function cacheKey(string $token): string
    {
        return "terminal-session:{$token}";
    }
}
