<?php

declare(strict_types=1);

namespace Tests\Feature\Siswa\Hasil;

use App\Enums\Bidang;
use App\Enums\Level;
use App\Enums\MemberRole;
use App\Enums\Participation;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Enums\WeightCategory;
use App\Models\Classroom;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Student;
use App\Models\User;
use App\Models\WeightVersion;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Support\TalentGate;

/**
 * Fixtures for tests/Feature/Siswa/Hasil and tests/Feature/Siswa/Talenta (W2-A2). Fictional data only
 * (DESIGN-SPEC-v2 §5 names); the report flow uses a WICE-like team lomba with final 24 Sep 2026.
 */
final class HasilFixtures
{
    /** Seeds roles/permissions and the default weight version. */
    public static function boot(): void
    {
        AuthTestHelpers::seedRoles();
        WeightVersion::factory()->withDefaultValues()->create(['effective_at' => '2024-07-01 00:00:00']);
    }

    /** Active, activated student (DB-fresh model, strict-mode safe). */
    /**
     * An active student. D-153: an active student has chosen a talent (else the talent gate holds every siswa page), so a
     * student outside kelas X gets one ("Lainnya (Membaca)", TalentGate::pass). Kelas X students stay without one, so the
     * gate tests keep testing the gate; `talent: false` gives a student of any class no talent (the talent tests).
     */
    public static function student(string $name = 'Nadia Putri Anindya', string $classroom = 'XI-D', bool $talent = true): Student
    {
        $user = User::factory()->create(['name' => $name])->fresh();
        $user->forceFill([
            'status' => UserStatus::Active,
            'must_change_password' => false,
            'password_changed_at' => now(),
            'email_verified_at' => now(),
        ])->save();

        $room = Classroom::query()->where('name', $classroom)->first()
            ?? Classroom::factory()->level((int) Classroom::levelFromName($classroom))->create(['name' => $classroom]);

        $student = Student::factory()->create(['user_id' => $user->getKey(), 'classroom_id' => $room->getKey()]);

        if ($talent && (int) $room->level !== 10) {
            TalentGate::pass($student);
        }

        return $student->fresh(['user']);
    }

    public static function staff(RoleName $role, string $name = 'Staf STH'): User
    {
        return AuthTestHelpers::staff($role, ['name' => $name, 'email' => str($name)->slug().'.'.$role->value.'@example.test']);
    }

    /** Published lomba (defaults: Riset · Internasional · kategori D, final 24 Sep 2026 like WICE 2026). */
    public static function competition(array $attributes = []): Competition
    {
        return Competition::factory()->published()->create(array_merge([
            'name' => 'WICE 2026',
            'organizer_name' => 'IYSA',
            'bidang' => Bidang::Riset,
            'level' => Level::Internasional,
            'weight_category' => WeightCategory::D,
            'participation' => Participation::Keduanya,
            'max_team_size' => 3,
            'registration_opens_at' => '2026-08-01 00:00:00',
            'registration_closes_at' => '2026-08-30 23:59:59',
            'starts_on' => '2026-09-21',
            'final_on' => '2026-09-24',
            'location' => 'Kuala Lumpur',
        ], $attributes));
    }

    /**
     * Registration with the leader (ketua, confirmed) and optional anggota.
     *
     * @param  list<Student>  $members  anggota (confirmed unless $confirmed = false)
     */
    public static function registration(
        Student $leader,
        RegistrationStatus $status = RegistrationStatus::MenungguLaporan,
        ?Competition $competition = null,
        array $members = [],
        bool $confirmed = true,
        ?User $mentor = null,
    ): Registration {
        $registration = Registration::factory()->create([
            'competition_id' => ($competition ?? self::competition())->getKey(),
            'leader_student_id' => $leader->getKey(),
            'type' => $members === [] ? RegistrationType::Individu : RegistrationType::Tim,
            'mentor_id' => $mentor?->getKey(),
            'status' => $status,
        ]);

        foreach ($members as $member) {
            $row = new RegistrationMember([
                'registration_id' => $registration->getKey(),
                'student_id' => $member->getKey(),
                'invited_at' => now(),
            ]);
            $row->forceFill(['role' => MemberRole::Anggota, 'confirmed_at' => $confirmed ? now() : null])->save();
        }

        return $registration->fresh();
    }

    public static function pdf(string $name = 'sertifikat-wice-2026.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    }

    public static function jpg(string $name = 'foto-1.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 640, 480);
    }

    /** Freeze "today" (09:00 Asia/Jakarta) for now(), today() and CarbonImmutable. */
    public static function today(string $date): void
    {
        $now = CarbonImmutable::parse($date.' 09:00:00', 'Asia/Jakarta');
        CarbonImmutable::setTestNow($now);
        Carbon::setTestNow($now);
    }

    public static function resetToday(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
    }
}
