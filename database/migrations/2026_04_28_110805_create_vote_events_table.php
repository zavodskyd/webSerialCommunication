<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vote_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('voting_question_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('raw_hex', 32);
            $table->string('source', 16)->default('web-serial');
            $table->string('button_name', 8)->nullable();
            $table->boolean('accepted')->default(false);
            $table->string('rejection_reason', 32)->nullable();
            $table->timestamp('received_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vote_events');
    }
};
