<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per team member of a verified report. level/weight_category and the three weights are
     * snapshots taken at verification time, so later catalog or weight edits never change history
     * silently (recalculation is an explicit, audited job).
     */
    public function up(): void
    {
        Schema::create('achievements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('report_id')->constrained('reports');
            $table->foreignUlid('student_id')->constrained('students');
            $table->foreignUlid('competition_id')->constrained('competitions');
            $table->string('result', 30);                   // App\Enums\ResultType
            $table->string('level', 20);                    // App\Enums\Level (snapshot)
            $table->char('weight_category', 1);             // App\Enums\WeightCategory (snapshot)
            $table->decimal('w_level', 6, 2);
            $table->decimal('w_result', 4, 2);
            $table->decimal('w_organizer', 4, 2);
            $table->decimal('points', 8, 2);
            $table->foreignUlid('weight_version_id')->constrained('weight_versions');
            $table->timestamp('verified_at');
            $table->timestamps();

            $table->unique(['report_id', 'student_id']);
            $table->index(['student_id', 'points']);
            $table->index('competition_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements');
    }
};
