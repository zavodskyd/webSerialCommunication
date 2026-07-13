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
        Schema::table('vote_events', function (Blueprint $table): void {
            $table->foreignId('election_candidate_admission_id')
                ->nullable()
                ->after('voting_question_id')
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('election_round_id')
                ->nullable()
                ->after('election_candidate_admission_id')
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('election_round_candidate_id')
                ->nullable()
                ->after('election_round_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vote_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('election_round_candidate_id');
            $table->dropConstrainedForeignId('election_round_id');
            $table->dropConstrainedForeignId('election_candidate_admission_id');
        });
    }
};
