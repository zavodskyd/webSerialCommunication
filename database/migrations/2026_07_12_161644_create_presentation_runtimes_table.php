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
        Schema::create('presentation_runtimes', function (Blueprint $table) {
            $table->id();
            $table->string('runtime_key')->unique();
            $table->foreignId('voting_id')->nullable()->constrained()->nullOnDelete();
            $table->string('content_type')->default('none')->index();
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('presentation_runtimes');
    }
};
