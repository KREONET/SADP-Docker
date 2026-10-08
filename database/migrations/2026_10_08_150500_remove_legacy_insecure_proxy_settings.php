<?php

use App\Models\Server;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Save each server on its own. In Postgres one failed update aborts a shared transaction and stops the upgrade.
     */
    public $withinTransaction = false;

    /**
     * Remove the published Traefik API port, --api.insecure=true and (unless HTTP/3 is enabled) the HTTP/3 settings
     * from saved proxy configurations. Only the database is changed. The proxy shows a restart notice and applies the
     * fix on the next restart.
     */
    public function up(): void
    {
        Server::query()->chunkById(100, function ($servers) {
            foreach ($servers as $server) {
                try {
                    removeLegacyInsecureProxyExposure($server);
                } catch (Throwable $e) {
                    Log::warning('Could not remove legacy insecure proxy settings', [
                        'server_id' => $server->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        //
    }
};
