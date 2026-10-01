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
 * Kesiswaan saves the pembina, assessment date and note of a pilihan talenta without changing its stage
 * (detail panel "Simpan"). Only a guru account can be pembina. Final rows (pembinaan / tidak lolos) keep
 * their data editable for the pembina and note only.
 */
final class UpdateTalentAssessment
{
    /**
     * @param  array{mentor_id?: int|string|null, assessment_on?: string|null, note?: string|null}  $details
     */
    public function handle(User $actor, TalentApplication $application, array $details): TalentApplication
    {
        Gate::forUser($actor)->authorize('move', $application);

        $clean = $this->validated($details);

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
     * Validates and normalises the detail fields. Keys that are absent stay untouched.
     *
     * @param  array{mentor_id?: int|string|null, assessment_on?: string|null, note?: string|null}  $details
     * @return array{mentor_id?: ?int, assessment_on?: ?string, note?: ?string}
     */
    public function validated(array $details): array
    {
        $clean = [];

        if (array_key_exists('mentor_id', $details)) {
            $mentorId = $details['mentor_id'] === null || $details['mentor_id'] === '' ? null : (int) $details['mentor_id'];

            if ($mentorId !== null && ! User::query()->whereKey($mentorId)->role(RoleName::Guru->value)->canLogIn()->exists()) {
                throw ValidationException::withMessages(['mentor_id' => 'Pilih guru pembina dari daftar.']);
            }

            $clean['mentor_id'] = $mentorId;
        }

        if (array_key_exists('assessment_on', $details)) {
            $raw = trim((string) $details['assessment_on']);
            $clean['assessment_on'] = $raw === '' ? null : self::date($raw);
        }

        if (array_key_exists('note', $details)) {
            $note = trim((string) $details['note']);

            if (mb_strlen($note) > 1000) {
                throw ValidationException::withMessages(['note' => 'Catatan maksimal 1.000 karakter.']);
            }

            $clean['note'] = $note === '' ? null : $note;
        }

        return $clean;
    }

    private static function date(string $value): string
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            $date = false;
        }

        if ($date === false || $date->format('Y-m-d') !== $value || $date->year < 2020 || $date->year > 2100) {
            throw ValidationException::withMessages(['assessment_on' => 'Tanggal asesmen tidak valid.']);
        }

        return $date->format('Y-m-d');
    }
}
