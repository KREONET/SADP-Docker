<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Every team may deploy to the root team's servers, so existing users see them right away.
     * A platform administrator can stop sharing a server on its settings page.
     */
    public function up(): void
    {
        DB::table('servers')->where('team_id', 0)->update(['is_shared' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('servers')->where('team_id', 0)->update(['is_shared' => false]);
    }
};
