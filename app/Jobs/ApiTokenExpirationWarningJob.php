<?php

namespace App\Jobs;

use App\Models\PersonalAccessToken;
use App\Models\Team;
use App\Models\User;
use App\Notifications\ApiTokenExpiringNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Laravel\Horizon\Contracts\Silenced;

class ApiTokenExpirationWarningJob implements ShouldBeEncrypted, ShouldQueue, Silenced
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 120;

    private const WARNING_DAYS = 7;

    public function handle(): void
    {
        $now = now();
        $until = $now->copy()->addDays(self::WARNING_DAYS);
        $maxDays = PersonalAccessToken::maxLifetimeDays();

        PersonalAccessToken::query()
            ->where(function (Builder $query) use ($now, $until, $maxDays) {
                $query->whereBetween('expires_at', [$now, $until]);
                if ($maxDays > 0) {
                    $query->orWhereBetween('created_at', [$now->copy()->subDays($maxDays), $until->copy()->subDays($maxDays)]);
                }
            })
            ->whereNull('api_token_expiration_warning_sent_at')
            ->where('tokenable_type', User::class)
            ->chunkById(100, function ($tokens) {
                foreach ($tokens as $token) {
                    if ($token->team_id === null || ! $this->isDue($token)) {
                        continue;
                    }

                    $team = Team::find($token->team_id);
                    if (! $team) {
                        continue;
                    }

                    if (! $team->members()->whereKey($token->tokenable_id)->exists()) {
                        continue;
                    }

                    $warningSentAt = now();

                    $team->notify(new ApiTokenExpiringNotification($token));

                    $markedAsSent = PersonalAccessToken::query()
                        ->whereKey($token->getKey())
                        ->where('expires_at', $token->expires_at)
                        ->where('created_at', $token->created_at)
                        ->whereNull('api_token_expiration_warning_sent_at')
                        ->update(['api_token_expiration_warning_sent_at' => $warningSentAt]);

                    if ($markedAsSent !== 1) {
                        continue;
                    }

                    $token->forceFill(['api_token_expiration_warning_sent_at' => $warningSentAt]);
                }
            });
    }

    /**
     * Warn 7 days before expiry. A token that lives 7 days or less gets the warning 24 hours before expiry,
     * so it is not warned right after it is created.
     */
    private function isDue(PersonalAccessToken $token): bool
    {
        $expiry = $token->effectiveExpiresAt();
        if ($token->created_at === null || $expiry === null || ! $expiry->isFuture()) {
            return false;
        }

        $warningDays = $token->created_at->diffInDays($expiry) > self::WARNING_DAYS ? self::WARNING_DAYS : 1;

        return $expiry->lte(now()->addDays($warningDays));
    }
}
