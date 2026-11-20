<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->boolean('is_sso_only_login_enabled')->default(false);

            $table->boolean('is_source_allowlist_enabled')->default(false);
            $table->text('allowed_git_sources')->nullable();
            $table->text('allowed_docker_registries')->nullable();

            $table->string('forgejo_base_url')->nullable();
            $table->text('forgejo_bot_token')->nullable();
            $table->text('forgejo_webhook_secret')->nullable();

            $table->boolean('is_trivy_scan_enabled')->default(false);
            $table->string('trivy_image')->default('aquasec/trivy:latest');
            $table->string('trivy_severities')->default('HIGH,CRITICAL');
            $table->boolean('trivy_ignore_unfixed')->default(false);
            $table->boolean('trivy_block_on_findings')->default(true);
            $table->boolean('trivy_report_to_repository')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn([
                'is_sso_only_login_enabled',
                'is_source_allowlist_enabled',
                'allowed_git_sources',
                'allowed_docker_registries',
                'forgejo_base_url',
                'forgejo_bot_token',
                'forgejo_webhook_secret',
                'is_trivy_scan_enabled',
                'trivy_image',
                'trivy_severities',
                'trivy_ignore_unfixed',
                'trivy_block_on_findings',
                'trivy_report_to_repository',
            ]);
        });
    }
};
