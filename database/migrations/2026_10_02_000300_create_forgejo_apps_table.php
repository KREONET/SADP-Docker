<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forgejo_apps', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('name');
            $table->string('html_url');
            $table->string('login')->nullable();
            $table->text('access_token');
            $table->boolean('is_system_wide')->default(true);
            $table->unsignedBigInteger('team_id')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forgejo_apps');
    }
};
