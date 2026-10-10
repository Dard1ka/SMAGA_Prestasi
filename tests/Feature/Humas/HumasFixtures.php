<?php

declare(strict_types=1);

namespace Tests\Feature\Humas;

use App\Enums\Bidang;
use App\Enums\FilePurpose;
use App\Enums\Level;
use App\Enums\MemberRole;
use App\Enums\PublicationStatus;
use App\Enums\RegistrationStatus;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Enums\WeightCategory;
use App\Models\Achievement;
use App\Models\AppreciationEvent;
use App\Models\Classroom;
use App\Models\Competition;
use App\Models\File;
use App\Models\Publication;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Report;
use App\Models\StaffProfile;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Auth\AuthTestHelpers;

/**
 * Fictional Humas fixtures (DESIGN-SPEC-v2 §5 names only): a verified achievement of a consenting
 * student with a mentor, plus its publication row.
 */
final class HumasFixtures
{
    public static function humas(): User
    {
        return AuthTestHelpers::staff(RoleName::Humas);
    }

    /**
     * @param  array<string, mixed>  $studentState  e.g. ['publish_consent' => false]
     * @param  array<string, mixed>  $publicationState  status / scheduled_at / published_at / channels / caption
     */
    public static function publication(
        array $studentState = [],
        array $publicationState = [],
        string $studentName = 'Nadia Putri Anindya',
        string $competitionName = 'OPSI 2026',
        bool $verified = true,
        bool $withEmail = true,
    ): Publication {
        $achievement = self::achievement($studentState, $studentName, $competitionName, $verified, $withEmail);

        $publication = new Publication(['achievement_id' => $achievement->getKey()]);
        $publication->forceFill(array_merge([
            'status' => PublicationStatus::Siap,
            'channels' => [],
            'caption' => null,
        ], $publicationState))->save();

        $publication = $publication->fresh();

        return $verified ? $publication : $publication->setRelation('achievement', $achievement);
    }

    /** @param array<string, mixed> $studentState */
    public static function achievement(
        array $studentState = [],
        string $studentName = 'Nadia Putri Anindya',
        string $competitionName = 'OPSI 2026',
        bool $verified = true,
        bool $withEmail = true,
    ): Achievement {
        $classroom = Classroom::query()->firstWhere('name', 'XI-4')
            ?? Classroom::factory()->level(11)->create(['name' => 'XI-4']);

        $user = User::factory()->create([
            'name' => $studentName,
            'email' => $withEmail ? fake()->unique()->safeEmail() : null,
        ]);

        $student = Student::factory()->create(array_merge([
            'user_id' => $user->getKey(),
            'classroom_id' => $classroom->getKey(),
            'photo_consent' => true,
            'publish_consent' => true,
        ], $studentState));

        $mentor = User::factory()->staff(RoleName::Guru)->create(['name' => 'Retno Wulandari, S.Pd.']);
        StaffProfile::factory()->create(['user_id' => $mentor->getKey(), 'honorific' => 'Bu']);

        $competition = Competition::factory()->finished()->create([
            'name' => $competitionName,
            'bidang' => Bidang::Riset,
            'level' => Level::Nasional,
        ]);

        $registration = Registration::factory()->status(RegistrationStatus::Selesai)->create([
            'competition_id' => $competition->getKey(),
            'leader_student_id' => $student->getKey(),
            'mentor_id' => $mentor->getKey(),
        ]);

        $report = Report::factory()->verified()->create([
            'registration_id' => $registration->getKey(),
            'claimed_result' => ResultType::Juara2,
            'status' => ReportStatus::Diverifikasi,
        ]);

        $achievement = Achievement::factory()
            ->scored(Level::Nasional, ResultType::Juara2, WeightCategory::A)
            ->create([
                'report_id' => $report->getKey(),
                'student_id' => $student->getKey(),
                'competition_id' => $competition->getKey(),
                'verified_at' => now(),
            ])
            ->fresh();

        // achievements.verified_at is NOT NULL in the schema: "unverified" only exists in memory, to
        // exercise the defensive checks in PublicationPolicy / RecordAppreciation.
        if (! $verified) {
            $achievement->verified_at = null;
        }

        return $achievement;
    }

    /**
     * One more confirmed member on the report of $of (a team win): own student, own achievement row with the
     * same lomba, capaian and tingkat (D-08: every member gets a row).
     *
     * @param  array<string, mixed>  $studentState  e.g. ['publish_consent' => false]
     */
    public static function teammate(Achievement $of, string $studentName, string $className = 'XII-2', array $studentState = []): Achievement
    {
        $classroom = Classroom::query()->firstWhere('name', $className)
            ?? Classroom::factory()->level(match (true) {
                str_starts_with($className, 'XII') => 12,
                str_starts_with($className, 'XI') => 11,
                default => 10,
            })->create(['name' => $className]);

        $student = Student::factory()->create(array_merge([
            'user_id' => User::factory()->create(['name' => $studentName])->getKey(),
            'classroom_id' => $classroom->getKey(),
            'photo_consent' => true,
            'publish_consent' => true,
        ], $studentState));

        RegistrationMember::factory()->create([
            'registration_id' => $of->report->registration_id,
            'student_id' => $student->getKey(),
            'role' => MemberRole::Anggota,
            'confirmed_at' => now(),
        ]);

        return Achievement::factory()
            ->scored($of->level, $of->result, $of->weight_category)
            ->create([
                'report_id' => $of->report_id,
                'student_id' => $student->getKey(),
                'competition_id' => $of->competition_id,
                'verified_at' => now(),
            ])
            ->fresh();
    }

    /**
     * A flyer PNG as STH generated it before M3-E (the generator is gone): bytes on the private disk, a
     * `files` row with purpose flyer owned by the Humas user, linked from publications.flyer_file_id.
     */
    public static function legacyFlyer(Publication $publication, User $owner): File
    {
        $path = 'flyer/2026/10/'.Str::lower((string) Str::ulid()).'.png';
        $bytes = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        Storage::disk('local')->put($path, $bytes);

        $file = new File;
        $file->forceFill([
            'disk' => 'local',
            'path' => $path,
            'original_name' => 'flyer-contoh.png',
            'mime' => 'image/png',
            'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'purpose' => FilePurpose::Flyer,
            'owner_user_id' => $owner->getKey(),
        ])->save();

        $publication->forceFill(['flyer_file_id' => $file->getKey()])->save();

        return $file->fresh();
    }

    /**
     * @param  string|null  $heldTime  "07:00"; null = an event made before events had a start time (D-123): the
     *                                 default, so the date-only texts of those rows stay covered
     */
    public static function event(string $name = 'Upacara Senin, 5 Oktober 2026', ?string $heldOn = null, ?string $heldTime = null): AppreciationEvent
    {
        return AppreciationEvent::query()->create([
            'name' => $name,
            'held_on' => $heldOn ?? now()->addDays(4)->toDateString(),
            'held_time' => $heldTime !== null ? $heldTime.':00' : null,
        ])->fresh();
    }
}
