<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SV8, D-199 (the lead, 2026-11-09: "siswa boleh mengajukan revisi dalam kondisi apapun … Kalau sudah di acc siswa
     * tetap bisa revisi namun perlu persetujuan TU atau kesiswaan dulu"): a revision the ketua asks for once the
     * registration was approved. Before the approval the ketua changes the team directly (ChangeRegistration) and no
     * row is written here.
     *
     * - `payload`: what the revision changes, ids only: `add` (student ULIDs invited into the team), `remove` (student
     *   ULIDs of confirmed anggota), `mentor_from` / `mentor_to` (user ids; `mentor_to` null = the guru stays) and
     *   `answers` (student ULID => {confirmed_at|declined_at}: each new member confirms first). Written only by the
     *   Actions (not mass-assignable).
     * - `status`: App\Enums\RevisionStatus (pending → approved | rejected | cancelled). One pending row per
     *   registration, checked by RequestRevision under the lock of the registration.
     * - `ready_at`: every new member confirmed (at once when nobody is invited): from then on Tata Usaha or Kesiswaan
     *   may decide, and the row counts in their badge.
     * - `reason` (the ketua's, 10–500 characters) and `note` (the decider's) are text people typed: never copied into
     *   the audit log, which carries ids only.
     *
     * Cascades with its registration (PurgeStudents deletes a registration whose every person is purged); a deleted
     * account leaves `requested_by` / `decided_by` empty. Forward-only in production; no backfill.
     */
    public function up(): void
    {
        Schema::create('registration_revisions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('registration_id')->constrained('registrations')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20);                    // App\Enums\RevisionStatus
            $table->json('payload');
            $table->text('reason');
            $table->timestamp('ready_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['registration_id', 'status']);
            $table->index(['status', 'ready_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_revisions');
    }
};
