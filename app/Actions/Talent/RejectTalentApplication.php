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

        $studentNote = TalentNote::clean($studentNote, 'student_note');
        $clean = $details === null ? [] : $this->assessment->validated($details);

        [$locked, $from] = DB::transaction(function () use ($actor, $application, $studentNote, $clean): array {
            /** @var TalentApplication $locked */
            $locked = TalentApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if ($from->isFinal()) {
                throw ValidationException::withMessages(['status' => 'Tahap ini sudah final.']);
            }

            $locked->forceFill([
                ...$clean,
                'status' => TalentStatus::TidakLolos,
                'student_note' => $studentNote,
                'rejected_from' => $from,
                'review_requested_at' => null,
                'review_message' => null,
            ])->save();

            activity('talent')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('talent.rejected')
                ->withProperties(['from' => $from->value, 'student_note' => $studentNote])
                ->log('talent.rejected');

            return [$locked, $from];
        });

        $application->setRawAttributes($locked->getAttributes(), true);

        $user = $locked->student()->with('user')->first()?->user;

        if ($user !== null && $user->canLogIn()) {
            $user->notify(TalentApplicationRejected::for($locked, $from, MoveTalentStage::talentLabel($locked), $studentNote));
        }

        return $locked;
    }
}
