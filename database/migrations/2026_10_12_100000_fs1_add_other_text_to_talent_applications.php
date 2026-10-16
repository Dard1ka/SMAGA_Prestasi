<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FS1-E (D-143): talent "Lainnya".
 *
 * - `talent_applications.other_text` = what the student typed in "Sebutkan" for the jenis "Lainnya" or a cabang
 *   "Lainnya" (3–60 characters, plain text, never mass-assignable; `ApplyTalent` writes it).
 * - The unique index (student, jenis, cabang) goes: one student may name several "Lainnya" talents on the same
 *   cabang. `ApplyTalent` refuses duplicates itself (jenis + cabang + normalised text, under the student lock).
 *   The plain (student, jenis) index is added BEFORE the unique one is dropped: in MySQL the unique index carries
 *   the `student_id` foreign key, which needs an index starting with that column.
 * - `talent_types.bidang` becomes nullable: the jenis "Lainnya" has no bidang (D-11 keeps four bidang).
 *
 * Forward-only in production; `down()` is for a local database only (it fails while a jenis without bidang exists).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('talent_applications', function (Blueprint $table) {
            $table->string('other_text', 60)->nullable()->after('talent_branch_id');
            $table->index(['student_id', 'talent_type_id'], 'talent_applications_student_type_index');
        });

        Schema::table('talent_applications', function (Blueprint $table) {
            $table->dropUnique('talent_applications_student_type_branch_unique');
        });

        Schema::table('talent_types', function (Blueprint $table) {
            $table->string('bidang', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('talent_types', function (Blueprint $table) {
            $table->string('bidang', 20)->nullable(false)->change();
        });

        Schema::table('talent_applications', function (Blueprint $table) {
            $table->unique(['student_id', 'talent_type_id', 'talent_branch_id'], 'talent_applications_student_type_branch_unique');
        });

        Schema::table('talent_applications', function (Blueprint $table) {
            $table->dropIndex('talent_applications_student_type_index');
            $table->dropColumn('other_text');
        });
    }
};
