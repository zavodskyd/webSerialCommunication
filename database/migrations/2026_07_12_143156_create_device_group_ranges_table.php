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
        Schema::create('device_group_ranges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_group_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('start_number');
            $table->unsignedInteger('end_number');
            $table->timestamps();

            $table->index(['device_group_id', 'start_number', 'end_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_group_ranges');
    }
};
