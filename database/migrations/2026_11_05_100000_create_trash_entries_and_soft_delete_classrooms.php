<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * smaga_server, the lead's decision of 5 Nov 2026 (replaces the "only an empty class" rule of D-166 here): the Admin
 * deletes classes and students into a trash ("Sampah") that keeps them for 7 days before `sth:sampah:hapus-permanen`
 * deletes them for good. Forward-safe: one nullable column and one new table, no data is changed.
 *
 * - `classrooms.deleted_at`: a class in the trash (App\Models\Classroom uses SoftDeletes from now on). `students` and
 *   `users` already have `deleted_at`.
 * - `trash_entries` (App\Models\TrashEntry): one row per class or student put in the trash. Students trashed with their
 *   class point at the class entry (`parent_id`). The ids are plain columns WITHOUT foreign keys on purpose: an entry is
 *   history that outlives the rows it names (the purge deletes the student and the class but keeps the entry, marked
 *   `purged_at`), and DeleteAllStudentsTest's schema guard keeps every foreign key to users / student tables handled.
 *   Production: the runtime user needs DML on the new table (scripts/deploy/grants.sh grants it after the migrate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classrooms', function (Blueprint $table): void {
            $table->softDeletes();
        });

        Schema::create('trash_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('kind', 20);                         // TrashEntry::KIND_CLASSROOM | TrashEntry::KIND_STUDENT
            $table->ulid('classroom_id')->nullable()->index();  // the class (of a student: its class when trashed)
            $table->unsignedBigInteger('user_id')->nullable()->index(); // the student's account (kind student)
            $table->ulid('parent_id')->nullable()->index();     // the class entry of a student trashed with its class
            $table->unsignedBigInteger('deleted_by');           // the Admin who confirmed
            $table->timestamp('deleted_at');
            $table->timestamp('purge_after')->index();          // restorable before, purged by the daily run after
            $table->text('reason');
            $table->timestamp('restored_at')->nullable();
            $table->unsignedBigInteger('restored_by')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trash_entries');

        Schema::table('classrooms', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
