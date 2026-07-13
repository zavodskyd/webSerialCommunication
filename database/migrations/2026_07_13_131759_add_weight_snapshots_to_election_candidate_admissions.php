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
        Schema::table('election_candidate_admissions', function (Blueprint $table) {
            $table->unsignedInteger('active_device_limit')->nullable()->after('response_time_seconds');
            $table->decimal('eligible_weight_total', 12, 2)->default(0)->after('active_device_limit');
        });

        Schema::create('election_candidate_admission_device_weights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('election_candidate_admission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->decimal('weight_snapshot', 12, 2);
            $table->timestamps();

            $table->unique(['election_candidate_admission_id', 'device_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('election_candidate_admission_device_weights');

        Schema::table('election_candidate_admissions', function (Blueprint $table) {
            $table->dropColumn(['active_device_limit', 'eligible_weight_total']);
        });
    }
};
