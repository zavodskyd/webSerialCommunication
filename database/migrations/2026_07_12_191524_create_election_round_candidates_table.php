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
        Schema::create('election_round_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('election_round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('election_candidate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->unsignedInteger('sort_order');
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['election_round_id', 'election_candidate_id']);
            $table->index(['election_round_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('election_round_candidates');
    }
};
