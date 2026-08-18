<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('election_candidate_admissions', function (Blueprint $table) {
            $table->foreignId('created_election_candidate_id')
                ->nullable()
                ->after('election_contest_id')
                ->constrained('election_candidates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('election_candidate_admissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_election_candidate_id');
        });
    }
};
