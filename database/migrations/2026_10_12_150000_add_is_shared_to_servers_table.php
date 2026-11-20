<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A shared server stays owned and managed by its team, but every team may deploy resources to it.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('servers', 'is_shared')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->boolean('is_shared')->default(false);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('servers', 'is_shared')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->dropColumn('is_shared');
            });
        }
    }
};
