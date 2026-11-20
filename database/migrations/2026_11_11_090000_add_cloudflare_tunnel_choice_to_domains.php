<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per resource: public traffic arrives directly (public IP, proxy TLS) or through the server's
        // Cloudflare Tunnel (Cloudflare TLS, plain HTTP to the proxy).
        Schema::table('application_settings', function (Blueprint $table) {
            $table->boolean('is_cloudflare_tunnel_enabled')->default(false);
        });
        Schema::table('service_applications', function (Blueprint $table) {
            $table->boolean('is_cloudflare_tunnel_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn('is_cloudflare_tunnel_enabled');
        });
        Schema::table('service_applications', function (Blueprint $table) {
            $table->dropColumn('is_cloudflare_tunnel_enabled');
        });
    }
};
