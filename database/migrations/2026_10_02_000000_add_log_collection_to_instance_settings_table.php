<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->boolean('is_log_collection_enabled')->default(false);
            $table->boolean('is_audit_log_collection_enabled')->default(true);
            $table->boolean('is_system_log_collection_enabled')->default(true);
            $table->boolean('is_container_log_collection_enabled')->default(false);
            $table->unsignedSmallInteger('log_retention_days')->default(7);

            $table->boolean('is_loki_shipping_enabled')->default(false);
            $table->string('loki_url')->nullable();
            $table->string('loki_username')->nullable();
            $table->text('loki_password')->nullable();
            $table->string('loki_tenant_id')->nullable();
            $table->timestamp('loki_shipping_started_at')->nullable();
            $table->timestamp('loki_last_pushed_at')->nullable();
            $table->text('loki_last_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn([
                'is_log_collection_enabled',
                'is_audit_log_collection_enabled',
                'is_system_log_collection_enabled',
                'is_container_log_collection_enabled',
                'log_retention_days',
                'is_loki_shipping_enabled',
                'loki_url',
                'loki_username',
                'loki_password',
                'loki_tenant_id',
                'loki_shipping_started_at',
                'loki_last_pushed_at',
                'loki_last_error',
            ]);
        });
    }
};
