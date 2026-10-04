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
 * Kesiswaan changes its mind about a "Tidak lolos" (tab Tidak lolos, "Pulihkan"; lead, 2026-10-04): the pilihan
 * talenta goes back into the pipeline at the stage it had before the rejection (`rejected_from`), or Terdaftar
 * when that is unknown. Works with or without the student's "Minta ditinjau ulang"; an open request is closed
 * by the restore. Pembina, assessment date and the note for the guru pembina are kept, so an assigned guru sees
 * the row in its stage again. The optional note for the student replaces the rejection note and is sent with
 * the queued notification ("pengajuanmu dibuka kembali").
 */
final class RestoreTalentApplication
{
    public function handle(User $actor, TalentApplication $application, ?string $studentNote = null): TalentApplication
    {
        Gate::forUser($actor)->authorize('restore', $application);

        $studentNote = TalentNote::clean($studentNote, 'student_note');

        [$locked, $hadRequest] = DB::transaction(function () use ($actor, $application, $studentNote): array {
            /** @var TalentApplication $locked */
            $locked = TalentApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isRestorable()) {
                throw ValidationException::withMessages(['status' => 'Hanya pilihan yang tidak lolos yang bisa dipulihkan.']);
            }

            $to = $locked->restoreTarget();
            $hadRequest = $locked->review_requested_at !== null;

            $locked->forceFill([
                'status' => $to,
                'student_note' => $studentNote,
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
                    'to' => $to->value,
                    'review_requested' => $hadRequest,
                    'student_note' => $studentNote,
                ])
                ->log('talent.restored');

            return [$locked, $hadRequest];
        });

        $application->setRawAttributes($locked->getAttributes(), true);

        $user = $locked->student()->with('user')->first()?->user;

        if ($user !== null && $user->canLogIn()) {
            $user->notify(TalentApplicationRestored::for($locked, MoveTalentStage::talentLabel($locked), $studentNote, $hadRequest));
        }

        return $locked;
    }
}
