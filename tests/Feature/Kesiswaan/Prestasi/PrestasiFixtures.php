<?php

declare(strict_types=1);

namespace Tests\Feature\Kesiswaan\Prestasi;

use App\Enums\Bidang;
use App\Enums\EventStatus;
use App\Enums\Level;
use App\Enums\MemberRole;
use App\Enums\OrganizerType;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Enums\WeightCategory;
use App\Models\Achievement;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Report;
use App\Models\Student;
use App\Models\User;
use App\Models\WeightVersion;
use Tests\Feature\Kesiswaan\KesiswaanFixtures;

/**
 * Fixtures for tests/Feature/Kesiswaan/Prestasi (B4, backlog #4). Fictional data only (DESIGN-SPEC-v2 §5).
 * One verified report per call; points from the default weights (Nasional 80 · Juara I 1,00 · Kategori A 1,00).
 */
final class PrestasiFixtures
{
    private ?WeightVersion $weights = null;

    private ?User $verifier = null;

    /** @param array<string, mixed> $attributes */
    public function lomba(string $name, Bidang $bidang = Bidang::Sains, Level $level = Level::Nasional, string $finalOn = '2026-09-12', array $attributes = []): Competition
    {
        return KesiswaanFixtures::competition(array_merge([
            'name' => $name,
            'bidang' => $bidang,
            'level' => $level,
            'weight_category' => WeightCategory::A,
            'organizer_type' => OrganizerType::PemerintahPusat,
            'event_status' => EventStatus::Puspresnas,
            'starts_on' => $finalOn,
            'final_on' => $finalOn,
        ], $attributes));
    }

    /**
     * Verified result of one registration: one Achievement per student (every member gets full points).
     *
     * @param  list<Student>  $students  first = ketua
     * @return list<Achievement>
     */
    public function prestasi(array $students, Competition $competition, ResultType $result = ResultType::Juara1, ?User $mentor = null, ?User $verifier = null): array
    {
        $this->weights ??= WeightVersion::query()->first() ?? WeightVersion::factory()->withDefaultValues()->create();

        $registration = Registration::factory()->create([
            'competition_id' => $competition->getKey(),
            'leader_student_id' => $students[0]->getKey(),
            'type' => count($students) > 1 ? RegistrationType::Tim : RegistrationType::Individu,
            'mentor_id' => $mentor?->getKey(),
            'status' => RegistrationStatus::Selesai,
        ]);

        foreach (array_slice($students, 1) as $member) {
            $row = new RegistrationMember(['registration_id' => $registration->getKey(), 'student_id' => $member->getKey(), 'invited_at' => now()]);
            $row->forceFill(['role' => MemberRole::Anggota, 'confirmed_at' => now()])->save();
        }

        $report = Report::factory()->verified()->create([
            'registration_id' => $registration->getKey(),
            'claimed_result' => $result,
            'verified_by' => ($verifier ?? $this->verifier ??= KesiswaanFixtures::staff(RoleName::Kesiswaan, 'Bapak Verifikator Kesiswaan'))->getKey(),
        ]);

        return array_map(fn (Student $student): Achievement => Achievement::factory()
            ->scored($competition->level, $result, $competition->weight_category)
            ->create([
                'report_id' => $report->getKey(),
                'student_id' => $student->getKey(),
                'competition_id' => $competition->getKey(),
                'weight_version_id' => $this->weights->getKey(),
                'verified_at' => $competition->final_on?->addDays(10)->toDateTimeString() ?? now()->toDateTimeString(),
            ])->fresh(), $students);
    }
}
