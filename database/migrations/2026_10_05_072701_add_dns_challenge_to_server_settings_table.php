<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->boolean('is_dns_challenge_enabled')->default(false);
            $table->string('dns_challenge_domain')->nullable();
            $table->string('dns_challenge_provider')->nullable();
            $table->text('dns_challenge_credentials')->nullable();
            $table->string('dns_challenge_email')->nullable();
            $table->string('dns_challenge_resolvers')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn([
                'is_dns_challenge_enabled',
                'dns_challenge_domain',
                'dns_challenge_provider',
                'dns_challenge_credentials',
                'dns_challenge_email',
                'dns_challenge_resolvers',
            ]);
        });
    }
};
