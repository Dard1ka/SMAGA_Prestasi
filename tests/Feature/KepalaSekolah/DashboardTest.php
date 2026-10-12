<?php

declare(strict_types=1);

use App\Enums\Level;
use App\Enums\RegistrationStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Enums\WeightCategory;
use App\Livewire\Sekolah\Dashboard;
use App\Models\Achievement;
use App\Models\Competition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    $this->kepsek = F::staff(RoleName::KepalaSekolah);
});

/** One verified achievement of $student in a lomba of $bidang/$level whose final is on $finalOn. */
function w2cAchievement(string $studentName, string $bidang, Level $level, string $finalOn, ResultType $result = ResultType::Juara1): Achievement
{
    $student = F::student($studentName);
    $competition = Competition::factory()->finished()->create(['bidang' => $bidang, 'level' => $level, 'starts_on' => $finalOn, 'final_on' => $finalOn]);

    return Achievement::factory()->scored($level, $result, WeightCategory::A)->create([
        'student_id' => $student->getKey(),
        'competition_id' => $competition->getKey(),
    ]);
}

it('opens for the Kepala Sekolah and refuses other areas', function (): void {
    $this->actingAs($this->kepsek)->get('/kepsek')->assertOk()->assertSee('Prestasi terverifikasi');

    foreach ([RoleName::TataUsaha, RoleName::Humas, RoleName::Guru, RoleName::Admin] as $role) {
        $this->actingAs(F::staff($role))->get('/kepsek')->assertForbidden();
    }

    $this->actingAs(F::activeStudent('Nadia Putri Anindya')->user)->get('/kepsek')->assertForbidden();
});

it('requires dashboard.view inside the component too', function (): void {
    Livewire::actingAs(F::staff(RoleName::TataUsaha))->test(Dashboard::class)->assertForbidden();
    Livewire::actingAs(F::staff(RoleName::Kesiswaan))->test(Dashboard::class)->assertOk();
});

it('builds KPIs and charts from verified achievements only', function (): void {
    w2cAchievement('Raka Pradipta', 'riset', Level::Nasional, '2025-11-10');
    w2cAchievement('Salsabila Kirana', 'seni_budaya', Level::Provinsi, '2025-09-01', ResultType::Juara3);
    w2cAchievement('Aurelia Maharani', 'sains', Level::Kota, '2024-10-01', ResultType::Finalis);
    w2cAchievement('Aurelia Maharani', 'sains', Level::Internasional, '2026-09-15');

    F::registration();                                       // 1 waiting for the Kepala Sekolah
    F::registration(RegistrationStatus::MenungguLaporan, competition: [
        'starts_on' => now()->subDays(60)->format('Y-m-d'), 'final_on' => now()->subDays(50)->format('Y-m-d'),
    ]);                                                      // report overdue (> 45 days after the final)

    $component = Livewire::actingAs($this->kepsek)->test(Dashboard::class);

    $kpi = $component->viewData('kpi');
    expect($kpi['prestasi'])->toBe(4)
        ->and($kpi['siswa'])->toBe(4) // 4 distinct student rows (fixtures create one student per call)
        ->and($kpi['menunggu'])->toBe(1)
        ->and($kpi['lewat'])->toBe(1);

    $perYear = $component->viewData('perYear');
    expect(collect($perYear['years'])->pluck('year')->all())->toBe(['2024/2025', '2025/2026', '2026/2027'])
        ->and($perYear['best'])->toBe(['year' => '2025/2026', 'total' => 2]);

    $perBidang = collect($component->viewData('perBidang'))->mapWithKeys(fn ($r) => [$r['bidang']->value => $r['value']]);
    expect($perBidang->all())->toMatchArray(['sains' => 2, 'riset' => 1, 'seni_budaya' => 1, 'olahraga' => 0]);

    $perLevel = $component->viewData('perLevel');
    expect($perLevel['nationalUp'])->toBe(2);

    $top = $component->viewData('top');
    expect($top[0]['points'])->toBe('100')
        ->and($top)->toHaveCount(4);

    $component->set('tahun', '2025/2026');
    expect($component->viewData('kpi')['prestasi'])->toBe(2);

    $component->set('tahun', 'bukan-tahun')->assertSet('tahun', '');
});

it('links bidang rows to the public drill-down and the waiting tile to the queue', function (): void {
    $html = $this->actingAs($this->kepsek)->get('/kepsek')->assertOk()->getContent();

    if (Route::has('public.prestasi')) {
        expect($html)->toContain(e(route('public.prestasi', ['bidang' => 'sains'])));
    }

    expect($html)->toContain(route('kepsek.persetujuan.index'))
        ->and($html)->not->toContain('style="');
});

// QA7: explore round after UI3 (2026-10-08) ---------------------------------------------------------

it('keeps a long lomba name in "Perlu perhatian" inside its card (QA7)', function (): void {
    $this->actingAs($this->kepsek)->get('/kepsek')
        ->assertOk()
        ->assertSee('flex flex-col gap-3 [overflow-wrap:anywhere]" aria-labelledby="perhatian-title"', false);
});

it('fits the chart of the dashboard on a 360px phone: year labels break after the slash, bars get narrower (QA7)', function (): void {
    w2cAchievement('Nadia Putri Anindya', 'sains', Level::Nasional, '2025-09-20');

    // Four labels such as "2025/2026" side by side were 18px wider than the card on a 360px phone.
    // (Livewire puts its morph markers around the @if, so only the part after the slash is compared.)
    Livewire::actingAs($this->kepsek)->test(Dashboard::class)
        ->assertSeeHtml('/<wbr>2026')
        // The bars of three years needed 278px in a 239px plot: a year group may get narrower than its 16px bars.
        ->assertSeeHtml('class="flex h-full min-w-0 flex-1 items-end justify-center gap-1.5"');
});

it('names the queue of old pending lomba "Verifikasi usulan lomba" (FS1 §2.2)', function (): void {
    Competition::factory()->create(['created_at' => now()->subDays(8)]);
    Competition::factory()->create(['created_at' => now()->subDays(2)]);

    Livewire::actingAs($this->kepsek)->test(Dashboard::class)
        ->assertSee('Verifikasi usulan lomba: 1 belum diperiksa')
        ->assertSee('Di Kesiswaan lebih dari 7 hari.')
        ->assertDontSee('usulan lomba belum diperiksa');
});
