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
            $table->string('voting_type')->default('standard')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('votings', function (Blueprint $table) {
            $table->dropIndex(['voting_type']);
            $table->dropColumn('voting_type');
        });
    }
};
