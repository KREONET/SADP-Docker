<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes versions.json (Sentinel, helper and Traefik versions).
 *
 * This distribution is not updated from the upstream Coolify CDN, so the job never flags a new
 * panel version and never triggers an automatic upgrade.
 */
class CheckForUpdatesJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A manual check runs in the web request, so it does not wait for CDN retries. */
    public int $cdnTries = 3;

    public function __construct(int $cdnTries = 3)
    {
        $this->cdnTries = $cdnTries;
    }

    /**
     * @return bool Whether versions.json was refreshed
     */
    public function handle(): bool
    {
        try {
            if (isDev() || isCloud()) {
                return false;
            }
            $versions = refresh_versions_file($this->cdnTries);
            if ($versions === null) {
                return false;
            }

            CheckTraefikVersionJob::dispatch();

            return true;
        } catch (\Throwable $e) {
            Log::warning('Could not refresh versions.json', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
