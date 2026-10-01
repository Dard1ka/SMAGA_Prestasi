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
 * note are saved in the same step (UpdateTalentAssessment). Tidak lolos = RejectTalentApplication.
 * The student is notified (queued). Bulk moves call this once per row.
 */
final class MoveTalentStage
{
    public function __construct(private readonly UpdateTalentAssessment $assessment) {}

    /**
     * @param  array{mentor_id?: int|string|null, assessment_on?: string|null, note?: string|null}|null  $details
     */
    public function handle(User $actor, TalentApplication $application, TalentStatus $to, ?array $details = null): TalentApplication
    {
        Gate::forUser($actor)->authorize('move', $application);

        $clean = $details === null ? null : $this->assessment->validated($details);

        [$locked, $from] = DB::transaction(function () use ($actor, $application, $to, $clean): array {
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

            $locked->forceFill(['status' => $to, ...($clean ?? [])])->save();

            activity('talent')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('talent.stage_changed')
                ->withProperties(['from' => $from->value, 'to' => $to->value, 'mentor_id' => $locked->mentor_id])
                ->log('talent.stage_changed');

            return [$locked, $from];
        });

        $application->setRawAttributes($locked->getAttributes(), true);

        $user = $locked->student()->with('user')->first()?->user;

        if ($user !== null && $user->canLogIn()) {
            $user->notify(TalentStageChanged::for($locked, $from, self::talentLabel($locked)));
        }

        return $locked;
    }

    /** "OSN Biologi", "OLPAIN" (type code label + branch), for notifications. */
    public static function talentLabel(TalentApplication $application): string
    {
        $application->loadMissing(['talentType', 'talentBranch']);

        $type = $application->talentType?->code?->label() ?? $application->talentType?->name ?? 'Talenta';

        return trim($type.' '.($application->talentBranch?->name ?? ''));
    }
}
