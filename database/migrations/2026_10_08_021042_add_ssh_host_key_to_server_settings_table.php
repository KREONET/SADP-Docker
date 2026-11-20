<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The trusted SSH host key lives next to the other per-server connection
     * settings (connection_timeout, is_cloudflare_tunnel). Existing servers start
     * without a trusted key and learn it on their next connection.
     */
    public function up(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->text('ssh_host_key_fingerprint')->nullable();
            $table->text('ssh_host_keys')->nullable();
            $table->timestamp('ssh_host_key_trusted_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn([
                'ssh_host_key_fingerprint',
                'ssh_host_keys',
                'ssh_host_key_trusted_at',
            ]);
        });
    }
};
