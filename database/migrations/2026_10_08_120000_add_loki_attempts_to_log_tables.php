<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Counts batches Loki rejected permanently, so rejected rows stay unshipped but stop blocking newer rows.
     */
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->unsignedSmallInteger('loki_attempts')->default(0);
        });

        Schema::table('log_entries', function (Blueprint $table): void {
            $table->unsignedSmallInteger('loki_attempts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropColumn('loki_attempts');
        });

        Schema::table('log_entries', function (Blueprint $table): void {
            $table->dropColumn('loki_attempts');
        });
    }
};
