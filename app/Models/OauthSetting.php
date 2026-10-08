<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class OauthSetting extends Model
{
    /**
     * Login providers PADED offers. Other providers were removed and their settings deleted.
     *
     * @var list<string>
     */
    public const SUPPORTED_PROVIDERS = ['oidc', 'authentik'];

    use HasFactory;

    protected $fillable = ['provider', 'client_id', 'client_secret', 'redirect_uri', 'tenant', 'base_url', 'enabled', 'custom_label', 'scopes', 'allow_registration', 'auto_join_root_team', 'require_email_verified', 'use_pkce', 'clock_skew_seconds'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'allow_registration' => 'boolean',
            'auto_join_root_team' => 'boolean',
            'require_email_verified' => 'boolean',
            'use_pkce' => 'boolean',
            'clock_skew_seconds' => 'integer',
        ];
    }

    protected $hidden = [
        'client_secret',
        'scim_token_hash',
    ];

    protected function clientSecret(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => empty($value) ? null : Crypt::decryptString($value),
            set: fn (?string $value) => empty($value) ? null : Crypt::encryptString($value),
        );
    }

    public function couldBeEnabled(): bool
    {
        return static::isSupported($this->provider)
            && filled($this->client_id) && filled($this->client_secret) && filled($this->base_url);
    }

    /**
     * PADED signs users in only through OpenID Connect and Authentik.
     */
    public static function isSupported(?string $provider): bool
    {
        return in_array($provider, self::SUPPORTED_PROVIDERS, true);
    }

    /**
     * @return array<int, string>
     */
    public function scopeList(): array
    {
        $scopes = str($this->scopes ?: 'openid email profile')
            ->replace(',', ' ')
            ->explode(' ')
            ->map(fn (string $scope) => trim($scope))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $scopes === [] ? ['openid', 'email', 'profile'] : $scopes;
    }

    public function loginLabel(): string
    {
        if (filled($this->custom_label)) {
            return $this->custom_label;
        }

        $envLabel = config("services.{$this->provider}.custom_label");
        if (filled($envLabel)) {
            return $envLabel;
        }

        return __("auth.login.{$this->provider}");
    }

    public function isOidc(): bool
    {
        return $this->provider === 'oidc';
    }

    /**
     * Create a new SCIM bearer token for the OIDC provider. Only its SHA-256 hash is stored,
     * so the returned plaintext can be shown once.
     */
    public function issueScimToken(): string
    {
        $token = 'scim_'.Str::random(48);
        $this->forceFill(['scim_token_hash' => hash('sha256', $token)])->save();

        return $token;
    }

    public function revokeScimToken(): void
    {
        $this->forceFill(['scim_token_hash' => null])->save();
    }

    public function hasScimToken(): bool
    {
        return filled($this->scim_token_hash);
    }

    /**
     * The SCIM endpoint accepts a token only when the OIDC provider has one issued.
     */
    public static function scimTokenMatches(?string $token): bool
    {
        if (blank($token)) {
            return false;
        }

        $hash = static::query()->where('provider', 'oidc')->value('scim_token_hash');

        return filled($hash) && hash_equals($hash, hash('sha256', $token));
    }
}
