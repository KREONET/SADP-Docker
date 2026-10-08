<?php

namespace App\Enums;

/**
 * Who can reach an application through the proxy.
 */
enum ApplicationExposureMode: string
{
    case Public = 'public';
    case Internal = 'internal';
    case Login = 'login';
    case Private = 'private';

    public function label(): string
    {
        return match ($this) {
            self::Public => __('Public to everyone'),
            self::Internal => __('Internal network only'),
            self::Login => __('Signed-in users only'),
            self::Private => __('Private'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Public => __('Anyone can open the application through its domains.'),
            self::Internal => __('Only clients from the internal network ranges set by a platform administrator can open the application.'),
            self::Login => __('Visitors must sign in to this platform first (password or OIDC). Any signed-in user can open the application.'),
            self::Private => __('No domain is published. The application is reachable only from the server\'s Docker network, or through port mappings if you configured any.'),
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $mode) => ['value' => $mode->value, 'label' => $mode->label()],
            self::cases(),
        );
    }
}
