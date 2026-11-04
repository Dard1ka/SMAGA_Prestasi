<?php

declare(strict_types=1);

use App\Enums\Level;
use App\Enums\RegistrationStatus;
use App\Enums\ReportFileKind;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Enums\TalentTypeCode;
use App\Enums\UserStatus;
use App\Enums\WeightCategory;
use App\Livewire\Siswa\Portofolio;
use App\Models\Achievement;
use App\Models\File;
use App\Models\ReportFile;
use App\Models\Student;
use App\Models\TalentApplication;
use App\Models\TalentBranch;
use App\Models\TalentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

uses(RefreshDatabase::class);

function w2a2Achievement(Student $student, string $name, Level $level, ResultType $result, WeightCategory $category, ?File $certificate = null): Achievement
{
    $registration = F::registration($student, RegistrationStatus::Selesai, F::competition([
        'name' => $name, 'level' => $level, 'weight_category' => $category, 'starts_on' => '2026-08-04', 'final_on' => '2026-08-07',
    ]));
    $achievement = Achievement::factory()->scored($level, $result, $category)->create([
        'student_id' => $student->getKey(),
        'competition_id' => $registration->competition_id,
    ]);

    if ($certificate !== null) {
        ReportFile::factory()->create(['report_id' => $achievement->report_id, 'kind' => ReportFileKind::Sertifikat, 'file_id' => $certificate->getKey()]);
    }

    return $achievement;
}

beforeEach(function (): void {
    F::boot();
    $this->withoutVite();

    $this->nadia = F::student();
    $this->certificate = File::factory()->create(['original_name' => 'sertifikat-opsi-2026.pdf', 'owner_user_id' => $this->nadia->user->getKey()]);

    w2a2Achievement($this->nadia, 'OPSI 2026', Level::Nasional, ResultType::Juara2, WeightCategory::A, $this->certificate); // 68
    w2a2Achievement($this->nadia, 'KRENOVA Kota Semarang 2026', Level::Kota, ResultType::Juara1, WeightCategory::B); // 32
    w2a2Achievement($this->nadia, 'O2SN Kota Semarang 2026', Level::Kota, ResultType::Peserta, WeightCategory::A); // 4

    $this->raka = F::student('Raka Pradipta', 'XII-2');
    w2a2Achievement($this->raka, 'OSN Nasional 2025', Level::Nasional, ResultType::MedaliEmas, WeightCategory::A); // 80 + …
    w2a2Achievement($this->raka, 'OSN Provinsi 2025', Level::Provinsi, ResultType::Juara1, WeightCategory::A); // 60 → 140

    // An alumnus with more points does not push Nadia down ("#n dari siswa aktif", DESIGN-SPEC §5).
    $alumnus = F::student('Ardiansyah Nur Hakim', 'XII-3');
    $alumnus->user->forceFill(['status' => UserStatus::Archived])->save();
    w2a2Achievement($alumnus, 'OSN Nasional 2024', Level::Internasional, ResultType::Juara1, WeightCategory::A);
});

it('shows the profile header, the verified achievements with rumus and the own certificate link', function (): void {
    $osn = TalentType::factory()->code(TalentTypeCode::Osn)->create();
    TalentApplication::factory()->create([
        'student_id' => $this->nadia->getKey(),
        'talent_type_id' => $osn->getKey(),
        'talent_branch_id' => TalentBranch::factory()->create(['talent_type_id' => $osn->getKey(), 'name' => 'Kimia'])->getKey(),
    ]);

    $this->actingAs($this->nadia->user)
        ->get(route('siswa.portofolio'))
        ->assertOk()
        ->assertSee('Nadia Putri Anindya')
        ->assertSee('Akun aktif sampai')
        ->assertSee('Poin kumulatif')
        ->assertSee('104')
        ->assertSee('#2')
        ->assertSee('OPSI 2026')
        ->assertSee('Juara II')
        ->assertSee('80 × 0,85 × 1,00')
        ->assertSee('68 poin')
        ->assertSee('40 × 1,00 × 0,80')
        ->assertSee(route('files.show', $this->certificate), false)
        ->assertSee('OSN · Kimia')
        ->assertDontSee('Menunggu pemetaan Kesiswaan')
        ->assertSee(route('siswa.talenta'), false)
        ->assertDontSee('OSN Provinsi 2025');
});

