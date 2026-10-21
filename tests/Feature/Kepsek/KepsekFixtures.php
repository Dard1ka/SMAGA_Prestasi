<?php

declare(strict_types=1);

namespace Tests\Feature\Kepsek;

use App\Enums\Bidang;
use App\Enums\Level;
use App\Enums\RegistrationStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Models\Achievement;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\Report;
use App\Models\Student;
use App\Models\User;
use Tests\Feature\Admin\AdminTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures;

/**
 * Fixtures for tests/Feature/Kepsek (B5). Fictional names from DESIGN-SPEC-v2 §5 only; staff have a confirmed TOTP.
 */
final class KepsekFixtures
{
    public static function seed(): void
    {
        AdminTestHelpers::seedRoles();
    }

    public static function kepsek(array $attributes = []): User
    {
        return AdminTestHelpers::staff(RoleName::KepalaSekolah, array_merge(['name' => 'Dra. Sri Wahyuni, M.Pd.', 'username' => 'kepsek'], $attributes));
    }

    public static function student(string $name, string $class = 'XI-D'): Student
    {
        return W2cFixtures::student($name, $class);
    }

    /**
     * One verified achievement (with its own lomba, registration and verified report).
     */
    public static function achievement(
        Student $student,
        Bidang $bidang = Bidang::Riset,
        Level $level = Level::Nasional,
        ResultType $result = ResultType::Juara2,
        string $points = '68.00',
        string $finalOn = '2026-09-24',
        string $lomba = 'OPSI 2026',
        ?User $mentor = null,
    ): Achievement {
        $competition = Competition::factory()->finished()->create([
            'name' => $lomba,
            'bidang' => $bidang,
            'level' => $level,
            'organizer_name' => 'Puspresnas',
            'starts_on' => $finalOn,
            'final_on' => $finalOn,
        ]);

        $registration = Registration::factory()->create([
            'competition_id' => $competition->getKey(),
            'leader_student_id' => $student->getKey(),
            'mentor_id' => $mentor?->getKey(),
            'status' => RegistrationStatus::Selesai,
        ]);

        $report = Report::factory()->verified()->create(['registration_id' => $registration->getKey()]);

        return Achievement::factory()->create([
            'report_id' => $report->getKey(),
            'student_id' => $student->getKey(),
            'competition_id' => $competition->getKey(),
            'level' => $level,
            'result' => $result,
            'points' => $points,
            'verified_at' => $finalOn.' 10:00:00',
        ]);
    }
}
