<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            // Worker servers on the internal network whose traffic enters through the controller's proxy.
            $table->boolean('is_routed_through_controller')->default(false);
            $table->string('internal_ip')->nullable();
            // Public traffic for this server's proxy arrives through a Cloudflare Tunnel.
            $table->boolean('is_cloudflare_tunnel_ingress_enabled')->default(false);
            $table->text('cloudflare_tunnel_ingress_token')->nullable();
        });

        // One row per container on a worker that the controller's proxy routes to.
        Schema::create('controller_ingress_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('resource_key');
            $table->string('service_name');
            $table->string('container_name');
            $table->nullableMorphs('resource');
            $table->json('ports');
            $table->json('labels');
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->unique(['server_id', 'container_name']);
            $table->index('resource_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('controller_ingress_routes');

        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn([
                'is_routed_through_controller',
                'internal_ip',
                'is_cloudflare_tunnel_ingress_enabled',
                'cloudflare_tunnel_ingress_token',
            ]);
        });
    }
};
