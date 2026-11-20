<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->text('ssh_host_key')->nullable();
            $table->string('ssh_host_key_source')->nullable();
            $table->text('ssh_host_key_pending')->nullable();
            $table->boolean('is_ssh_host_key_check_disabled')->default(false);
        });

        DB::table('server_settings')->whereNotNull('ssh_host_keys')->update([
            'ssh_host_key' => DB::raw('ssh_host_keys'),
            'ssh_host_key_source' => 'upgrade',
        ]);
    }

    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn([
                'ssh_host_key',
                'ssh_host_key_source',
                'ssh_host_key_pending',
                'is_ssh_host_key_check_disabled',
            ]);
        });
    }
};
