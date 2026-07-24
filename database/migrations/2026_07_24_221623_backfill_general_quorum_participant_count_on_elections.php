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
        DB::table('elections')
            ->whereNull('quorum_participant_count')
            ->update(['quorum_participant_count' => DB::raw('weight_one_device_count')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
