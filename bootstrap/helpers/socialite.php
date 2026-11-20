<?php

use App\Auth\Oidc\OidcConfig;
use App\Models\OauthSetting;
use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Manager\Config;

/**
 * Callback URL built from the instance URL, never from the request Host header.
 */
function oauth_default_redirect_uri(string $provider): string
{
    return rtrim(base_url(), '/').route('auth.callback', $provider, false);
}

/**
 * Socialite driver for a login provider. SADP-Docker supports only OpenID Connect and Authentik.
 */
function get_socialite_provider(string $provider)
{
    if (! OauthSetting::isSupported($provider)) {
        throw new InvalidArgumentException("Unsupported login provider: {$provider}");
    }

    $oauth_setting = OauthSetting::firstWhere('provider', $provider);

    if (! filled($oauth_setting->redirect_uri)) {
        $oauth_setting->redirect_uri = oauth_default_redirect_uri($provider);
    }

    if ($provider === 'oidc') {
        return Socialite::driver('oidc')->setConfig(OidcConfig::fromOauthSetting($oauth_setting));
    }

    return Socialite::driver('authentik')->setConfig(new Config(
        $oauth_setting->client_id,
        $oauth_setting->client_secret,
        $oauth_setting->redirect_uri,
        ['base_url' => $oauth_setting->base_url],
    ));
}
