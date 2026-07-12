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
        Schema::table('election_candidate_admissions', function (Blueprint $table) {
            $table->unsignedInteger('response_time_seconds')->default(30)->after('status');
            $table->timestamp('closed_at')->nullable()->after('opened_at');
            $table->boolean('results_visible')->default(false)->after('resolved_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('election_candidate_admissions', function (Blueprint $table) {
            $table->dropColumn(['response_time_seconds', 'closed_at', 'results_visible']);
        });
    }
};
