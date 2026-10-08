<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('telegram_notification_settings', 'telegram_notifications_security_alert_thread_id')) {
            Schema::table('telegram_notification_settings', function (Blueprint $table) {
                $table->text('telegram_notifications_security_alert_thread_id')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('telegram_notification_settings', 'telegram_notifications_security_alert_thread_id')) {
            Schema::table('telegram_notification_settings', function (Blueprint $table) {
                $table->dropColumn('telegram_notifications_security_alert_thread_id');
            });
        }
    }
};
