<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voting_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voting_question_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('key', 1);
            $table->string('label');
            $table->string('color', 32)->nullable();
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamps();

            $table->unique(['voting_question_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voting_options');
    }
};
