<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Records which user started a deployment, so the deployment job can decide whether the user may deploy
     * repository Compose settings that give containers access to the host. Webhook deployments have no user.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('application_deployment_queues', 'triggered_by_user_id')) {
            Schema::table('application_deployment_queues', function (Blueprint $table) {
                $table->unsignedBigInteger('triggered_by_user_id')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('application_deployment_queues', 'triggered_by_user_id')) {
            Schema::table('application_deployment_queues', function (Blueprint $table) {
                $table->dropColumn('triggered_by_user_id');
            });
        }
    }
};
