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
        Schema::table('device_groups', function (Blueprint $table): void {
            $table->unsignedInteger('quorum_participant_count')->nullable()->after('is_active');
        });

        Schema::table('election_candidate_admissions', function (Blueprint $table): void {
            $table->unsignedInteger('quorum_participant_count_snapshot')->nullable()->after('eligible_weight_total');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('election_candidate_admissions', function (Blueprint $table): void {
            $table->dropColumn('quorum_participant_count_snapshot');
        });

        Schema::table('device_groups', function (Blueprint $table): void {
            $table->dropColumn('quorum_participant_count');
        });
    }
};
