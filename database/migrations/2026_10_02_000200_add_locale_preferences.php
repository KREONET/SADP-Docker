<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Null follows the instance default language.
            $table->string('locale', 10)->nullable();
        });

        Schema::table('instance_settings', function (Blueprint $table) {
            $table->string('default_locale', 10)->default('en');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });

        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn('default_locale');
        });
    }
};
