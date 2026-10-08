<?php

namespace Database\Seeders;

use App\Models\PrivateKey;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Windows Docker Desktop mode reaches the "localhost" server (id 0) through the coolify-testing-host
 * container over SSH with private key 0.
 *
 * Earlier releases seeded one fixed private key whose public half is published in this repository and
 * in the coolify-testing-host image, so anyone could log in to that container (which has the Docker
 * socket) with it. The key is now generated per install, and a key that still equals the published
 * one is replaced.
 *
 * The matching public key is written to storage/app/ssh/windows-docker-desktop.pub on every seed run.
 * With docker-compose.windows.yml that file is ./ssh/windows-docker-desktop.pub next to the compose file
 * and /data/coolify/ssh/windows-docker-desktop.pub inside the coolify-testing-host container. The operator
 * must add it to /root/.ssh/authorized_keys in that container (and remove the published key there):
 *
 *   docker exec coolify-testing-host sh -c 'cat /data/coolify/ssh/windows-docker-desktop.pub >> /root/.ssh/authorized_keys'
 */
class WindowsDockerDesktopKeySeeder extends Seeder
{
    /**
     * Public key of the private key that earlier releases seeded for every Windows Docker Desktop install.
     */
    public const LEGACY_PUBLIC_KEY = 'AAAAC3NzaC1lZDI1NTE5AAAAIFuGmoeGq/pojrsyP1pszcNVuZx9iFkCELtxrh31QJ68';

    public const PUBLIC_KEY_FILE = 'app/ssh/windows-docker-desktop.pub';

    public function run(): void
    {
        $privateKey = PrivateKey::find(0);

        if ($privateKey === null || self::isLegacyKey($privateKey)) {
            $keyPair = generateSSHKey();
            // "id" is not fillable, so it is forced: the localhost server (id 0) uses private key 0.
            $privateKey = ($privateKey ?? new PrivateKey)->forceFill([
                'id' => 0,
                'team_id' => 0,
                'name' => 'Testing-host',
                'description' => 'This is a a docker container with SSH access',
                'private_key' => $keyPair['private'],
            ]);
            $privateKey->save();

            $this->writeLine('Generated a new SSH key for the Windows Docker Desktop testing host.');
            $this->writeLine('Add '.self::publicKeyPath().' to /root/.ssh/authorized_keys in the coolify-testing-host container:');
            $this->writeLine("  docker exec coolify-testing-host sh -c 'cat /data/coolify/ssh/windows-docker-desktop.pub >> /root/.ssh/authorized_keys'");
        }

        $publicKey = PrivateKey::extractPublicKeyFromPrivate($privateKey->private_key);
        if ($publicKey === null) {
            return;
        }

        File::ensureDirectoryExists(dirname(self::publicKeyPath()));
        File::put(self::publicKeyPath(), trim($publicKey)." coolify@coolify-instance\n");
    }

    public static function isLegacyKey(PrivateKey $privateKey): bool
    {
        $publicKey = PrivateKey::extractPublicKeyFromPrivate($privateKey->private_key);

        return $publicKey !== null && str_contains($publicKey, self::LEGACY_PUBLIC_KEY);
    }

    public static function publicKeyPath(): string
    {
        return storage_path(self::PUBLIC_KEY_FILE);
    }

    private function writeLine(string $message): void
    {
        if ($this->command !== null) {
            $this->command->line($message);

            return;
        }

        echo $message."\n";
    }
}
