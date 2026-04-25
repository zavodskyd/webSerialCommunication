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
        Schema::table('votings', function (Blueprint $table) {
            $table->foreignId('current_voting_question_id')
                ->nullable()
                ->after('auto_show_results')
                ->constrained('voting_questions')
                ->nullOnDelete();
            $table->unsignedInteger('runtime_remaining_seconds')->default(0)->after('current_voting_question_id');
            $table->boolean('runtime_timer_running')->default(false)->after('runtime_remaining_seconds');
            $table->boolean('runtime_collector_enabled')->default(false)->after('runtime_timer_running');
            $table->boolean('runtime_results_visible')->default(false)->after('runtime_collector_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('votings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_voting_question_id');
            $table->dropColumn([
                'runtime_remaining_seconds',
                'runtime_timer_running',
                'runtime_collector_enabled',
                'runtime_results_visible',
            ]);
        });
    }
};
