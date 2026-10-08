<?php

namespace App\Enums;

/**
 * Key types Coolify can generate for SSH key pairs, in display order.
 *
 * ECDSA P-256 and RSA-3072 are FIPS 186-5 / KCMVP approved; Ed25519 is kept
 * for compatibility but is not the default.
 */
enum SshKeyType: string
{
    case ECDSA = 'ecdsa';
    case RSA = 'rsa';
    case ED25519 = 'ed25519';

    public const RSA_BITS = 3072;

    public const ECDSA_CURVE = 'nistp256';

    public static function default(): self
    {
        return self::ECDSA;
    }

    public function isApproved(): bool
    {
        return $this !== self::ED25519;
    }

    /**
     * Label for the "generate key" menu entry.
     */
    public function generateLabel(): string
    {
        return match ($this) {
            self::ECDSA => __('Generate ECDSA P-256 (recommended)'),
            self::RSA => __('Generate RSA 3072'),
            self::ED25519 => __('Generate ED25519 (not KCMVP-approved)'),
        };
    }
}
