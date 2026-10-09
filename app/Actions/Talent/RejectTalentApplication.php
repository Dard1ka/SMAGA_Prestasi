<?php

declare(strict_types=1);

namespace App\Actions\Talent;

use App\Enums\TalentStatus;
use App\Models\TalentApplication;
use App\Models\User;
use App\Notifications\Talent\TalentApplicationRejected;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Kesiswaan marks a pilihan talenta "Tidak lolos" (from any open stage). The note for the student is optional
 * (lead, 2026-10-04); when given it is stored as `student_note`, sent with the queued notification and shown on
 * Profil talenta. The stage the row had is kept in `rejected_from`, so RestoreTalentApplication can undo the
 * rejection. Optional pembina, assessment date and note for the guru pembina are saved in the same step (the
 * detail panel sends them, like "Lolos ke …"). Final stages (pembinaan, tidak lolos) cannot be rejected.
 * `$studentNote` null (a bulk rejection without a note) sends the draft that "Simpan" kept on the row, a string
 * is the note for this rejection, '' = none (M2-B, TalentNote::forDecision()); the rejection clears the draft.
 */
final class RejectTalentApplication
{
    public function __construct(private readonly UpdateTalentAssessment $assessment) {}

    /**
     * @param  array{mentor_id?: int|string|null, assessment_on?: string|null, note?: string|null}|null  $details
     */
    public function handle(User $actor, TalentApplication $application, ?string $studentNote = null, ?array $details = null): TalentApplication
    {
        Gate::forUser($actor)->authorize('move', $application);

        // A text passed for this rejection wins (an empty one = no note); null = send the saved draft, if any.
        $noteGiven = $studentNote !== null;
        $givenNote = TalentNote::clean($studentNote, 'student_note');
        $clean = $details === null ? [] : $this->assessment->validated($details, $application);

        [$locked, $from, $studentNote] = DB::transaction(function () use ($actor, $application, $noteGiven, $givenNote, $clean): array {
            /** @var TalentApplication $locked */
            $locked = TalentApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if ($from->isFinal()) {
                throw ValidationException::withMessages(['status' => 'Tahap ini sudah final.']);
            }

            [$studentNote, $fromDraft] = TalentNote::forDecision($givenNote, $noteGiven, $locked->student_note_draft);

            $locked->forceFill([
                ...$clean,
                'status' => TalentStatus::TidakLolos,
                'student_note' => $studentNote,
                'student_note_draft' => null,
                'rejected_from' => $from,
                'review_requested_at' => null,
                'review_message' => null,
            ])->save();

            activity('talent')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('talent.rejected')
                ->withProperties(['from' => $from->value, 'student_note' => $studentNote, 'student_note_from_draft' => $fromDraft])
                ->log('talent.rejected');

            return [$locked, $from, $studentNote];
        });

        $application->setRawAttributes($locked->getAttributes(), true);

        MoveTalentStage::studentToTell($locked)?->notify(TalentApplicationRejected::for($locked, $from, MoveTalentStage::talentLabel($locked), $studentNote));

        return $locked;
    }

    /** Is the student told about the rejection? Not an alumnus or a disabled account (QA9: the screen asks). */
    public static function notifiesStudent(TalentApplication $application): bool
    {
        return MoveTalentStage::notifiesStudent($application);
    }
}
