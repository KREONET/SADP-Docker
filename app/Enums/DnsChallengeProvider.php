<?php

namespace App\Enums;

/**
 * DNS providers offered for the Traefik ACME DNS-01 wildcard certificate. The value is the
 * Traefik (lego) provider code; `Other` lets an admin enter any lego provider code.
 */
enum DnsChallengeProvider: string
{
    case Cloudflare = 'cloudflare';
    case Route53 = 'route53';
    case Rfc2136 = 'rfc2136';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cloudflare => 'Cloudflare',
            self::Route53 => 'AWS Route 53',
            self::Rfc2136 => __('RFC 2136 (BIND, PowerDNS, self-hosted DNS)'),
            self::Other => __('Other provider'),
        };
    }

    public function helper(): string
    {
        return match ($this) {
            self::Cloudflare => __('Create an API token with Zone.DNS:Edit and Zone.Zone:Read permissions for the zone.'),
            self::Route53 => __('Use an IAM user that may change records in the hosted zone (route53:ChangeResourceRecordSets, route53:GetChange, route53:ListHostedZonesByName).'),
            self::Rfc2136 => __('Allow dynamic updates for the zone with a TSIG key on your DNS server.'),
            self::Other => __('Enter the provider code and environment variables from the Traefik DNS provider list (go-acme/lego).'),
        };
    }

    /**
     * Environment variables the provider needs. A secret value is never sent back to the browser.
     *
     * @return array<int, array{key: string, label: string, required: bool, secret: bool, default?: string}>
     */
    public function credentialFields(): array
    {
        return match ($this) {
            self::Cloudflare => [
                ['key' => 'CF_DNS_API_TOKEN', 'label' => __('API token'), 'required' => true, 'secret' => true],
            ],
            self::Route53 => [
                ['key' => 'AWS_ACCESS_KEY_ID', 'label' => __('Access key ID'), 'required' => true, 'secret' => true],
                ['key' => 'AWS_SECRET_ACCESS_KEY', 'label' => __('Secret access key'), 'required' => true, 'secret' => true],
                ['key' => 'AWS_REGION', 'label' => __('Region'), 'required' => true, 'secret' => false, 'default' => 'us-east-1'],
                ['key' => 'AWS_HOSTED_ZONE_ID', 'label' => __('Hosted zone ID'), 'required' => false, 'secret' => false],
            ],
            self::Rfc2136 => [
                ['key' => 'RFC2136_NAMESERVER', 'label' => __('Name server (host:port)'), 'required' => true, 'secret' => false],
                ['key' => 'RFC2136_TSIG_KEY', 'label' => __('TSIG key name'), 'required' => true, 'secret' => false],
                ['key' => 'RFC2136_TSIG_SECRET', 'label' => __('TSIG secret'), 'required' => true, 'secret' => true],
                ['key' => 'RFC2136_TSIG_ALGORITHM', 'label' => __('TSIG algorithm'), 'required' => true, 'secret' => false, 'default' => 'hmac-sha256.'],
            ],
            self::Other => [],
        };
    }

    /**
     * The provider selected in the form for a stored Traefik provider code.
     */
    public static function fromCode(?string $code): self
    {
        return self::tryFrom((string) $code) ?? self::Other;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $provider): array => ['value' => $provider->value, 'label' => $provider->label()],
            self::cases(),
        );
    }
}
