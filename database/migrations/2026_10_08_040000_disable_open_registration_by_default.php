<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New installations and new OIDC providers no longer allow self-registration by default.
 * Existing rows keep their current values; an administrator opts in explicitly.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->boolean('is_registration_enabled')->default(false)->change();
        });

        Schema::table('oauth_settings', function (Blueprint $table) {
            $table->boolean('allow_registration')->default(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->boolean('is_registration_enabled')->default(true)->change();
        });

        Schema::table('oauth_settings', function (Blueprint $table) {
            $table->boolean('allow_registration')->default(true)->change();
        });
    }
};
