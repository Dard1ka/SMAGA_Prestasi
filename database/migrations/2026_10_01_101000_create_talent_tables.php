<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Talent scouting (D-21…D-23, docs/TALENT-FORM.md). */
    public function up(): void
    {
        Schema::create('talent_types', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 20)->unique();           // App\Enums\TalentTypeCode
            $table->string('name', 100);
            $table->string('bidang', 20);                   // App\Enums\Bidang (D-22)
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('talent_branches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('talent_type_id')->constrained('talent_types');
            $table->string('name', 100);
            $table->unsignedTinyInteger('team_size')->nullable(); // null = individual
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['talent_type_id', 'name']);
        });

        Schema::create('talent_applications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('student_id')->constrained('students');
            $table->foreignUlid('talent_type_id')->constrained('talent_types');
            $table->foreignUlid('talent_branch_id')->nullable()->constrained('talent_branches'); // null for OLPAIN
            $table->foreignUlid('evidence_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->string('status', 20)->default('terdaftar')->index(); // App\Enums\TalentStatus
            $table->foreignId('mentor_id')->nullable()->constrained('users');
            $table->date('assessment_on')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // NULL branch (OLPAIN) never collides in a unique index: the Action must check that case.
            $table->unique(['student_id', 'talent_type_id', 'talent_branch_id'], 'talent_applications_student_type_branch_unique');
        });

        Schema::create('talent_profiles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('student_id')->unique()->constrained('students');
            $table->json('interests')->nullable();          // list of strings, max 4
            $table->text('skills')->nullable();
            $table->text('organizations')->nullable();
            $table->text('junior_high_achievements')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talent_profiles');
        Schema::dropIfExists('talent_applications');
        Schema::dropIfExists('talent_branches');
        Schema::dropIfExists('talent_types');
    }
};
