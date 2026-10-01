<?php

declare(strict_types=1);

namespace App\Actions\Competitions;

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Kesiswaan/Admin takes a published lomba out of the student catalog (`dipublikasikan` → `ditutup`).
 * Registrations that already exist keep running; new ones are no longer possible (catalog = published only).
 */
final class CloseCompetition
{
    public function handle(User $actor, Competition $competition, ?string $note = null): Competition
    {
        Gate::forUser($actor)->authorize('close', $competition);

        $note = trim((string) $note);

        if (mb_strlen($note) > 1000) {
            throw ValidationException::withMessages(['note' => 'Catatan maksimal 1.000 karakter.']);
        }

        $result = DB::transaction(function () use ($actor, $competition, $note): Competition {
            /** @var Competition $locked */
            $locked = Competition::query()->whereKey($competition->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== CompetitionStatus::Dipublikasikan) {
                throw ValidationException::withMessages(['status' => 'Hanya lomba yang dipublikasikan yang bisa ditutup.']);
            }

            $locked->forceFill(['status' => CompetitionStatus::Ditutup])->save();

            activity('competition')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('competition.closed')
                ->withProperties(['note' => $note === '' ? null : $note])
                ->log('competition.closed');

            return $locked;
        });

        $competition->setRawAttributes($result->getAttributes(), true);

        return $result;
    }
}
