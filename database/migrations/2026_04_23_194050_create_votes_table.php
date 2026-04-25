<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voting_question_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('device_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('voting_option_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->string('option_key', 1);
            $table->decimal('weight_snapshot', 8, 2);
            $table->timestamp('voted_at');
            $table->timestamps();

            $table->unique(['voting_question_id', 'device_id']);
            $table->index(['voting_question_id', 'option_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('votes');
    }
};
