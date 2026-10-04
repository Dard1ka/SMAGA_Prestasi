<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M1-D (lead, 2026-10-04, items 14 + 15): two clearly addressed notes and an undo for "tidak lolos".
 *
 * - `note` stays and now means only "catatan untuk guru pembina" (internal: guru pembina + Kesiswaan).
 * - `student_note` = "catatan untuk siswa": sent with the notification of the latest decision (lolos, tidak
 *   lolos, dipulihkan) and shown on the student's Profil talenta.
 * - `rejected_from` = the stage a row had before "tidak lolos" (RestoreTalentApplication goes back to it).
 * - `review_requested_at` / `review_message` = the student's open "Minta ditinjau ulang" (one per rejection,
 *   cleared when Kesiswaan restores the row).
 *
 * Existing data: until now one `note` was sent to the student on a rejection, so the note of every
 * "tidak lolos" row moves to `student_note`; all other notes were never shown to the student and stay in
 * `note`. `rejected_from` is read from the audit log (`talent.rejected`, property `from`) where it exists.
 */
return new class extends Migration
{
    private const OPEN_STAGES = ['terdaftar', 'pemetaan', 'seleksi'];

    public function up(): void
    {
        Schema::table('talent_applications', function (Blueprint $table) {
            $table->text('student_note')->nullable()->after('note');
            $table->string('rejected_from', 20)->nullable()->after('student_note'); // App\Enums\TalentStatus
            $table->timestamp('review_requested_at')->nullable()->after('rejected_from');
            $table->string('review_message', 500)->nullable()->after('review_requested_at');
        });

        $this->backfill();
    }

    /** Moves the rejection notes and fills `rejected_from`. Safe to run twice. */
    public function backfill(): void
    {
        // Two statements: the order in which one UPDATE applies its assignments differs between engines.
        DB::table('talent_applications')
            ->where('status', 'tidak_lolos')
            ->whereNotNull('note')
            ->whereNull('student_note')
            ->update(['student_note' => DB::raw('note')]);

        DB::table('talent_applications')
            ->where('status', 'tidak_lolos')
            ->whereNotNull('note')
            ->whereColumn('student_note', 'note')
            ->update(['note' => null]);

        if (! Schema::hasTable('activity_log')) {
            return;
        }

        DB::table('talent_applications')
            ->where('status', 'tidak_lolos')
            ->whereNull('rejected_from')
            ->select('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $properties = DB::table('activity_log')
                        ->where('event', 'talent.rejected')
                        ->where('subject_id', (string) $row->id)
                        ->orderByDesc('id')
                        ->value('properties');

                    $from = is_string($properties) ? (json_decode($properties, true)['from'] ?? null) : null;

                    if (is_string($from) && in_array($from, self::OPEN_STAGES, true)) {
                        DB::table('talent_applications')->where('id', $row->id)->update(['rejected_from' => $from]);
                    }
                }
            }, 'id');
    }

    public function down(): void
    {
        DB::table('talent_applications')
            ->where('status', 'tidak_lolos')
            ->whereNull('note')
            ->whereNotNull('student_note')
            ->update(['note' => DB::raw('student_note')]);

        Schema::table('talent_applications', function (Blueprint $table) {
            $table->dropColumn(['student_note', 'rejected_from', 'review_requested_at', 'review_message']);
        });
    }
};
