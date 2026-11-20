<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PADED Swarm clusters run workloads on the manager node too, so a single-server cluster must
     * be able to place applications. Applications that already run on Swarm keep their setting.
     */
    public function up(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->boolean('is_swarm_only_worker_nodes')->default(false)->change();
        });

        DB::table('application_settings')
            ->whereNotIn('application_id', DB::table('applications')
                ->where('destination_type', 'App\\Models\\SwarmDocker')
                ->select('id'))
            ->update(['is_swarm_only_worker_nodes' => false]);
    }

    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->boolean('is_swarm_only_worker_nodes')->default(true)->change();
        });
    }
};
