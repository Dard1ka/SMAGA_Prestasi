<?php

declare(strict_types=1);

namespace Tests\Feature\Siswa\Lomba;

use App\Enums\Bidang;
use App\Enums\CompetitionStatus;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\Level;
use App\Enums\OrganizerType;
use App\Enums\Participation;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Enums\WeightCategory;
use App\Models\Classroom;
use App\Models\Competition;
use App\Models\Student;
use App\Models\User;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Support\TalentGate;

/**
 * W2-A1 fixtures (fictional names from DESIGN-SPEC-v2 §5 or Faker only).
 */
final class LombaFixtures
{
    public static function roles(): void
    {
        AuthTestHelpers::seedRoles();
    }

    /** Staff routes need confirmed TOTP outside APP_ENV=local; the file tests are not about 2FA. */
    public static function withoutStaffTwoFactor(): void
    {
        config(['fortify.sth.require_staff_2fa' => false]);
    }

    /** Active, activated student (role siswa). Returned fresh so every column is loaded (strict models). */
    /**
     * An active student. D-153: an active student has chosen a talent (else the talent gate holds every siswa page), so a
     * student outside kelas X gets one ("Lainnya (Membaca)", TalentGate::pass). Kelas X students stay without one, so the
     * gate tests keep testing the gate; `talent: false` gives a student of any class no talent.
     */
    public static function student(string $name = 'Nadia Putri Anindya', string $kelas = 'XI-4', array $user = [], bool $talent = true): Student
    {
        $classroom = Classroom::query()->where('name', $kelas)->first()
            ?? Classroom::factory()->level(str_starts_with($kelas, 'XII') ? 12 : (str_starts_with($kelas, 'XI') ? 11 : 10))->create(['name' => $kelas]);

        $student = Student::factory()->activated()->create(['classroom_id' => $classroom->getKey()]);
        $student->user->forceFill(array_merge(['name' => $name, 'status' => UserStatus::Active], $user))->save();

        if ($talent && (int) $classroom->level !== 10) {
            TalentGate::pass($student);
        }

        return $student->fresh(['user', 'classroom']);
    }

    public static function user(Student $student): User
    {
        return User::query()->findOrFail($student->user_id);
    }

    public static function staff(RoleName $role, string $name = 'Hendra Kusuma, S.Pd., M.Si.'): User
    {
        return User::factory()->staff($role)->create(['name' => $name])->fresh();
    }

    /** Published lomba, registration open now. */
    public static function openCompetition(array $attributes = []): Competition
    {
        return Competition::factory()->published()->create(array_merge([
            'name' => 'National Science and Invention Fair (NSIF) 2026',
            'bidang' => Bidang::Riset,
            'level' => Level::Nasional,
            'weight_category' => WeightCategory::D,
            'participation' => Participation::Individu,
            'registration_opens_at' => now()->subDays(10),
            'registration_closes_at' => now()->addDays(20),
            'starts_on' => now()->addDays(35)->format('Y-m-d'),
            'final_on' => now()->addDays(37)->format('Y-m-d'),
        ], $attributes))->fresh();
    }

    public static function teamCompetition(int $max = 3, array $attributes = []): Competition
    {
        return self::openCompetition(array_merge(['participation' => Participation::Tim, 'max_team_size' => $max], $attributes));
    }

    /**
     * A complete "Usulkan lomba" input (CompetitionFields::toAttributes() shape, D-145): one-day individu lomba by a PTN,
     * tingkat kota, registration closing in 20 days, the lomba in 35. No weight fields: the Action computes them.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function usulan(array $overrides = []): array
    {
        $final = now()->addDays(35)->format('Y-m-d');

        return array_merge([
            'name' => 'International Science Project Olympiad (ISPO) 2027',
            'description' => 'Pendaftaran gratis (data fiktif).',
            'bidang' => Bidang::Riset->value,
            'level' => Level::Kota->value,
            'organizer_name' => 'Universitas Negeri Contoh',
            'organizer_type' => 'ptn',
            'organizer_type_other' => null,
            'puspresnas' => false,
            'mode' => 'luring',
            'location' => 'Kota Semarang',
            'registration_opens_at' => null,
            'registration_closes_at' => now()->addDays(20)->format('Y-m-d'),
            'starts_on' => $final,
            'final_on' => $final,
            'participation' => Participation::Individu->value,
            'max_team_size' => null,
            'website_url' => 'https://example.org/ispo',
            'registration_url' => null,
        ], $overrides);
    }

    /**
     * Model attributes of a complete queued student lomba (D-145): what SubmitCompetition stores for usulan(), weight
     * included. Registration closes in 20 days, the lomba runs in 35; individu. Pass to pendingCompetition().
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function usulanAttributes(array $overrides = []): array
    {
        $final = now()->addDays(35)->format('Y-m-d');

        return array_merge([
            'description' => 'Pendaftaran gratis (data fiktif).',
            'bidang' => Bidang::Riset,
            'level' => Level::Kota,
            'organizer_name' => 'Universitas Negeri Contoh',
            'organizer_type' => OrganizerType::Ptn,
            'weight_category' => WeightCategory::C,
            'event_status' => EventStatus::NonPemerintah,
            'mode' => EventMode::Luring,
            'location' => 'Kota Semarang',
            'registration_opens_at' => null,
            'registration_closes_at' => now()->addDays(20)->endOfDay(),
            'starts_on' => $final,
            'final_on' => $final,
            'participation' => Participation::Individu,
            'max_team_size' => null,
            'website_url' => 'https://example.org/ispo',
        ], $overrides);
    }

    public static function pendingCompetition(User $submitter, array $attributes = []): Competition
    {
        return Competition::factory()->proposal()->create(array_merge([
            'name' => 'ISPO 2027',
            'submitted_by' => $submitter->getKey(),
            'status' => CompetitionStatus::MenungguVerifikasi,
        ], $attributes))->fresh();
    }
}
