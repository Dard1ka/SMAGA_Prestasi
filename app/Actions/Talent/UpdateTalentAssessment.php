<?php

declare(strict_types=1);

namespace App\Actions\Talent;

use App\Enums\RoleName;
use App\Models\TalentApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Kesiswaan records the guru pembina and the note for him of a pilihan talenta (Database talenta siswa, panel
 * "Simpan"). D-142: talents have no stages and no verification any more, so this is the only change Kesiswaan makes;
 * the guru pembina is optional, there is no assessment date and no note for the student (keys `assessment_on`,
 * `student_note` and `student_note_draft` are ignored; the columns stay for old rows). Nobody is notified.
 *
 * `note` is the internal "catatan untuk guru pembina" (optional; the guru pembina and Kesiswaan read it, the student
 * never does). Only an active guru can be chosen; the guru already on the row stays valid after his account was
 * disabled or archived (QA9), so he never blocks "Simpan". The audit event keeps its name `talent.assessment_updated`.
 */
final class UpdateTalentAssessment
{
    /**
     * @param  array{mentor_id?: int|string|null, note?: string|null}  $details  absent keys stay untouched
     */
    public function handle(User $actor, TalentApplication $application, array $details): TalentApplication
    {
        Gate::forUser($actor)->authorize('update', $application);

        $clean = $this->validated($details, $application);

        $locked = DB::transaction(function () use ($actor, $application, $clean): TalentApplication {
            /** @var TalentApplication $locked */
            $locked = TalentApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            $locked->forceFill($clean)->save();

            if ($locked->wasChanged()) {
                activity('talent')
                    ->performedOn($locked)
                    ->causedBy($actor)
                    ->event('talent.assessment_updated')
                    ->withProperties(array_intersect_key($locked->getChanges(), $clean))
                    ->log('talent.assessment_updated');
            }

            return $locked;
        });

        $application->setRawAttributes($locked->getAttributes(), true);

        return $locked;
    }

    /**
     * Validates and normalises the panel fields. Keys that are absent stay untouched; every other key is ignored.
     * $current is the row the details are for: its saved guru pembina passes even when his account left.
     *
     * @param  array<string, mixed>  $details
     * @return array{mentor_id?: ?int, note?: ?string}
     */
    public function validated(array $details, ?TalentApplication $current = null): array
    {
        $clean = [];

        if (array_key_exists('mentor_id', $details)) {
            $raw = $details['mentor_id'];
            $mentorId = $raw === null || $raw === '' ? null : (int) $raw;
            $unchanged = $mentorId !== null && $current?->mentor_id !== null && (int) $current->mentor_id === $mentorId;

            if ($mentorId !== null && ! $unchanged && ! self::isActiveGuru($mentorId)) {
                throw ValidationException::withMessages(['mentor_id' => 'Pilih guru pembina dari daftar.']);
            }

            $clean['mentor_id'] = $mentorId;
        }

        if (array_key_exists('note', $details)) {
            $note = $details['note'];
            $clean['note'] = TalentNote::clean($note === null ? null : (string) $note, 'note');
        }

        return $clean;
    }

    /** An account that may be chosen as guru pembina now: a guru who can log in. */
    public static function isActiveGuru(int|string|null $userId): bool
    {
        return $userId !== null && $userId !== '' && User::query()->whereKey($userId)->role(RoleName::Guru->value)->canLogIn()->exists();
    }
}
