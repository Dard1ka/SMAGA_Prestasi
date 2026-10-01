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
 * Kesiswaan marks a pilihan talenta "Tidak lolos" (from any open stage). The note is required because the
 * student receives it (queued notification). Final stages (pembinaan, tidak lolos) cannot be rejected.
 */
final class RejectTalentApplication
{
    public function handle(User $actor, TalentApplication $application, string $note): TalentApplication
    {
        Gate::forUser($actor)->authorize('move', $application);

        $note = trim($note);

        if ($note === '') {
            throw ValidationException::withMessages(['note' => 'Tulis catatan dulu. Siswa akan menerimanya.']);
        }

        if (mb_strlen($note) > 1000) {
            throw ValidationException::withMessages(['note' => 'Catatan maksimal 1.000 karakter.']);
        }

        [$locked, $from] = DB::transaction(function () use ($actor, $application, $note): array {
            /** @var TalentApplication $locked */
            $locked = TalentApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if ($from->isFinal()) {
                throw ValidationException::withMessages(['status' => 'Tahap ini sudah final.']);
            }

            $locked->forceFill(['status' => TalentStatus::TidakLolos, 'note' => $note])->save();

            activity('talent')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('talent.rejected')
                ->withProperties(['from' => $from->value, 'note' => $note])
                ->log('talent.rejected');

            return [$locked, $from];
        });

        $application->setRawAttributes($locked->getAttributes(), true);

        $user = $locked->student()->with('user')->first()?->user;

        if ($user !== null && $user->canLogIn()) {
            $user->notify(TalentApplicationRejected::for($locked, $from, MoveTalentStage::talentLabel($locked), $note));
        }

        return $locked;
    }
}
