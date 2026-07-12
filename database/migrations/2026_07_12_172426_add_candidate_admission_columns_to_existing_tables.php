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
            if (! Schema::hasColumn('election_candidate_admissions', 'election_id')) {
                $table->foreignId('election_id')->nullable()->constrained()->cascadeOnDelete();
                $table->foreignId('election_contest_id')->nullable()->constrained()->cascadeOnDelete();
                $table->foreignId('device_group_id')->nullable()->constrained()->nullOnDelete();
                $table->string('first_name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('status')->default('draft')->index();
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->index(['election_id', 'status']);
            }
        });

        Schema::table('election_candidate_admission_votes', function (Blueprint $table) {
            if (! Schema::hasColumn('election_candidate_admission_votes', 'election_candidate_admission_id')) {
                $table->foreignId('election_candidate_admission_id')->nullable()->constrained()->cascadeOnDelete();
                $table->foreignId('device_id')->nullable()->constrained()->cascadeOnDelete();
                $table->string('option_key')->nullable();
                $table->decimal('weight_snapshot', 12, 2)->nullable();
                $table->timestamp('voted_at')->nullable();
                $table->unique(['election_candidate_admission_id', 'device_id'], 'admission_device_unique');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration repairs databases that already ran the original empty schema.
    }
};
