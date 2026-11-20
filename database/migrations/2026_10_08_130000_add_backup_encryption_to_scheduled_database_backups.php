<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Backup encryption is opt-in per schedule. Each encrypted execution stores its own random
     * passphrase (encrypted with APP_KEY), so a backup can be decrypted for a restore later.
     */
    public function up(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->boolean('encrypt_backup')->default(false);
        });

        Schema::table('scheduled_database_backup_executions', function (Blueprint $table) {
            $table->boolean('is_encrypted')->default(false);
            $table->text('encryption_passphrase')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_database_backup_executions', function (Blueprint $table) {
            $table->dropColumn(['is_encrypted', 'encryption_passphrase']);
        });

        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->dropColumn('encrypt_backup');
        });
    }
};
