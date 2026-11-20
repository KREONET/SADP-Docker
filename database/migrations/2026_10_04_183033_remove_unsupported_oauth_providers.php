<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PADED signs users in only through OpenID Connect and Authentik. Deletes the settings (with their
 * client secrets) of every other login provider, and the identities linked through them: a stale
 * identity would otherwise block linking the same account to OIDC by email.
 */
return new class extends Migration
{
    private const SUPPORTED_PROVIDERS = ['oidc', 'authentik'];

    public function up(): void
    {
        DB::table('oauth_identities')->whereNotIn('provider', self::SUPPORTED_PROVIDERS)->delete();
        DB::table('oauth_settings')->whereNotIn('provider', self::SUPPORTED_PROVIDERS)->delete();
    }

    /**
     * The deleted settings and identities cannot be restored.
     */
    public function down(): void {}
};
