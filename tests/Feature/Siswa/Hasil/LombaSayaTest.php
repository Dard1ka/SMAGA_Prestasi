<?php

declare(strict_types=1);

use App\Enums\FilePurpose;
use App\Enums\LetterType;
use App\Enums\Level;
use App\Enums\RegistrationStatus;
use App\Enums\ReportFileKind;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Enums\WeightCategory;
use App\Livewire\Siswa\LombaSaya\Index;
use App\Models\Achievement;
use App\Models\File;
use App\Models\Letter;
use App\Models\RegistrationMember;
use App\Models\Report;
use App\Models\ReportFile;
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

    $this->nadia = F::student();
    $this->dimas = F::student('Dimas Arya Nugroho');
    $this->keisha = F::student('Keisha Larasati', 'XI-6');
    $this->mentor = F::staff(RoleName::Guru, 'Retno Wulandari, S.Pd.');

    $this->wice = F::registration($this->nadia, RegistrationStatus::MenungguLaporan, members: [$this->dimas, $this->keisha], mentor: $this->mentor);
    $this->lpb = F::registration($this->nadia, RegistrationStatus::MenungguKepsek, F::competition([
        'name' => 'LPB 2026', 'organizer_name' => 'Center for Young Scientists', 'starts_on' => '2026-10-17', 'final_on' => '2026-10-17',
    ]), members: [$this->dimas]);
});

afterEach(fn () => F::resetToday());

it('lists running and finished lomba and opens the first one with the 6-phase tracker and deadline', function (): void {
    $opsi = F::registration($this->nadia, RegistrationStatus::Selesai, F::competition(['name' => 'OPSI 2026', 'final_on' => '2026-08-07', 'starts_on' => '2026-08-04']));

    $this->actingAs($this->nadia->user)
        ->get(route('siswa.lomba-saya.index'))
        ->assertOk()
        ->assertSee('Lomba saya')
        ->assertSee('WICE 2026')
        ->assertSee('LPB 2026')
        ->assertDontSee('OPSI 2026')
        ->assertSee('Batas 8 Nov · 38 hari lagi')
        ->assertSee('aria-label="Jejak prestasi"', false)
        ->assertSee('Laporkan hasil')
        ->assertSee('Setiap anggota tim mendapat poin penuh.')
        ->assertSee('Retno Wulandari, S.Pd.')
        ->assertSee(route('siswa.lapor', $this->wice), false);

    $this->actingAs($this->nadia->user)
        ->get(route('siswa.lomba-saya.index', ['tab' => 'selesai']))
        ->assertOk()
        ->assertSee('OPSI 2026')
        ->assertDontSee('LPB 2026');

    expect($opsi->status)->toBe(RegistrationStatus::Selesai);
});

it('shows a member (not ketua) the report card without the send button', function (): void {
    $this->actingAs($this->dimas->user)
        ->get(route('siswa.lomba-saya.show', $this->wice))
        ->assertOk()
        ->assertSee('Ketua tim mengirim laporan hasil untuk seluruh tim.')
        ->assertDontSee(route('siswa.lapor', $this->wice), false);
});

it('answers 404 for someone else\'s registration and hides declined invitations', function (): void {
    $rizky = F::student('Rizky Maulana', 'XI-1');

    $this->actingAs($rizky->user)->get(route('siswa.lomba-saya.show', $this->wice))->assertNotFound();

    RegistrationMember::query()->where('registration_id', $this->lpb->getKey())->where('student_id', $this->dimas->getKey())
        ->first()->forceFill(['confirmed_at' => null, 'declined_at' => now()])->save();

    $this->actingAs($this->dimas->user)
        ->get(route('siswa.lomba-saya.index'))
        ->assertOk()
        ->assertSee('WICE 2026')
        ->assertDontSee('LPB 2026');
    $this->actingAs($this->dimas->user)->get(route('siswa.lomba-saya.show', $this->lpb))->assertNotFound();
});

it('is only for signed-in students (role × route)', function (): void {
    $this->get(route('siswa.lomba-saya.index'))->assertRedirect(route('login'));

    foreach ([RoleName::Kesiswaan, RoleName::Guru, RoleName::Admin] as $role) {
        $this->actingAs(F::staff($role, 'Staf '.$role->value))
            ->get(route('siswa.lomba-saya.index'))
            ->assertForbidden();
    }
});

