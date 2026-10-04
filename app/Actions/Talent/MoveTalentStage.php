<?php

declare(strict_types=1);

namespace App\Actions\Talent;

use App\Enums\TalentStatus;
use App\Models\TalentApplication;
use App\Models\User;
use App\Notifications\Talent\TalentStageChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Kesiswaan moves a pilihan talenta one step forward in the pipeline ("Lolos ke …"):
 * terdaftar → pemetaan → seleksi → pembinaan (TalentStatus::next()). Optional pembina, assessment date and
 * the note for the guru pembina are saved in the same step (UpdateTalentAssessment). Tidak lolos =
 * RejectTalentApplication. The student is notified (queued). Bulk moves call this once per row.
 *
 * `student_note` (optional "catatan untuk siswa") belongs to this decision: it is stored on the row, sent
 * with the notification and shown on Profil talenta. A move without one clears the note of the earlier stage.
 */
final class MoveTalentStage
{
    public function __construct(private readonly UpdateTalentAssessment $assessment) {}

    /**
     * @param  array{mentor_id?: int|string|null, assessment_on?: string|null, note?: string|null, student_note?: string|null}|null  $details
     */
    public function handle(User $actor, TalentApplication $application, TalentStatus $to, ?array $details = null): TalentApplication
    {
        Gate::forUser($actor)->authorize('move', $application);

        $clean = $details === null ? [] : $this->assessment->validated($details);
        $studentNote = TalentNote::clean(isset($details['student_note']) ? (string) $details['student_note'] : null, 'student_note');

        [$locked, $from] = DB::transaction(function () use ($actor, $application, $to, $clean, $studentNote): array {
            /** @var TalentApplication $locked */
            $locked = TalentApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if ($from->next() !== $to) {
                throw ValidationException::withMessages([
                    'status' => $from->isFinal()
                        ? 'Tahap ini sudah final.'
                        : 'Pindahkan ke tahap berikutnya: '.$from->next()?->label().'.',
                ]);
            }

            $locked->forceFill(['status' => $to, 'student_note' => $studentNote, ...$clean])->save();

            activity('talent')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('talent.stage_changed')
                ->withProperties(['from' => $from->value, 'to' => $to->value, 'mentor_id' => $locked->mentor_id, 'student_note' => $studentNote])
                ->log('talent.stage_changed');

            return [$locked, $from];
        });

        $application->setRawAttributes($locked->getAttributes(), true);

        $user = $locked->student()->with('user')->first()?->user;

        if ($user !== null && $user->canLogIn()) {
            $user->notify(TalentStageChanged::for($locked, $from, self::talentLabel($locked), $studentNote));
        }

        return $locked;
    }

    /** "OSN Biologi", "OLPAIN" (type code label + branch), for notifications. */
    public static function talentLabel(TalentApplication $application): string
    {
        $application->loadMissing(['talentType', 'talentBranch']);

        $type = $application->talentType?->code?->label() ?? $application->talentType?->name ?? 'Talenta';
        $branch = (string) ($application->talentBranch?->name ?? '');

        // Branches of Debat are named "Debat Bahasa …": do not repeat the type ("Debat Debat Bahasa …").
        if ($branch !== '' && str_starts_with(mb_strtolower($branch), mb_strtolower($type))) {
            return $branch;
        }

        return trim($type.' '.$branch);
    }
}
