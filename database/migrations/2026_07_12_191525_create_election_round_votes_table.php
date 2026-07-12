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
        if (Schema::hasTable('election_round_votes')) {
            return;
        }

        Schema::create('election_round_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('election_round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('election_round_candidate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->decimal('weight_snapshot', 12, 2);
            $table->timestamp('voted_at');
            $table->timestamps();

            $table->unique(['election_round_candidate_id', 'device_id']);
            $table->index(['election_round_id', 'device_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('election_round_votes');
    }
};
