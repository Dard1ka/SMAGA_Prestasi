<?php

declare(strict_types=1);

namespace App\Actions\Talent;

use App\Enums\RoleName;
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
 * that is unknown, which is also what the dialog preselects.
 *
 * QA5 (lead, 2026-10-07, D-109): back into Peserta pembinaan only with a guru pembina: the one the choice already
 * has, or `$mentorId` from the dialog (an active guru; it becomes the pembina in the same step). The earlier
 * stages still ask for none, and the assessment date stays optional everywhere.
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

    /** QA5 (lead, 2026-10-07): a peserta pembinaan always has a guru pembina, also when it comes back by "Pulihkan". */
    public const MENTOR_MESSAGE = 'Peserta pembinaan perlu guru pembina. Pilih guru pembinanya.';

    public const INACTIVE_MENTOR_MESSAGE = 'Guru pembina yang tercatat tidak aktif lagi. Pilih guru pembina lain.';

    public const UNKNOWN_MENTOR_MESSAGE = 'Pilih guru pembina dari daftar.';

    /**
     * @param  int|string|null  $mentorId  the guru pembina chosen in the dialog (null = keep the one on the row). A new
     *                                     choice must be an active guru; Peserta pembinaan needs an active guru in the end,
     *                                     the earlier stages need none, so a pembina who left blocks only that stage.
     */
    public function handle(User $actor, TalentApplication $application, ?string $studentNote = null, ?TalentStatus $to = null, int|string|null $mentorId = null): TalentApplication
    {
        Gate::forUser($actor)->authorize('restore', $application);

        if ($to !== null && ! in_array($to, self::TARGETS, true)) {
            throw ValidationException::withMessages(['to' => self::TARGET_MESSAGE]);
        }

        $mentorId = $mentorId === null || $mentorId === '' ? null : (int) $mentorId;

        // Same rule as the other decisions (TalentNote::forDecision()): a text passed here wins, null = the draft.
        $noteGiven = $studentNote !== null;
        $givenNote = TalentNote::clean($studentNote, 'student_note');

        [$locked, $hadRequest, $studentNote] = DB::transaction(function () use ($actor, $application, $noteGiven, $givenNote, $to, $mentorId): array {
            /** @var TalentApplication $locked */
            $locked = TalentApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isRestorable()) {
                throw ValidationException::withMessages(['status' => 'Hanya pilihan yang tidak lolos yang bisa dipulihkan.']);
            }

            $rejectedFrom = $locked->rejected_from;
            $target = $to ?? $locked->restoreTarget();
            $mentorId = self::mentorFor($target, $mentorId, $locked->mentor_id === null ? null : (int) $locked->mentor_id);

            $hadRequest = $locked->review_requested_at !== null;
            [$studentNote] = TalentNote::forDecision($givenNote, $noteGiven, $locked->student_note_draft);

            $locked->forceFill([
                'status' => $target,
                'mentor_id' => $mentorId,
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
                    'mentor_id' => $mentorId,
                    'review_requested' => $hadRequest,
                    'student_note' => $studentNote,
                ])
                ->log('talent.restored');

            return [$locked, $hadRequest, $studentNote];
        });

        $application->setRawAttributes($locked->getAttributes(), true);

        MoveTalentStage::studentToTell($locked)?->notify(TalentApplicationRestored::for($locked, MoveTalentStage::talentLabel($locked), $studentNote, $hadRequest));

        return $locked;
    }

    /** Is the student told about the restore? Not an alumnus or a disabled account (QA9: the screen asks). */
    public static function notifiesStudent(TalentApplication $application): bool
    {
        return MoveTalentStage::notifiesStudent($application);
    }

    /**
     * The pembina the restored row gets (D-109). A guru chosen now (other than the one on the row) must be an active
     * guru. Peserta pembinaan needs an active guru in the end; the earlier stages take the row's pembina as it is.
     *
     * @throws ValidationException
     */
    private static function mentorFor(TalentStatus $target, ?int $chosen, ?int $current): ?int
    {
        if ($chosen !== null && $chosen !== $current && ! self::isActiveGuru($chosen)) {
            throw ValidationException::withMessages(['mentor_id' => self::UNKNOWN_MENTOR_MESSAGE]);
        }

        $mentorId = $chosen ?? $current;

        if ($target !== TalentStatus::Pembinaan) {
            return $mentorId;
        }

        if ($mentorId === null) {
            throw ValidationException::withMessages(['mentor_id' => self::MENTOR_MESSAGE]);
        }

        if (! self::isActiveGuru($mentorId)) {
            throw ValidationException::withMessages(['mentor_id' => self::INACTIVE_MENTOR_MESSAGE]);
        }

        return $mentorId;
    }

    /** The same accounts the dialog lists: guru role, can log in. */
    public static function isActiveGuru(int|string|null $userId): bool
    {
        return $userId !== null && $userId !== '' && User::query()->whereKey($userId)->role(RoleName::Guru->value)->canLogIn()->exists();
    }
}
