<?php

namespace App\Support;

use phpseclib3\Crypt\EC;
use RuntimeException;

/**
 * The SSH key pair of the local development hosts (testing-host container, QEMU and Lima VMs).
 *
 * The key is generated on each developer machine and never committed: .dev/testing-host-key and
 * .dev/testing-host-key.pub are ignored by git. scripts/dev creates the same files with ssh-keygen
 * before it builds the development hosts, so either side may generate them first.
 */
final class DevelopmentSshKey
{
    public const PRIVATE_KEY_PATH = '.dev/testing-host-key';

    public const PUBLIC_KEY_PATH = '.dev/testing-host-key.pub';

    public static function privateKey(): string
    {
        self::ensureExists();

        return (string) file_get_contents(base_path(self::PRIVATE_KEY_PATH));
    }

    /**
     * The public key, or null while no key has been generated on this machine.
     */
    public static function publicKey(): ?string
    {
        $path = base_path(self::PUBLIC_KEY_PATH);

        return is_file($path) ? trim((string) file_get_contents($path)) : null;
    }

    public static function ensureExists(): void
    {
        $privatePath = base_path(self::PRIVATE_KEY_PATH);
        $publicPath = base_path(self::PUBLIC_KEY_PATH);
        if (is_file($privatePath) && is_file($publicPath)) {
            return;
        }

        if (! is_dir(dirname($privatePath)) && ! mkdir(dirname($privatePath), 0700, true) && ! is_dir(dirname($privatePath))) {
            throw new RuntimeException('Cannot create the directory for the development SSH key.');
        }

        $key = EC::createKey('Ed25519');
        file_put_contents($privatePath, $key->toString('OpenSSH', ['comment' => 'coolify@development']));
        chmod($privatePath, 0600);
        file_put_contents($publicPath, $key->getPublicKey()->toString('OpenSSH', ['comment' => 'coolify@development'])."\n");
    }
}
