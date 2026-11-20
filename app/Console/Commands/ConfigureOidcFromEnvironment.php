<?php

namespace App\Console\Commands;

use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use Illuminate\Console\Command;

/**
 * Provision the company OIDC provider from environment variables (OIDC_CLIENT_ID,
 * OIDC_CLIENT_SECRET, OIDC_BASE_URL, OIDC_LOGIN_LABEL, OIDC_SCOPES), so an instance can be
 * rolled out with SSO without clicking through Settings → OAuth.
 */
class ConfigureOidcFromEnvironment extends Command
{
    protected $signature = 'platform:configure-oidc
        {--sso-only : Disable password login for everyone except the root user}
        {--allow-registration : Create accounts for new SSO users on first login}
        {--auto-join-root-team : Add new SSO users to the root team}';

    protected $description = 'Configure and enable the OIDC login provider from environment variables';

    public function handle(): int
    {
        $clientId = config('services.oidc.client_id');
        $clientSecret = config('services.oidc.client_secret');
        $baseUrl = config('services.oidc.base_url');

        if (blank($clientId) || blank($clientSecret) || blank($baseUrl)) {
            $this->error('OIDC_CLIENT_ID, OIDC_CLIENT_SECRET and OIDC_BASE_URL must be set.');

            return self::FAILURE;
        }

        $setting = OauthSetting::firstOrNew(['provider' => 'oidc']);
        $setting->fill([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'base_url' => rtrim($baseUrl, '/'),
            'redirect_uri' => config('services.oidc.redirect') ?: route('auth.callback', 'oidc'),
            'custom_label' => config('services.oidc.custom_label') ?: $setting->custom_label,
            'scopes' => config('services.oidc.scopes') ?: ($setting->scopes ?: 'openid email profile'),
            'allow_registration' => $this->option('allow-registration') || (bool) $setting->allow_registration,
            'auto_join_root_team' => $this->option('auto-join-root-team') || (bool) $setting->auto_join_root_team,
            'enabled' => true,
        ]);
        $setting->save();

        if ($this->option('sso-only')) {
            InstanceSettings::get()->update(['is_sso_only_login_enabled' => true]);
        }

        $this->info('OIDC login is enabled. Redirect URI: '.$setting->redirect_uri);
        if ($this->option('sso-only')) {
            $this->info('Password login is now limited to the root user.');
        }

        return self::SUCCESS;
    }
}
