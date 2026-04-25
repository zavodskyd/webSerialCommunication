<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voting_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voting_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('device_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->decimal('weight', 8, 2)->default(0);
            $table->boolean('is_present')->default(true);
            $table->boolean('can_vote')->default(true);
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();

            $table->unique(['voting_id', 'device_id']);
            $table->index(['voting_id', 'is_present', 'can_vote']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voting_attendees');
    }
};
