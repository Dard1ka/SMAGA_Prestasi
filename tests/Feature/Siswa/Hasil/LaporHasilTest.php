<?php

declare(strict_types=1);

use App\Enums\Bidang;
use App\Enums\RegistrationStatus;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Enums\SettingKey;
use App\Livewire\Siswa\LombaSaya\LaporHasil;
use App\Models\Report;
use App\Models\Setting;
use App\Models\WeightValue;
use App\Models\WeightVersion;
use App\Notifications\Reports\ReportSubmitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
    F::boot();
    F::today('2026-10-01');
    $this->withoutVite();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan, 'Ibu Dwi Rahayu');
    $this->nadia = F::student();
    $this->dimas = F::student('Dimas Arya Nugroho');
    $this->wice = F::registration($this->nadia, members: [$this->dimas]);
});

afterEach(fn () => F::resetToday());

it('renders the mobile page for the ketua with the deadline and the riset fields', function (): void {
    $this->actingAs($this->nadia->user)
        ->get(route('siswa.lapor', $this->wice))
        ->assertOk()
        ->assertSee('<title>Laporkan hasil · SMAGA Talent Hub</title>', false)
        ->assertSee('WICE 2026 · Riset · Tim 2 orang')
        ->assertSee('Batas 8 Nov 2026 (45 hari setelah final).')
        ->assertSee('Hasil yang diraih')
        ->assertSee('Penghargaan khusus')
        ->assertSee('(final setelah diverifikasi)', false)
        ->assertSee('Khusus lomba riset')
        ->assertSee('Kirim laporan')
        ->assertSee(route('siswa.lomba-saya.show', $this->wice), false);
});

it('refuses members, outsiders, staff and guests', function (): void {
    $this->actingAs($this->dimas->user)
        ->get(route('siswa.lapor', $this->wice))
        ->assertRedirect(route('siswa.lomba-saya.show', $this->wice));

    $this->actingAs(F::student('Rizky Maulana', 'XI-1')->user)->get(route('siswa.lapor', $this->wice))->assertNotFound();
    $this->actingAs(F::staff(RoleName::Kesiswaan, 'Staf lain'))->get(route('siswa.lapor', $this->wice))->assertForbidden();
    auth()->logout();
    $this->get(route('siswa.lapor', $this->wice))->assertRedirect(route('login'));
});

it('previews points live from the active weight version', function (): void {
    // Internasional 100 × Juara II 0,85 × kategori D 0,40 = 34.
    Livewire::actingAs($this->nadia->user)
        ->test(LaporHasil::class, ['registration' => $this->wice])
        ->set('hasil', ResultType::Juara2->value)
        ->assertSee('100 × 0,85 × 0,40 = 34 poin');

    // A newer version wins (Admin changed the kategori D weight).
    $version = WeightVersion::factory()->withDefaultValues()->create(['effective_at' => now()->subHour()]);
    WeightValue::query()->where('weight_version_id', $version->getKey())->where('group', 'organizer')->where('key', 'D')->update(['value' => '0.50']);

    Livewire::actingAs($this->nadia->user)
        ->test(LaporHasil::class, ['registration' => $this->wice])
        ->set('hasil', ResultType::Juara2->value)
        ->assertSee('100 × 0,85 × 0,50 = 42,5 poin');
});

