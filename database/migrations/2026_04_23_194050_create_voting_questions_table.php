<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voting_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voting_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->unsignedInteger('order');
            $table->string('status')->default('draft');
            $table->string('label')->nullable();
            $table->text('text');
            $table->unsignedInteger('response_time_seconds');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['voting_id', 'order']);
            $table->index(['voting_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voting_questions');
    }
};
