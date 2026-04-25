<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('votings', function (Blueprint $table) {
            $table->boolean('auto_show_results')->default(true)->after('default_response_time_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('votings', function (Blueprint $table) {
            $table->dropColumn('auto_show_results');
        });
    }
};
