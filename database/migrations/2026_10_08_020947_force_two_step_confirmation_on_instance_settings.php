<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Security baseline: destructive actions always require text and password confirmation.
        // The setting is no longer exposed in the UI, so no instance may keep the bypass enabled.
        DB::table('instance_settings')->update(['disable_two_step_confirmation' => false]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Previous values are not restored: re-enabling the bypass would weaken confirmation.
    }
};
