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
        Schema::create('election_contests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('election_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key');
            $table->string('name');
            $table->unsignedInteger('seat_count');
            $table->unsignedInteger('sort_order');
            $table->timestamps();

            $table->unique(['election_id', 'key']);
            $table->unique(['election_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('election_contests');
    }
};
