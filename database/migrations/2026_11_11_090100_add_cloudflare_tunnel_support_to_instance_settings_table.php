<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Instance admins can turn Cloudflare Tunnel traffic off for every server and back on.
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->boolean('is_cloudflare_tunnel_allowed')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn('is_cloudflare_tunnel_allowed');
        });
    }
};
