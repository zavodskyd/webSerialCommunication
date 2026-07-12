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
        Schema::create('election_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('election_contest_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('round_number');
            $table->string('status')->default('draft')->index();
            $table->unsignedInteger('response_time_seconds')->default(30);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['election_contest_id', 'round_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('election_rounds');
    }
};
