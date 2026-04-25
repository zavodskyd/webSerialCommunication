<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('votings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status')->default('draft');
            $table->string('question_label')->default('Hlasovanie');
            $table->string('title')->nullable();
            $table->text('header_text')->nullable();
            $table->string('logo_path')->nullable();
            $table->unsignedInteger('default_response_time_seconds')->default(30);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('votings');
    }
};
