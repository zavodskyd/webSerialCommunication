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
        Schema::table('election_rounds', function (Blueprint $table): void {
            $table->unsignedInteger('quorum_participant_count_snapshot')->nullable()->after('eligible_weight_total');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('election_rounds', function (Blueprint $table): void {
            $table->dropColumn('quorum_participant_count_snapshot');
        });
    }
};
