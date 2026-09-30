<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Once;
use Spatie\Url\Url;

class InstanceSettings extends Model
{
    protected $attributes = [
        'is_dashboard_force_https_enabled' => true,
    ];

    protected $fillable = [
        'public_ipv4',
        'public_ipv6',
        'fqdn',
        'public_port_min',
        'public_port_max',
        'do_not_track',
        'is_auto_update_enabled',
        'is_registration_enabled',
        'disable_registration_when_oauth_enabled',
        'next_channel',
        'smtp_enabled',
        'smtp_from_address',
        'smtp_from_name',
        'smtp_recipients',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'smtp_password',
        'smtp_timeout',
        'smtp_ehlo_domain',
        'resend_enabled',
        'resend_api_key',
        'is_dns_validation_enabled',
        'custom_dns_servers',
        'domain_connect_private_key',
        'instance_name',
        'is_api_enabled',
        'allowed_ips',
        'auto_update_frequency',
        'update_check_frequency',
        'new_version_available',
        'instance_timezone',
        'helper_version',
        'disable_two_step_confirmation',
        'is_sponsorship_popup_enabled',
        'dev_helper_version',
        'is_wire_navigate_enabled',
        'is_mcp_server_enabled',
        'webhook_allowed_internal_hosts',
        'webhook_allow_localhost',
        'avatar_storage_type',
        'avatar_s3_storage_id',
        'image_cdn_url',
        'is_dashboard_force_https_enabled',
        'is_sso_only_login_enabled',
        'is_source_allowlist_enabled',
        'allowed_git_sources',
        'allowed_docker_registries',
        'forgejo_base_url',
        'forgejo_bot_token',
        'forgejo_webhook_secret',
        'is_trivy_scan_enabled',
        'trivy_image',
        'trivy_severities',
        'trivy_ignore_unfixed',
        'trivy_block_on_findings',
        'trivy_report_to_repository',
    ];

    protected $hidden = [
        'smtp_from_address',
        'smtp_from_name',
        'smtp_recipients',
        'smtp_host',
        'smtp_username',
        'smtp_password',
        'resend_api_key',
        'domain_connect_private_key',
        'sentinel_token',
        'forgejo_bot_token',
        'forgejo_webhook_secret',
    ];

    protected $casts = [
        'smtp_enabled' => 'boolean',
        'smtp_from_address' => 'encrypted',
        'smtp_from_name' => 'encrypted',
        'smtp_recipients' => 'encrypted',
        'smtp_host' => 'encrypted',
        'smtp_port' => 'integer',
        'smtp_username' => 'encrypted',
        'smtp_password' => 'encrypted',
        'smtp_timeout' => 'integer',

        'resend_enabled' => 'boolean',
        'resend_api_key' => 'encrypted',
        'domain_connect_private_key' => 'encrypted',

        'allowed_ip_ranges' => 'array',
        'is_auto_update_enabled' => 'boolean',
        'is_registration_enabled' => 'boolean',
        'disable_registration_when_oauth_enabled' => 'boolean',
        'auto_update_frequency' => 'string',
        'update_check_frequency' => 'string',
        'sentinel_token' => 'encrypted',
        'is_wire_navigate_enabled' => 'boolean',
        'is_mcp_server_enabled' => 'boolean',
        'webhook_allowed_internal_hosts' => 'array',
        'webhook_allow_localhost' => 'boolean',
        'is_dashboard_force_https_enabled' => 'boolean',
        'is_sso_only_login_enabled' => 'boolean',
        'is_source_allowlist_enabled' => 'boolean',
        'forgejo_bot_token' => 'encrypted',
        'forgejo_webhook_secret' => 'encrypted',
        'is_trivy_scan_enabled' => 'boolean',
        'trivy_ignore_unfixed' => 'boolean',
        'trivy_block_on_findings' => 'boolean',
        'trivy_report_to_repository' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::created(function () {
            Once::flush();
        });

        static::updated(function ($settings) {
            // Clear once() cache so subsequent calls get fresh data
            Once::flush();
        });
    }

    public function isPasswordRegistrationAllowed(): bool
    {
        if (! $this->is_registration_enabled || $this->isSsoOnlyLoginActive()) {
            return false;
        }

        if (! $this->disable_registration_when_oauth_enabled) {
            return true;
        }

        return ! OauthSetting::where('enabled', true)->exists();
    }

    /**
     * SSO-only login is active only while at least one OAuth provider is enabled,
     * so a misconfiguration can never lock every user out of the instance.
     */
    public function isSsoOnlyLoginActive(): bool
    {
        if (! $this->is_sso_only_login_enabled) {
            return false;
        }

        return OauthSetting::where('enabled', true)->exists();
    }

    /**
     * The root user (id 0) keeps password login as a break-glass account.
     */
    public function isPasswordLoginAllowedFor(?User $user): bool
    {
        if (! $this->isSsoOnlyLoginActive()) {
            return true;
        }

        return $user !== null && $user->id === 0;
    }

    public function fqdn(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if ($value) {
                    $url = Url::fromString($value);
                    $host = $url->getHost();

                    return $url->getScheme().'://'.$host;
                }
            }
        );
    }

    public function updateCheckFrequency(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                return translate_cron_expression($value);
            },
            get: function ($value) {
                return translate_cron_expression($value);
            }
        );
    }

    public function autoUpdateFrequency(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                return translate_cron_expression($value);
            },
            get: function ($value) {
                return translate_cron_expression($value);
            }
        );
    }

    public static function get()
    {
        return once(fn () => InstanceSettings::findOrFail(0));
    }

    // public function getRecipients($notification)
    // {
    //     $recipients = data_get($notification, 'emails', null);
    //     if (is_null($recipients) || $recipients === '') {
    //         return [];
    //     }

    //     return explode(',', $recipients);
    // }

    public function getTitleDisplayName(): string
    {
        $instanceName = $this->instance_name;
        if (! $instanceName) {
            return '';
        }

        return "[{$instanceName}]";
    }

    // public function helperVersion(): Attribute
    // {
    //     return Attribute::make(
    //         get: function ($value) {
    //             if (isDev()) {
    //                 return 'latest';
    //             }

    //             return $value;
    //         }
    //     );
    // }
}
