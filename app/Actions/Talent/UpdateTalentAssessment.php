<?php

declare(strict_types=1);

namespace App\Actions\Talent;

use App\Enums\RoleName;
use App\Models\TalentApplication;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Kesiswaan saves the pembina, assessment date and the note for the guru pembina of a pilihan talenta without
 * changing its stage (detail panel "Simpan"). Only a guru account can be pembina. Final rows (pembinaan /
 * tidak lolos) keep their data editable for the pembina and note only.
 *
 * `note` is the internal "catatan untuk guru pembina" (optional; guru pembina + Kesiswaan read it, the student
 * never does). The note for the student is never SENT here: it belongs to a decision (MoveTalentStage,
 * RejectTalentApplication, RestoreTalentApplication) and travels with that notification. What Kesiswaan
 * already typed for the student is kept as `student_note_draft` (M2-B, QA Q2-14: "Simpan" used to drop it
 * without a word): only the panel reads it, `student_note` stays untouched, nobody is notified, and the next
 * decision sends and clears it.
 *
 * "Jadwal asesmen" is a schedule (QA6, Q2-13): a new or changed date may not lie before today (Asia/Jakarta); the
 * date already saved on the row stays valid, so the panel can still be saved after the assessment took place.
 * The guru pembina works the same way (QA9): a new choice must be an active guru, the one already on the row stays
 * valid after the account was disabled or archived, so it no longer blocks Simpan, Lolos and Tidak lolos.
 */
final class UpdateTalentAssessment
{
    /**
     * @param  array{mentor_id?: int|string|null, assessment_on?: string|null, note?: string|null, student_note_draft?: string|null}  $details
     */
    public function handle(User $actor, TalentApplication $application, array $details): TalentApplication
    {
        Gate::forUser($actor)->authorize('move', $application);

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
     * Validates and normalises the detail fields. Keys that are absent stay untouched. $current is the row the
     * details are for: its saved assessment date passes even when it lies in the past.
     *
     * @param  array{mentor_id?: int|string|null, assessment_on?: string|null, note?: string|null, student_note_draft?: string|null}  $details
     * @return array{mentor_id?: ?int, assessment_on?: ?string, note?: ?string, student_note_draft?: ?string}
     */
    public function validated(array $details, ?TalentApplication $current = null): array
    {
        $clean = [];

        if (array_key_exists('mentor_id', $details)) {
            $mentorId = $details['mentor_id'] === null || $details['mentor_id'] === '' ? null : (int) $details['mentor_id'];
            // QA9: the guru pembina already on the row passes unchanged, also when the account was disabled or archived
            // since (the panel sends it with every button and says the guru is not active); a new choice must be active.
            $unchanged = $mentorId !== null && $current?->mentor_id !== null && (int) $current->mentor_id === $mentorId;

            if ($mentorId !== null && ! $unchanged && ! User::query()->whereKey($mentorId)->role(RoleName::Guru->value)->canLogIn()->exists()) {
                throw ValidationException::withMessages(['mentor_id' => 'Pilih guru pembina dari daftar.']);
            }

            $clean['mentor_id'] = $mentorId;
        }

        if (array_key_exists('assessment_on', $details)) {
            $raw = trim((string) $details['assessment_on']);
            $clean['assessment_on'] = $raw === '' ? null : self::date($raw, $current?->assessment_on?->format('Y-m-d'));
        }

        if (array_key_exists('note', $details)) {
            $clean['note'] = TalentNote::clean($details['note'] === null ? null : (string) $details['note'], 'note');
        }

        // Unsent text for the student ("Simpan"); the key `student_note` itself is never accepted here.
        if (array_key_exists('student_note_draft', $details)) {
            $clean['student_note_draft'] = TalentNote::clean(
                $details['student_note_draft'] === null ? null : (string) $details['student_note_draft'],
                'student_note_draft',
            );
        }

        return $clean;
    }

    private static function date(string $value, ?string $saved): string
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            $date = false;
        }

        if ($date === false || $date->format('Y-m-d') !== $value || $date->year < 2020 || $date->year > 2100) {
            throw ValidationException::withMessages(['assessment_on' => 'Tanggal asesmen tidak valid.']);
        }

        if ($date->format('Y-m-d') !== $saved && $date->lessThan(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['assessment_on' => 'Jadwal asesmen tidak boleh sebelum hari ini.']);
        }

        return $date->format('Y-m-d');
    }
}