it('lets the ketua cancel while waiting for the Kepala Sekolah (W2-A1 CancelRegistration)', function (): void {
    Livewire::actingAs($this->nadia->user)
        ->test(Index::class, ['registration' => $this->lpb])
        ->assertSee('Batalkan pendaftaran')
        ->call('cancel')
        ->assertDispatched('sth-toast');

    expect($this->lpb->fresh()->status)->toBe(RegistrationStatus::Dibatalkan);

    // Members cannot cancel.
    $again = F::registration($this->nadia, RegistrationStatus::MenungguKepsek, members: [$this->dimas]);
    Livewire::actingAs($this->dimas->user)
        ->test(Index::class, ['registration' => $again])
        ->assertDontSee('Batalkan pendaftaran')
        ->call('cancel');

    expect($again->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek);
});

it('opens the inline report form only for the ketua who may report', function (): void {
    Livewire::actingAs($this->nadia->user)
        ->test(Index::class, ['registration' => $this->wice])
        ->call('openLapor')
        ->assertSet('lapor', true)
        ->assertSee('Hasil yang diraih');

    Livewire::actingAs($this->dimas->user)
        ->test(Index::class, ['registration' => $this->wice])
        ->call('openLapor')
        ->assertForbidden();
});

it('lists both surat tugas with download links through files.show', function (): void {
    $issued = F::registration($this->keisha, RegistrationStatus::SuratTerbit);
    $siswaPdf = File::factory()->purpose(FilePurpose::Letter)->create();
    $guruPdf = File::factory()->purpose(FilePurpose::Letter)->create();
    Letter::factory()->create(['registration_id' => $issued->getKey(), 'type' => LetterType::Siswa, 'sequence' => 312, 'issued_on' => '2026-09-08', 'file_id' => $siswaPdf->getKey()]);
    Letter::factory()->guru()->create(['registration_id' => $issued->getKey(), 'sequence' => 1680, 'issued_on' => '2026-09-08', 'file_id' => $guruPdf->getKey()]);

    $this->actingAs($this->keisha->user)
        ->get(route('siswa.lomba-saya.show', $issued))
        ->assertOk()
        ->assertSee('Surat tugas siswa')
        ->assertSee('400.3.8/312/MRD/IX/2026')
        ->assertSee('Surat perintah tugas guru pendamping')
        ->assertSee(route('files.show', $siswaPdf), false)
        ->assertSee(route('files.show', $guruPdf), false)
        ->assertSee('Terbit 8 Sep');
});

it('shows the verified achievement with its weight snapshot and the certificate', function (): void {
    $done = F::registration($this->keisha, RegistrationStatus::Selesai, F::competition([
        'name' => 'OPSI 2026', 'organizer_name' => 'Puspresnas', 'level' => Level::Nasional, 'weight_category' => WeightCategory::A,
        'starts_on' => '2026-08-04', 'final_on' => '2026-08-07',
    ]));
    $report = Report::factory()->verified()->create(['registration_id' => $done->getKey(), 'claimed_result' => ResultType::Juara2]);
    $certificate = File::factory()->create(['original_name' => 'sertifikat-opsi-2026.pdf']);
    ReportFile::factory()->create(['report_id' => $report->getKey(), 'kind' => ReportFileKind::Sertifikat, 'file_id' => $certificate->getKey()]);
    Achievement::factory()->scored(Level::Nasional, ResultType::Juara2, WeightCategory::A)->create([
        'report_id' => $report->getKey(), 'student_id' => $this->keisha->getKey(), 'competition_id' => $done->competition_id,
        'verified_at' => '2026-08-15 10:00:00',
    ]);

    $this->actingAs($this->keisha->user)
        ->get(route('siswa.lomba-saya.show', $done))
        ->assertOk()
        ->assertSee('Capaian')
        ->assertSee('Juara II')
        ->assertSee('Poin diberikan')
        ->assertSee('80 × 0,85 × 1,00 = 68 poin')
        ->assertSee(route('files.show', $certificate), false);

    expect($report->status)->toBe(ReportStatus::Diverifikasi);
});

it('shows "Laporan terkirim" with the estimate after sending', function (): void {
    $this->wice->forceFill(['status' => RegistrationStatus::LaporanDikirim])->save();
    Report::factory()->create(['registration_id' => $this->wice->getKey(), 'claimed_result' => ResultType::MedaliEmas, 'submitted_by' => $this->nadia->user->getKey()]);

    // Internasional 100 × medali emas 1,00 × kategori D 0,40 = 40.
    $this->actingAs($this->dimas->user)
        ->get(route('siswa.lomba-saya.show', $this->wice))
        ->assertOk()
        ->assertSee('Laporan terkirim')
        ->assertSee('Medali emas')
        ->assertSee('perkiraan <span class="num">40</span> poin untuk setiap anggota', false);
});
