<?php

declare(strict_types=1);

namespace App\Actions\Talent;

use App\Models\TalentApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The student deletes a talent choice (Profil talenta, "Hapus"). D-142: at any time, whatever the old status of the
 * row, also when Kesiswaan named a guru pembina. Nobody is notified, the guru pembina neither (spec FS1 §6.1).
 * Soft delete (history + audit stay); ApplyTalent restores the same row for "Urungkan" or a later re-apply.
 */
final class CancelTalentApplication
{
    public function handle(User $actor, TalentApplication $application): void
    {
        Gate::forUser($actor)->authorize('cancel', $application);

        DB::transaction(function () use ($actor, $application): void {
            /** @var TalentApplication|null $locked */
            $locked = TalentApplication::query()->whereKey($application->getKey())->lockForUpdate()->first();

            // Deleted meanwhile (another tab): nothing left to do.
            if ($locked === null) {
                return;
            }

            $locked->delete();

            activity('talent')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('talent.cancelled')
                ->withProperties([
                    'status' => $locked->status->value,
                    'mentor' => $locked->mentor_id !== null,
                ])
                ->log('talent.cancelled');
        });
    }
}
