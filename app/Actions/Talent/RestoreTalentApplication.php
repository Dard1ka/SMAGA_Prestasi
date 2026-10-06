<?php

declare(strict_types=1);

namespace App\Actions\Talent;

use App\Enums\TalentStatus;
use App\Models\TalentApplication;
use App\Models\User;
use App\Notifications\Talent\TalentApplicationRestored;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Kesiswaan changes its mind about a "Tidak lolos" ("Pulihkan"; lead, 2026-10-04): the pilihan talenta goes
 * back into the pipeline. Works with or without the student's "Minta ditinjau ulang"; an open request is closed
 * by the restore. Pembina, assessment date and the note for the guru pembina are kept, so an assigned guru sees
 * the row in its stage again. The optional note for the student replaces the rejection note and is sent with
 * the queued notification ("pengajuanmu dibuka kembali"), which names the stage the choice is in again.
 *
 * M3-A (lead, 2026-10-06): Kesiswaan chooses the stage, `$to` = Terdaftar, Pemetaan, Seleksi or Peserta
 * pembinaan (TARGETS). null = the stage the row had before the rejection (`rejected_from`), or Terdaftar when
 * that is unknown, which is also what the dialog preselects. A stage asks for nothing more here than it does in
 * MoveTalentStage: pembina and assessment date are optional at every stage, so none is required by a restore.
 */
final class RestoreTalentApplication
{
    /** Where a rejected choice may go back to: every stage of the pipeline, never "tidak lolos" itself. */
    public const TARGETS = [
        TalentStatus::Terdaftar,
        TalentStatus::Pemetaan,
        TalentStatus::Seleksi,
        TalentStatus::Pembinaan,
    ];

    public const TARGET_MESSAGE = 'Pilih tahap tujuan: Terdaftar, Pemetaan, Seleksi/asesmen, atau Peserta pembinaan.';

    public function handle(User $actor, TalentApplication $application, ?string $studentNote = null, ?TalentStatus $to = null): TalentApplication
    {
        Gate::forUser($actor)->authorize('restore', $application);

        if ($to !== null && ! in_array($to, self::TARGETS, true)) {
            throw ValidationException::withMessages(['to' => self::TARGET_MESSAGE]);
        }

        // Same rule as the other decisions (TalentNote::forDecision()): a text passed here wins, null = the draft.
        $noteGiven = $studentNote !== null;
        $givenNote = TalentNote::clean($studentNote, 'student_note');

        [$locked, $hadRequest, $studentNote] = DB::transaction(function () use ($actor, $application, $noteGiven, $givenNote, $to): array {
            /** @var TalentApplication $locked */
            $locked = TalentApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isRestorable()) {
                throw ValidationException::withMessages(['status' => 'Hanya pilihan yang tidak lolos yang bisa dipulihkan.']);
            }

            $rejectedFrom = $locked->rejected_from;
            $target = $to ?? $locked->restoreTarget();
            $hadRequest = $locked->review_requested_at !== null;
            [$studentNote] = TalentNote::forDecision($givenNote, $noteGiven, $locked->student_note_draft);

            $locked->forceFill([
                'status' => $target,
                'student_note' => $studentNote,
                'student_note_draft' => null,
                'rejected_from' => null,
                'review_requested_at' => null,
                'review_message' => null,
            ])->save();

            activity('talent')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('talent.restored')
                ->withProperties([
                    'from' => TalentStatus::TidakLolos->value,
                    'to' => $target->value,
                    // The stage before the rejection, so the log shows when the row went somewhere else.
                    'rejected_from' => $rejectedFrom?->value,
                    'review_requested' => $hadRequest,
                    'student_note' => $studentNote,
                ])
                ->log('talent.restored');

            return [$locked, $hadRequest, $studentNote];
        });

        $application->setRawAttributes($locked->getAttributes(), true);

        $user = $locked->student()->with('user')->first()?->user;

        if ($user !== null && $user->canLogIn()) {
            $user->notify(TalentApplicationRestored::for($locked, MoveTalentStage::talentLabel($locked), $studentNote, $hadRequest));
        }

        return $locked;
    }
}
