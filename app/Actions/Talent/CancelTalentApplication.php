<?php

declare(strict_types=1);

namespace App\Actions\Talent;

use App\Enums\TalentStatus;
use App\Models\TalentApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The student withdraws a talent choice while it is still "Terdaftar" (Profil talenta, "Batalkan").
 * Soft delete (history + audit stay); ApplyTalent restores the same row for "Urungkan" or a later re-apply.
 */
final class CancelTalentApplication
{
    /** @throws ValidationException */
    public function handle(User $actor, TalentApplication $application): void
    {
        Gate::forUser($actor)->authorize('cancel', $application);

        DB::transaction(function () use ($actor, $application): void {
            /** @var TalentApplication|null $locked */
            $locked = TalentApplication::query()->whereKey($application->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->status !== TalentStatus::Terdaftar) {
                throw ValidationException::withMessages([
                    'pilihan' => 'Talenta ini sudah diproses Kesiswaan dan tidak bisa dibatalkan.',
                ]);
            }

            $locked->delete();

            activity('talent')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('talent.cancelled')
                ->withProperties(['status' => $locked->status->value])
                ->log('talent.cancelled');
        });
    }
}
