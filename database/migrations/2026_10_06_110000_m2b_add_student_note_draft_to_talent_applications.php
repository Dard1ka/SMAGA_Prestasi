<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M2-B (QA 2026-10-05, Q2-14): "Simpan" in Kesiswaan › Talenta dropped a typed "Catatan untuk siswa" without a
 * word. The text is now kept as a draft on the row:
 *
 * - `student_note_draft` = what Kesiswaan typed for the student and saved with "Simpan". Only Kesiswaan/Admin
 *   read it (the detail panel). It is never shown or sent to the student.
 * - `student_note` stays the note that WAS sent with the latest decision (M1-D). A decision (lolos, tidak
 *   lolos, dipulihkan) sends its note and clears the draft.
 *
 * Plain nullable column, no backfill: nothing was stored before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('talent_applications', function (Blueprint $table) {
            $table->text('student_note_draft')->nullable()->after('student_note');
        });
    }

    public function down(): void
    {
        Schema::table('talent_applications', function (Blueprint $table) {
            $table->dropColumn('student_note_draft');
        });
    }
};
