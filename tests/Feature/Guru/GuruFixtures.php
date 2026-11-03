<?php

declare(strict_types=1);

namespace Tests\Feature\Guru;

use App\Enums\MemberRole;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Student;
use App\Models\TalentApplication;
use App\Models\User;
use Tests\Feature\Kesiswaan\KesiswaanFixtures;

/**
 * Fixtures for tests/Feature/Guru (B4, backlog #3). Fictional data only (DESIGN-SPEC-v2 §5 names).
 */
final class GuruFixtures
{
    public static function boot(): void
    {
        KesiswaanFixtures::boot();
    }

    public static function guru(string $name = 'Retno Wulandari, S.Pd.'): User
    {
        return KesiswaanFixtures::staff(RoleName::Guru, $name);
    }

    public static function student(string $name = 'Nadia Putri Anindya', string $classroom = 'XI-D'): Student
    {
        return KesiswaanFixtures::student($name, $classroom);
    }

    /**
     * A registration guided by $mentor (leader = $leader, extra confirmed anggota = $members).
     *
     * @param  list<Student>  $members
     */
    public static function registration(
        ?User $mentor,
        Student $leader,
        RegistrationStatus $status = RegistrationStatus::SuratTerbit,
        array $members = [],
        ?Competition $competition = null,
    ): Registration {
        $registration = Registration::factory()->create([
            'competition_id' => ($competition ?? KesiswaanFixtures::competition())->getKey(),
            'leader_student_id' => $leader->getKey(),
            'type' => $members === [] ? RegistrationType::Individu : RegistrationType::Tim,
            'mentor_id' => $mentor?->getKey(),
            'status' => $status,
        ]);

        foreach ($members as $member) {
            $row = new RegistrationMember(['registration_id' => $registration->getKey(), 'student_id' => $member->getKey(), 'invited_at' => now()]);
            $row->forceFill(['role' => MemberRole::Anggota, 'confirmed_at' => now()])->save();
        }

        return $registration->fresh();
    }

    public static function talent(?User $mentor, Student $student, TalentStatus $status = TalentStatus::Seleksi): TalentApplication
    {
        $application = KesiswaanFixtures::application($student, $status);
        $application->forceFill(['mentor_id' => $mentor?->getKey(), 'assessment_on' => '2026-10-20'])->save();

        return $application->fresh();
    }
}