it('points a student without a talent to "Tambah talenta" (D-142 wording, FS1 fix wave, D-153)', function (): void {
    // D-153: such a student is held on Talenta saya at the next page, so the empty card shows only on a render.
    $bima = F::student('Bima Satria Wicaksono', 'XI-3', talent: false);
    $this->actingAs($bima->user)->get(route('siswa.portofolio'))->assertRedirect(route('siswa.talenta'));

    Livewire::actingAs($bima->user)->test(Portofolio::class)
        ->assertSee('Belum ada talenta. Tambah talenta di Profil saya, tab Talenta saya.')
        ->assertDontSee('penjaringan');
});

it('ranks among active students only', function (): void {
    $this->actingAs($this->raka->user)
        ->get(route('siswa.portofolio'))
        ->assertOk()
        ->assertSee('#1')
        ->assertSee('140')
        ->assertDontSee('OPSI 2026');
});

it('downloads the portfolio as a PDF', function (): void {
    Livewire::actingAs($this->nadia->user)
        ->test(Portofolio::class)
        ->call('download')
        ->assertFileDownloaded('portofolio-nadia-putri-anindya.pdf', contentType: 'application/pdf');
});

it('is only for signed-in students', function (): void {
    $this->get(route('siswa.portofolio'))->assertRedirect(route('login'));

    foreach ([RoleName::Kesiswaan, RoleName::Humas] as $role) {
        $this->actingAs(F::staff($role))->get(route('siswa.portofolio'))->assertForbidden();
    }
});

it('shows an old tidak lolos talent as a plain talent', function (): void {
    $debat = TalentType::factory()->code(TalentTypeCode::Debat)->create();
    TalentApplication::factory()->status(TalentStatus::TidakLolos)->create([
        'student_id' => $this->nadia->getKey(),
        'talent_type_id' => $debat->getKey(),
        'talent_branch_id' => TalentBranch::factory()->create(['talent_type_id' => $debat->getKey(), 'name' => 'Debat Bahasa Inggris (NSDC)'])->getKey(),
    ]);

    $this->actingAs($this->nadia->user)
        ->get(route('siswa.portofolio'))
        ->assertOk()
        ->assertSee('Debat · Debat Bahasa Inggris (NSDC)')
        ->assertDontSee('Tidak lolos')
        ->assertDontSee('Belum lolos')
        ->assertDontSee('Tahap');

    $component = Livewire::actingAs($this->nadia->user)->test(Portofolio::class)
        // Its bidang counts as a bidang of interest like any other choice (D-142).
        ->assertViewHas('bidangMinat', fn (array $bidang): bool => count($bidang) === 1);

    $component->call('download')->assertFileDownloaded('portofolio-nadia-putri-anindya.pdf', contentType: 'application/pdf');

    // The PDF is drawn from the same data: no status column.
    $data = (function (): array {
        return $this->data();
    })->call($component->instance());
    $html = view('livewire.siswa.portofolio.pdf', [...$data, 'generatedOn' => '13 Oktober 2026'])->render();

    expect($html)->toContain('Debat · Debat Bahasa Inggris (NSDC)')
        ->and($html)->toContain('<th>Talenta</th><th>Keterangan</th>')
        ->and($html)->not->toContain('<th>Status</th>')
        ->and($html)->not->toContain('Tidak lolos');
});

// QA7: explore round after UI3 (2026-10-08) ---------------------------------------------------------

it('lets a long word in a prestasi capsule wrap inside the card (QA7)', function (): void {
    $this->actingAs($this->nadia->user)
        ->get(route('siswa.portofolio'))
        ->assertOk()
        ->assertSee('class="flex min-w-0 flex-1 flex-col items-start gap-2 [overflow-wrap:anywhere]"', false);
});
