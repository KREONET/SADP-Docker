<?php

namespace App\Enums;

use App\Models\User;

/**
 * Instance-wide permission level of a user, separate from their team roles.
 *
 * - ADMIN: admins and owners of the root team (see {@see User::isInstanceAdmin()}).
 *   Full platform admin, instance settings and security settings, and grants platform roles.
 * - SUB_ADMIN: `users.is_sub_admin`. Operates the platform admin pages (overview, applications,
 *   groups, users) but cannot grant platform roles or change instance and security settings.
 * - USER: no platform admin access.
 *
 * [한국어] 플랫폼 권한 3단계: 관리자 / 부관리자 / 유저.
 */
enum PlatformRole: string
{
    case ADMIN = 'admin';
    case SUB_ADMIN = 'sub_admin';
    case USER = 'user';

    public function label(): string
    {
        return match ($this) {
            self::ADMIN => __('Administrator'),
            self::SUB_ADMIN => __('Sub-administrator'),
            self::USER => __('Regular user'),
        };
    }

    public function canOperatePlatform(): bool
    {
        return $this !== self::USER;
    }
}
