<?php

namespace Tests\Support;

use App\Http\Middleware\RequireTwoFactor;
use App\Models\User;

/**
 * Test helper for the mandatory 2FA policy (`security.two_factor.required_for`).
 *
 * Platform administrators without confirmed 2FA are sent to their profile by
 * {@see RequireTwoFactor}; tests that open other pages as such
 * an administrator confirm 2FA for them first.
 */
class TwoFactor
{
    public const SECRET = 'JBSWY3DPEHPK3PXP';

    public static function confirm(User $user): User
    {
        $user->forceFill([
            'two_factor_secret' => encrypt(self::SECRET),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1', 'recovery-code-2'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user;
    }
}
