<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'authentik' => [
        'base_url' => env('AUTHENTIK_BASE_URL'),
        'client_id' => env('AUTHENTIK_CLIENT_ID'),
        'client_secret' => env('AUTHENTIK_CLIENT_SECRET'),
        'redirect' => env('AUTHENTIK_REDIRECT_URI'),
    ],

    'oidc' => [
        'client_id' => env('OIDC_CLIENT_ID'),
        'client_secret' => env('OIDC_CLIENT_SECRET'),
        'redirect' => env('OIDC_REDIRECT_URI'),
        'base_url' => env('OIDC_BASE_URL'),
        'custom_label' => env('OIDC_LOGIN_LABEL'),
        'scopes' => env('OIDC_SCOPES'),
    ],

    /*
    | Domain Connect (Cloudflare automatic DNS).
    | Template: resources/domain-connect/coolify.io.hosting.json
    | Publish public key TXT at {key_id}.{syncPubKeyDomain} and onboard with Cloudflare.
    | @see https://developers.cloudflare.com/dns/reference/domain-connect/
    */
    'domain_connect' => [
        'provider_id' => env('DOMAIN_CONNECT_PROVIDER_ID', 'coolify.io'),
        'service_id' => env('DOMAIN_CONNECT_SERVICE_ID', 'hosting'),
        'key_id' => env('DOMAIN_CONNECT_KEY_ID', '_dcpubkeyv1'),
        'private_key' => env('DOMAIN_CONNECT_PRIVATE_KEY'),
    ],

];
