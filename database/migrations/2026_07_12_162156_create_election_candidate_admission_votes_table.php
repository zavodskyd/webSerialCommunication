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
        Schema::create('election_candidate_admission_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('election_candidate_admission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('option_key');
            $table->decimal('weight_snapshot', 12, 2);
            $table->timestamp('voted_at');
            $table->timestamps();

            $table->unique(['election_candidate_admission_id', 'device_id'], 'admission_device_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('election_candidate_admission_votes');
    }
};