it('sends the report from the form and returns to Lomba saya', function (): void {
    Livewire::actingAs($this->nadia->user)
        ->test(LaporHasil::class, ['registration' => $this->wice])
        ->set('hasil', ResultType::MedaliEmas->value)
        ->set('sertifikat', F::pdf())
        ->set('fotoBaru', [F::jpg('foto-1.jpg'), F::jpg('foto-2.jpg')])
        ->assertCount('foto', 2)
        ->set('judul', 'Biofilter sabut kelapa untuk air sumur payau')
        ->set('abstrak', 'Biofilter berbahan sabut kelapa menurunkan salinitas air sumur rata-rata 38 persen.')
        ->set('laporan', F::pdf('laporan-penelitian.pdf'))
        ->set('asli', true)
        ->call('kirim')
        ->assertHasNoErrors()
        ->assertRedirect(route('siswa.lomba-saya.show', $this->wice));

    $report = Report::query()->where('registration_id', $this->wice->getKey())->firstOrFail();
    expect($report->status)->toBe(ReportStatus::Dikirim)
        ->and($report->claimed_result)->toBe(ResultType::MedaliEmas)
        ->and($report->files()->count())->toBe(4)
        ->and($this->wice->fresh()->status)->toBe(RegistrationStatus::LaporanDikirim);
    Notification::assertSentTo($this->kesiswaan, ReportSubmitted::class);
});

it('shows Indonesian errors and keeps the status when fields are missing', function (): void {
    Livewire::actingAs($this->nadia->user)
        ->test(LaporHasil::class, ['registration' => $this->wice])
        ->call('kirim')
        ->assertHasErrors(['hasil', 'asli', 'sertifikat', 'foto', 'judul', 'abstrak', 'laporan'])
        ->assertSee('Centang pernyataan keaslian dokumen dulu.')
        ->assertSee('Upload sertifikat atau piagam.');

    Livewire::actingAs($this->nadia->user)
        ->test(LaporHasil::class, ['registration' => $this->wice])
        ->set('sertifikat', F::jpg('sertifikat.gif'))
        ->assertHasErrors('sertifikat');

    expect($this->wice->fresh()->status)->toBe(RegistrationStatus::MenungguLaporan);
});

it('hides the riset section for other bidang and caps photos at five', function (): void {
    $o2sn = F::registration(F::student('Yoga Pratama', 'XII-5'), competition: F::competition(['name' => 'O2SN Kota Semarang 2026', 'bidang' => Bidang::Olahraga]));

    Livewire::actingAs($o2sn->leader->user)
        ->test(LaporHasil::class, ['registration' => $o2sn])
        ->assertDontSee('Khusus lomba riset')
        ->set('fotoBaru', array_map(fn (int $i) => F::jpg("foto-{$i}.jpg"), range(1, 4)))
        ->set('fotoBaru', array_map(fn (int $i) => F::jpg("foto-b{$i}.jpg"), range(1, 3)))
        ->assertCount('foto', 5)
        ->call('removeFoto', 0)
        ->assertCount('foto', 4);
});

it('pre-fills a revision with the Kesiswaan note and the earlier answers', function (): void {
    $this->wice->forceFill(['status' => RegistrationStatus::MenungguLaporan])->save();
    $report = Report::factory()->create([
        'registration_id' => $this->wice->getKey(),
        'claimed_result' => ResultType::MedaliPerak,
        'research_title' => 'Biofilter sabut kelapa',
        'research_abstract' => 'Ringkasan lama.',
    ]);
    $report->forceFill(['status' => ReportStatus::PerluRevisi, 'note' => 'Sertifikat buram, upload ulang.'])->save();

    Livewire::actingAs($this->nadia->user)
        ->test(LaporHasil::class, ['registration' => $this->wice])
        ->assertSet('hasil', ResultType::MedaliPerak->value)
        ->assertSet('judul', 'Biofilter sabut kelapa')
        ->assertSee('Perlu revisi')
        ->assertSee('Sertifikat buram, upload ulang.');
});

// QA8: explore round after PR 17 (2026-10-08) -------------------------------------------------------

it('names the report deadline the Admin set, not a fixed 45 days (QA8)', function (): void {
    Setting::put(SettingKey::ReportDeadlineDays, 60);

    $this->actingAs($this->nadia->user)
        ->get(route('siswa.lapor', $this->wice))
        ->assertOk()
        ->assertSee('Batas 23 Nov 2026 (60 hari setelah final).')
        ->assertDontSee('45 hari');
});
