<?php

declare(strict_types=1);

namespace Tests\Feature\Humas;

use App\Enums\Bidang;
use App\Enums\Level;
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
use App\Models\Publication;
use App\Models\Registration;
use App\Models\Report;
use App\Models\StaffProfile;
use App\Models\Student;
use App\Models\User;
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

    public static function event(string $name = 'Upacara Senin, 5 Oktober 2026', ?string $heldOn = null): AppreciationEvent
    {
        return AppreciationEvent::query()->create([
            'name' => $name,
            'held_on' => $heldOn ?? now()->addDays(4)->toDateString(),
        ])->fresh();
    }
}
