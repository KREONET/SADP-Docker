<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private array $columns = [
        'email_notification_settings' => 'security_alert_email_notifications',
        'discord_notification_settings' => 'security_alert_discord_notifications',
        'telegram_notification_settings' => 'security_alert_telegram_notifications',
        'slack_notification_settings' => 'security_alert_slack_notifications',
        'pushover_notification_settings' => 'security_alert_pushover_notifications',
        'webhook_notification_settings' => 'security_alert_webhook_notifications',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->columns as $table => $column) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
                continue;
            }

            Schema::table($table, function (Blueprint $table) use ($column) {
                $table->boolean($column)->default(true);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->columns as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            Schema::table($table, function (Blueprint $table) use ($column) {
                $table->dropColumn($column);
            });
        }
    }
};
