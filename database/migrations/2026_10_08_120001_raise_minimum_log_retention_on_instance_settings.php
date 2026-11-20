<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * System and container logs must be kept for at least `security.audit.system_log_min_retention_days`.
     */
    public function up(): void
    {
        $minimum = (int) config('security.audit.system_log_min_retention_days', 180);

        Schema::table('instance_settings', function (Blueprint $table) use ($minimum): void {
            $table->unsignedSmallInteger('log_retention_days')->default($minimum)->change();
        });

        DB::table('instance_settings')
            ->where(fn ($query) => $query->whereNull('log_retention_days')->orWhere('log_retention_days', '<', $minimum))
            ->update(['log_retention_days' => $minimum]);
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('log_retention_days')->default(7)->change();
        });
    }
};
