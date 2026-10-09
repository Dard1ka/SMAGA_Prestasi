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
    /** Also the toast of Profil talenta when a stale tab asks to cancel a choice Kesiswaan already moved (QA9). */
    public const PROCESSED_MESSAGE = 'Talenta ini sudah diproses Kesiswaan dan tidak bisa dibatalkan.';

    /** @throws ValidationException */
    public function handle(User $actor, TalentApplication $application): void
    {
        Gate::forUser($actor)->authorize('cancel', $application);

        DB::transaction(function () use ($actor, $application): void {
            /** @var TalentApplication|null $locked */
            $locked = TalentApplication::query()->whereKey($application->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->status !== TalentStatus::Terdaftar) {
                throw ValidationException::withMessages(['pilihan' => self::PROCESSED_MESSAGE]);
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
