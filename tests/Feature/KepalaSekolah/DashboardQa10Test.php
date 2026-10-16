<?php

declare(strict_types=1);

use App\Enums\Level;
use App\Enums\RegistrationStatus;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Enums\WeightCategory;
use App\Livewire\Sekolah\Dashboard;
use App\Models\Achievement;
use App\Models\Report;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;

/*
 * QA10: the shared Kepala Sekolah / Admin dashboard.
 * - "Siswa teratas" sorted on points only and numbered 1–5: ties got different numbers and an order the public
 *   ranking (App\Domain\Leaderboard\Ranker) does not show.
 * - "N laporan lewat batas … Siswa belum melaporkan hasil" also counted registrations whose report was sent on time
 *   and sent back for a revision.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    $this->kepsek = F::staff(RoleName::KepalaSekolah);
});

function qa10LombaVerified(Student $student, Level $level, ResultType $result): Achievement
{
    return Achievement::factory()->scored($level, $result, WeightCategory::A)->create(['student_id' => $student->getKey()]);
}

it('ranks the top five like the public ranking: ties share a rank, the result profile breaks ties (QA10)', function (): void {
    $bima = F::student('Bima Saputra');
    qa10LombaVerified($bima, Level::Nasional, ResultType::Finalis);       // 40
    qa10LombaVerified($bima, Level::Nasional, ResultType::Finalis);       // 40 → 80, lower results
    qa10LombaVerified(F::student('Citra Lestari'), Level::Nasional, ResultType::Juara1); // 80
    qa10LombaVerified(F::student('Ayu Kirana'), Level::Nasional, ResultType::Juara1);    // 80, same standing as Citra
    qa10LombaVerified(F::student('Dewi Anggraini'), Level::Provinsi, ResultType::Juara1); // 60

    $top = Livewire::actingAs($this->kepsek)->test(Dashboard::class)->viewData('top');

    expect(array_column($top, 'name'))->toBe(['Ayu Kirana', 'Citra Lestari', 'Bima Saputra', 'Dewi Anggraini'])
        ->and(array_column($top, 'rank'))->toBe([1, 1, 3, 4])
        ->and(array_column($top, 'points'))->toBe(['80', '80', '80', '60']);
});

it('keeps five rows in "Siswa teratas" (QA10)', function (): void {
    foreach (['Ayu Kirana', 'Bima Saputra', 'Citra Lestari', 'Dewi Anggraini', 'Eka Pratiwi', 'Fajar Nugroho'] as $name) {
        qa10LombaVerified(F::student($name), Level::Nasional, ResultType::Juara1);
    }

    $top = Livewire::actingAs($this->kepsek)->test(Dashboard::class)->viewData('top');

    expect($top)->toHaveCount(5)
        ->and(array_column($top, 'rank'))->toBe([1, 1, 1, 1, 1])
        ->and(array_column($top, 'name'))->toBe(['Ayu Kirana', 'Bima Saputra', 'Citra Lestari', 'Dewi Anggraini', 'Eka Pratiwi']);
});

it('counts as "Belum lapor > 14 hari" only registrations that never sent a report (QA10, D-151)', function (): void {
    $past = ['starts_on' => now()->subDays(60)->format('Y-m-d'), 'final_on' => now()->subDays(50)->format('Y-m-d')];

    F::registration(RegistrationStatus::MenungguLaporan, ['Nadia Putri Anindya'], competition: $past);

    // Sent on time, then sent back for a revision: the registration is back in menunggu_laporan.
    $revised = F::registration(RegistrationStatus::MenungguLaporan, ['Raka Pradipta'], competition: $past);
    Report::factory()->create([
        'registration_id' => $revised->getKey(),
        'status' => ReportStatus::PerluRevisi,
        'submitted_at' => now()->subDays(48),
    ]);

    $component = Livewire::actingAs($this->kepsek)->test(Dashboard::class);

    expect($component->viewData('kpi')['lewat'])->toBe(1)
        ->and(collect($component->viewData('attention'))->pluck('title')->all())->toContain('1 laporan belum dikirim lebih dari 14 hari setelah lomba');
});
