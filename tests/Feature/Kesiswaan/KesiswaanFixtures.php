<?php

declare(strict_types=1);

namespace Tests\Feature\Kesiswaan;

use App\Enums\Bidang;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\Level;
use App\Enums\MemberRole;
use App\Enums\OrganizerType;
use App\Enums\Participation;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Enums\TalentTypeCode;
use App\Enums\UserStatus;
use App\Enums\WeightCategory;
use App\Models\Classroom;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Report;
use App\Models\Student;
use App\Models\TalentApplication;
use App\Models\TalentBranch;
use App\Models\TalentType;
use App\Models\User;
use App\Models\WeightVersion;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Auth\AuthTestHelpers;

/**
 * Fixtures for tests/Feature/Kesiswaan (W2-B). Fictional data only (DESIGN-SPEC-v2 §5 names).
 * Default lomba = "Olimpiade Kimia Nasional Undip 2026" · Nasional · kategori C → Juara III = 80 × 0,70 × 0,60 = 33,6.
 */
final class KesiswaanFixtures
{
    public static function boot(): void
    {
        AuthTestHelpers::seedRoles();
        WeightVersion::factory()->withDefaultValues()->create(['effective_at' => '2024-07-01 00:00:00']);
        config(['fortify.sth.require_staff_2fa' => false]);
    }

    public static function staff(RoleName $role, string $name = 'Ibu Dwi Rahayu'): User
    {
        return AuthTestHelpers::staff($role, ['name' => $name, 'email' => str($name)->slug().'.'.$role->value.'@example.test']);
    }

    public static function student(string $name = 'Rizky Maulana', string $classroom = 'XI-1', ?string $nis = null, ?string $nisn = null): Student
    {
        $user = User::factory()->create(['name' => $name])->fresh();
        $user->forceFill(['status' => UserStatus::Active, 'must_change_password' => false, 'password_changed_at' => now()])->save();

        $room = Classroom::query()->where('name', $classroom)->first()
            ?? Classroom::factory()->level((int) Classroom::levelFromName($classroom))->create(['name' => $classroom]);

        return Student::factory()->create(array_filter([
            'user_id' => $user->getKey(),
            'classroom_id' => $room->getKey(),
            'nis' => $nis,
            'nisn' => $nisn,
        ]))->fresh(['user']);
    }

    /** @param array<string, mixed> $attributes */
    public static function competition(array $attributes = []): Competition
    {
        return Competition::factory()->published()->create(array_merge([
            'name' => 'Olimpiade Kimia Nasional Universitas Diponegoro 2026',
            'organizer_name' => 'Universitas Diponegoro',
            'bidang' => Bidang::Sains,
            'level' => Level::Nasional,
            'weight_category' => WeightCategory::C,
            'participation' => Participation::Keduanya,
            'max_team_size' => 3,
            'starts_on' => '2026-09-12',
            'final_on' => '2026-09-12',
        ], $attributes));
    }

    /** Complete form input for CreateCompetition / UpdateCompetition (NSIF from the mockup). @return array<string, mixed> */
    public static function form(array $overrides = []): array
    {
        return array_merge([
            'name' => 'National Science and Invention Fair (NSIF) 2026',
            'description' => 'Info lomba contoh (fiktif).',
            'bidang' => Bidang::Riset->value,
            'organizer_name' => 'Penyelenggara swasta',
            'organizer_type' => OrganizerType::PerusahaanSwasta->value,
            'weight_category' => WeightCategory::D->value,
            'event_status' => EventStatus::NonPemerintah->value,
            'level' => Level::Nasional->value,
            'mode' => EventMode::Daring->value,
            'registration_opens_at' => '2026-09-15',
            'registration_closes_at' => '2026-10-20',
            'starts_on' => '2026-11-07',
            'final_on' => '2026-11-07',
            'location' => 'Daring',
            'participation' => Participation::Tim->value,
            'max_team_size' => 3,
            'default_mentor_id' => null,
            'website_url' => 'https://example.org/nsif',
            'registration_url' => 'https://example.org/nsif/daftar',
        ], $overrides);
    }

    /**
     * A report waiting for verification: registration laporan_dikirim, leader + confirmed anggota.
     *
     * @param  list<Student>  $members
     */
    public static function pendingReport(
        ?Student $leader = null,
        array $members = [],
        ResultType $claimed = ResultType::Juara3,
        ?User $mentor = null,
        ?Competition $competition = null,
    ): Report {
        $leader ??= self::student();

        $registration = Registration::factory()->create([
            'competition_id' => ($competition ?? self::competition())->getKey(),
            'leader_student_id' => $leader->getKey(),
            'type' => $members === [] ? RegistrationType::Individu : RegistrationType::Tim,
            'mentor_id' => $mentor?->getKey(),
            'status' => RegistrationStatus::LaporanDikirim,
        ]);

        foreach ($members as $member) {
            $row = new RegistrationMember(['registration_id' => $registration->getKey(), 'student_id' => $member->getKey(), 'invited_at' => now()]);
            $row->forceFill(['role' => MemberRole::Anggota, 'confirmed_at' => now()])->save();
        }

        $report = new Report(['registration_id' => $registration->getKey(), 'claimed_result' => $claimed]);
        $report->forceFill([
            'status' => ReportStatus::Dikirim,
            'submitted_by' => $leader->user_id,
            'submitted_at' => now()->subDay(),
        ])->save();

        return $report->fresh();
    }

    /** @return array<string, bool> */
    public static function allChecks(): array
    {
        return ['nama_peserta' => true, 'nama_lomba' => true, 'sertifikat_asli' => true, 'peringkat_sesuai' => true];
    }

    public static function talentType(TalentTypeCode $code = TalentTypeCode::Osn): TalentType
    {
        return TalentType::query()->where('code', $code->value)->first()
            ?? TalentType::factory()->create(['code' => $code, 'name' => $code->label(), 'bidang' => $code->bidang()]);
    }

    public static function application(?Student $student = null, TalentStatus $status = TalentStatus::Terdaftar, string $branch = 'Biologi'): TalentApplication
    {
        $type = self::talentType();
        $branchModel = TalentBranch::query()->where('talent_type_id', $type->getKey())->where('name', $branch)->first()
            ?? TalentBranch::factory()->create(['talent_type_id' => $type->getKey(), 'name' => $branch]);

        return TalentApplication::factory()->status($status)->create([
            'student_id' => ($student ?? self::student('Hana Safira', 'X-8'))->getKey(),
            'talent_type_id' => $type->getKey(),
            'talent_branch_id' => $branchModel->getKey(),
        ])->fresh();
    }

    public static function pdf(string $name = 'juknis-nsif-2026.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    }
}
