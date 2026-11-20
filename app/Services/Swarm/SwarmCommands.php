<?php

namespace App\Services\Swarm;

/**
 * Shell commands PADED runs over SSH to build and run a Docker Swarm cluster.
 *
 * Every command is written so that `parseCommandsByLineForSudo()` keeps it working for
 * servers that connect with a non-root SSH user.
 */
class SwarmCommands
{
    public const OVERLAY_NETWORK = 'coolify-overlay';

    public const MANAGER_PORT = 2377;

    public const REGISTRY_SERVICE = 'coolify-registry';

    public const REGISTRY_IMAGE = 'registry:2.8.3';

    public const REGISTRY_PORT = 5000;

    /**
     * Every node reaches the registry through the Swarm routing mesh on its own loopback
     * address, which Docker trusts without TLS.
     */
    public const REGISTRY_ADDRESS = '127.0.0.1:5000';

    public const REGISTRY_USERNAME = 'coolify';

    public const REGISTRY_DIRECTORY = '/data/coolify/swarm-registry';

    public const PROXY_STACK = 'coolify-proxy';

    /**
     * Ports the nodes of a cluster must reach on each other.
     *
     * @var list<string>
     */
    public const REQUIRED_PORTS = ['2377/tcp', '7946/tcp', '7946/udp', '4789/udp'];

    public static function localNodeState(): string
    {
        return "docker info --format '{{.Swarm.LocalNodeState}}'";
    }

    public static function isManagerNode(): string
    {
        return "docker info --format '{{.Swarm.ControlAvailable}}'";
    }

    public static function nodeId(): string
    {
        return "docker info --format '{{.Swarm.NodeID}}'";
    }

    public static function nodeAddress(): string
    {
        return "docker info --format '{{.Swarm.NodeAddr}}'";
    }

    public static function init(string $advertiseAddress): string
    {
        return 'docker swarm init --advertise-addr '.escapeshellarg($advertiseAddress);
    }

    public static function workerJoinToken(): string
    {
        return 'docker swarm join-token -q worker';
    }

    public static function join(string $token, string $managerAddress, ?string $advertiseAddress = null): string
    {
        $advertise = filled($advertiseAddress) ? ' --advertise-addr '.escapeshellarg($advertiseAddress) : '';

        return 'docker swarm join --token '.escapeshellarg($token).$advertise.' '.escapeshellarg($managerAddress.':'.self::MANAGER_PORT);
    }

    public static function leave(bool $force = false): string
    {
        return 'docker swarm leave'.($force ? ' --force' : '');
    }

    public static function drainNode(string $nodeId): string
    {
        return 'docker node update --availability drain '.escapeshellarg($nodeId);
    }

    public static function removeNode(string $nodeId): string
    {
        return 'docker node rm --force '.escapeshellarg($nodeId);
    }

    /**
     * One line per node: id|hostname|role|availability|state|address|nanocpus|memorybytes|leader.
     */
    public static function listNodes(): string
    {
        return "docker node inspect --format '{{.ID}}|{{.Description.Hostname}}|{{.Spec.Role}}|{{.Spec.Availability}}|{{.Status.State}}|{{.Status.Addr}}|{{.Description.Resources.NanoCPUs}}|{{.Description.Resources.MemoryBytes}}|{{if .ManagerStatus}}{{.ManagerStatus.Leader}}{{end}}' $(docker node ls -q)";
    }

    public static function ensureOverlayNetwork(): string
    {
        return dockerNetworkEnsureCommand(self::OVERLAY_NETWORK, overlay: true, quietCreate: true);
    }

    /**
     * Runs the cluster registry on the manager node, protected by a generated password, and
     * logs the manager in so builds can push and `docker stack deploy --with-registry-auth`
     * hands the credentials to the worker nodes.
     *
     * @return list<string>
     */
    public static function setUpRegistry(string $managerNodeId, string $password): array
    {
        $htpasswd = base64_encode(self::REGISTRY_USERNAME.':'.password_hash($password, PASSWORD_BCRYPT)."\n");
        $dataDirectory = self::REGISTRY_DIRECTORY.'/data';
        $authDirectory = self::REGISTRY_DIRECTORY.'/auth';
        $login = self::registryLogin($password);

        return [
            "mkdir -p {$dataDirectory}",
            "mkdir -p {$authDirectory}",
            "echo '{$htpasswd}' | base64 -d | tee {$authDirectory}/htpasswd >/dev/null",
            'docker pull '.self::REGISTRY_IMAGE.' >/dev/null',
            'docker service rm '.self::REGISTRY_SERVICE.' >/dev/null 2>&1 || true',
            'docker service create --detach --name '.self::REGISTRY_SERVICE
                .' --constraint '.escapeshellarg('node.id=='.$managerNodeId)
                .' --publish published='.self::REGISTRY_PORT.',target=5000'
                ." --mount type=bind,source={$dataDirectory},target=/var/lib/registry"
                ." --mount type=bind,source={$authDirectory},target=/auth,readonly"
                .' --env REGISTRY_AUTH=htpasswd --env REGISTRY_AUTH_HTPASSWD_REALM=PADED --env REGISTRY_AUTH_HTPASSWD_PATH=/auth/htpasswd'
                .' '.self::REGISTRY_IMAGE.' >/dev/null',
            'for attempt in $(seq 1 30); do',
            "    if {$login} >/dev/null 2>&1; then",
            '        break',
            '    fi',
            '    sleep 1',
            'done',
            $login,
        ];
    }

    /**
     * @return list<string>
     */
    public static function removeRegistry(): array
    {
        return [
            'docker service rm '.self::REGISTRY_SERVICE.' >/dev/null 2>&1 || true',
            'docker --config "$HOME/.docker" logout '.self::REGISTRY_ADDRESS.' >/dev/null 2>&1 || true',
        ];
    }

    public static function removeProxyStack(): string
    {
        return 'docker stack rm '.self::PROXY_STACK.' >/dev/null 2>&1 || true';
    }

    /**
     * `--config "$HOME/.docker"` keeps the credentials in the SSH user's home, which the
     * deployment helper container mounts, even when sudo changes HOME for non-root users.
     */
    private static function registryLogin(string $password): string
    {
        return "echo '{$password}' | docker --config \"\$HOME/.docker\" login ".self::REGISTRY_ADDRESS.' --username '.self::REGISTRY_USERNAME.' --password-stdin';
    }
}
