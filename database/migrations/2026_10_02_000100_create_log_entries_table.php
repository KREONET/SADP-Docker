<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 32);
            $table->string('level', 16);
            $table->text('message');
            $table->json('context')->nullable();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->unsignedBigInteger('server_id')->nullable();
            $table->string('resource_type', 32)->nullable();
            $table->string('resource_uuid')->nullable();
            $table->string('resource_name')->nullable();
            $table->string('container_name')->nullable();
            $table->timestamp('logged_at', 6);
            $table->timestamp('loki_shipped_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['logged_at', 'id']);
            $table->index(['source', 'logged_at']);
            $table->index(['server_id', 'container_name', 'logged_at']);
            $table->index(['loki_shipped_at', 'id']);
            $table->index('created_at');
        });

        Schema::table('audit_events', function (Blueprint $table): void {
            $table->timestamp('loki_shipped_at')->nullable();
            $table->index(['loki_shipped_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropIndex(['loki_shipped_at', 'id']);
            $table->dropColumn('loki_shipped_at');
        });

        Schema::dropIfExists('log_entries');
    }
};
