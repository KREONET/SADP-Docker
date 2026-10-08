<?php

namespace App\Actions\Development;

use App\Models\PrivateKey;
use App\Models\Server;
use App\Services\Ssh\KnownHosts;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class SeedDevelopmentQemuServer
{
    use AsAction;

    /**
     * $ip and $port override the profile address, for example for a Lima VM whose SSH port is forwarded to the host.
     */
    public function handle(string $profileName, bool $removeOtherServers = true, bool $asLocalhost = false, ?string $ip = null, int $port = 22): Server
    {
        $this->ensureDevelopmentEnvironment();
        $profile = config("development-qemu.profiles.{$profileName}");

        if (! is_array($profile)) {
            throw new InvalidArgumentException("Unknown development QEMU profile: {$profileName}");
        }

        $privateKey = PrivateKey::query()->find(1);

        if (! $privateKey) {
            throw new RuntimeException('Development private key 1 is missing. Run the development database seeders first.');
        }

        if ($removeOtherServers) {
            Server::query()
                ->where('uuid', 'like', 'development-qemu-%')
                ->where('uuid', '!=', $profile['uuid'])
                ->delete();
        }

        $server = $asLocalhost
            ? (Server::withTrashed()->find(0) ?? new Server)
            : (Server::withTrashed()->where('uuid', $profile['uuid'])->first() ?? new Server);
        $server->forceFill($asLocalhost ? ['id' => 0, 'uuid' => 'localhost'] : ['uuid' => $profile['uuid']]);
        $server->fill([
            'name' => $asLocalhost ? 'localhost' : $profile['name'],
            'description' => 'Development QEMU virtual machine',
            'ip' => $ip ?? $profile['ip'],
            'port' => $port,
            'user' => $profile['user'],
            'team_id' => 0,
            'private_key_id' => $privateKey->id,
        ]);
        $server->deleted_at = null;
        $server->save();

        $this->forgetHostKey($server);

        return $server->fresh();
    }

    /**
     * Development VMs are rebuilt with `dev:qemu --fresh` or `limactl delete`, which gives them a new
     * SSH host key that Coolify is not told about. Forget the trusted key whenever a VM is (re)seeded
     * so the next connection learns the new key instead of being refused as a changed host key.
     */
    private function forgetHostKey(Server $server): void
    {
        if (KnownHosts::trustedFingerprints($server) === [] && ! is_file(KnownHosts::path($server))) {
            return;
        }

        KnownHosts::forget($server);
    }

    private function ensureDevelopmentEnvironment(): void
    {
        if (! in_array(config('app.env'), ['local', 'development', 'dev'], true)) {
            throw new RuntimeException('QEMU VM servers may only be seeded in development environments.');
        }
    }
}
