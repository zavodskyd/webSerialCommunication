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
        Schema::table('election_rounds', function (Blueprint $table): void {
            $table->unsignedInteger('active_device_limit')->nullable()->after('response_time_seconds');
            $table->decimal('eligible_weight_total', 12, 2)->nullable()->after('active_device_limit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('election_rounds', function (Blueprint $table): void {
            $table->dropColumn(['active_device_limit', 'eligible_weight_total']);
        });
    }
};
