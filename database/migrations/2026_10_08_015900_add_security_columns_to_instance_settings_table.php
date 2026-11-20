<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->boolean('is_error_reporting_enabled')->default(false);
            $table->boolean('is_changelog_enabled')->default(false);
            $table->boolean('is_feedback_forwarding_enabled')->default(false);
            $table->text('admin_allowed_ips')->nullable();
            $table->unsignedInteger('audit_log_retention_days')->default(365);
            $table->boolean('do_not_track')->default(true)->change();
        });

        // Security baseline: no telemetry leaves the instance unless an administrator opts in again.
        DB::table('instance_settings')->update(['do_not_track' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn([
                'is_error_reporting_enabled',
                'is_changelog_enabled',
                'is_feedback_forwarding_enabled',
                'admin_allowed_ips',
                'audit_log_retention_days',
            ]);
            $table->boolean('do_not_track')->default(false)->change();
        });
    }
};
