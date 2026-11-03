<?php

declare(strict_types=1);

namespace App\Actions\Talent;

use App\Domain\Files\InvalidUpload;
use App\Domain\Files\StoreUpload;
use App\Enums\FilePurpose;
use App\Models\File;
use App\Models\Student;
use App\Models\TalentProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Saves the student's "Riwayat talenta" (talent_profiles; replaces the Google Form, D-21): minat (≤ 4),
 * bidang yang dikuasai, organisasi/ekskul, prestasi SMP, and up to 2 sertifikat pendukung (StoreUpload,
 * purpose talent_evidence, table talent_profile_files). Validation keys: minat, dikuasai, organisasi, smp,
 * sertifikat.
 */
final class SaveTalentProfile
{
    public const MAX_INTEREST_LENGTH = 40;

    public const MAX_SKILLS = 300;

    public const MAX_TEXT = 1000;

    public function __construct(private readonly StoreUpload $store) {}

    /**
     * @param  array{interests?: list<string>, skills?: ?string, organizations?: ?string, junior_high_achievements?: ?string}  $data
     * @param  list<UploadedFile>  $newCertificates
     * @param  list<string>  $removeCertificateIds  File ULIDs to detach from the profile
     *
     * @throws ValidationException
     */
    public function handle(User $actor, array $data, array $newCertificates = [], array $removeCertificateIds = []): TalentProfile
    {
        /** @var Student|null $student */
        $student = $actor->loadMissing('student')->getRelation('student');
        $profile = $student?->talentProfile()->first();

        Gate::forUser($actor)->authorize('save', [TalentProfile::class, $profile]);

        $clean = $this->validated($data);

        $stored = [];

        foreach ($newCertificates as $upload) {
            try {
                $stored[] = $this->store->store($upload, FilePurpose::TalentEvidence, $actor);
            } catch (InvalidUpload $e) {
                array_map($this->store->discard(...), $stored);

                throw $e->toValidationException('sertifikat');
            }
        }

        try {
            return DB::transaction(fn (): TalentProfile => $this->persist($actor, $student, $clean, $stored, $removeCertificateIds));
        } catch (Throwable $e) {
            array_map($this->store->discard(...), $stored);

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{interests: list<string>, skills: ?string, organizations: ?string, junior_high_achievements: ?string}
     *
     * @throws ValidationException
     */
    public function validated(array $data): array
    {
        $errors = [];

        $interests = [];
        foreach ((array) ($data['interests'] ?? []) as $interest) {
            $interest = trim(preg_replace('/\s+/u', ' ', (string) $interest) ?? '');

            if ($interest === '') {
                continue;
            }

            if (mb_strlen($interest) > self::MAX_INTEREST_LENGTH) {
                $errors['minat'] = 'Setiap minat maksimal '.self::MAX_INTEREST_LENGTH.' karakter.';
            }

            if (! in_array(mb_strtolower($interest), array_map(mb_strtolower(...), $interests), true)) {
                $interests[] = $interest;
            }
        }

        if (count($interests) > TalentProfile::MAX_INTERESTS) {
            $errors['minat'] = 'Maksimal '.TalentProfile::MAX_INTERESTS.' minat.';
        }

        $text = function (string $key, string $field, int $max) use ($data, &$errors): ?string {
            $value = trim((string) ($data[$key] ?? ''));

            if (mb_strlen($value) > $max) {
                $errors[$field] = 'Maksimal '.$max.' karakter.';
            }

            return $value === '' ? null : $value;
        };

        $clean = [
            'interests' => $interests,
            'skills' => $text('skills', 'dikuasai', self::MAX_SKILLS),
            'organizations' => $text('organizations', 'organisasi', self::MAX_TEXT),
            'junior_high_achievements' => $text('junior_high_achievements', 'smp', self::MAX_TEXT),
        ];

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /**
     * @param  array{interests: list<string>, skills: ?string, organizations: ?string, junior_high_achievements: ?string}  $clean
     * @param  list<File>  $stored
     * @param  list<string>  $removeIds
     *
     * @throws ValidationException
     */
    private function persist(User $actor, Student $student, array $clean, array $stored, array $removeIds): TalentProfile
    {
        Student::query()->whereKey($student->getKey())->lockForUpdate()->firstOrFail();

        $profile = TalentProfile::query()->firstOrNew(['student_id' => $student->getKey()]);
        $profile->fill($clean)->save();

        $current = $profile->certificates()->pluck('files.id')->map(fn ($id): string => (string) $id)->all();
        $remove = array_values(array_intersect($current, array_map('strval', $removeIds)));

        if (count($current) - count($remove) + count($stored) > TalentProfile::MAX_CERTIFICATES) {
            throw ValidationException::withMessages([
                'sertifikat' => 'Maksimal '.TalentProfile::MAX_CERTIFICATES.' sertifikat pendukung.',
            ]);
        }

        if ($remove !== []) {
            $profile->certificates()->detach($remove);
        }

        foreach ($stored as $file) {
            $profile->certificates()->attach($file->getKey(), ['id' => (string) Str::ulid()]);
        }

        activity('talent')
            ->performedOn($profile)
            ->causedBy($actor)
            ->event('talent_profile.saved')
            ->withProperties([
                'interests' => count($clean['interests']),
                'certificates_added' => count($stored),
                'certificates_removed' => count($remove),
            ])
            ->log('talent_profile.saved');

        return $profile->refresh();
    }
}
