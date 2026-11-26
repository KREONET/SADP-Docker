<?php

namespace App\Models;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'api_token_expiration_warning_sent_at',
        'team_id',
        'scopes',
        'paused_at',
    ];

    protected function casts(): array
    {
        return [
            'api_token_expiration_warning_sent_at' => 'datetime',
            'scopes' => 'array',
            'paused_at' => 'datetime',
        ];
    }

    /**
     * A paused token is kept, but it does not authenticate requests until it is resumed.
     */
    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }

    public static function maxLifetimeDays(): int
    {
        return max(0, (int) config('security.api_tokens.max_lifetime_days'));
    }

    public function effectiveExpiresAt(): ?Carbon
    {
        $maxDays = self::maxLifetimeDays();
        if ($maxDays === 0 || $this->created_at === null) {
            return $this->expires_at;
        }

        $deadline = $this->created_at->copy()->addDays($maxDays);

        return $this->expires_at === null ? $deadline : $deadline->min($this->expires_at);
    }

    public function isExpired(): bool
    {
        return $this->created_at === null || $this->effectiveExpiresAt()?->lte(now()) === true;
    }

    public static function generateSecret(): string
    {
        return sprintf(
            '%s%s%s',
            config('sanctum.token_prefix', ''),
            $tokenEntropy = Str::random(40),
            hash('crc32b', $tokenEntropy)
        );
    }

    /**
     * Replace the secret and keep the abilities, scopes, and expiry. The old secret stops working at once.
     *
     * @return string the new plain-text token
     */
    public function roll(): string
    {
        $secret = self::generateSecret();
        $this->forceFill(['token' => hash('sha256', $secret), 'last_used_at' => null])->save();

        return $this->getKey().'|'.$secret;
    }
}
