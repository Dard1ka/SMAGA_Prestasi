<?php

declare(strict_types=1);

use App\Enums\CompetitionStatus;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Livewire\Akun\ImportSiswa;
use App\Livewire\Kesiswaan\Lomba\Form;
use App\Livewire\Kesiswaan\Lomba\Index;
use App\Livewire\Kesiswaan\Talenta;
use App\Livewire\Kesiswaan\VerifikasiHasil;
use App\Models\Achievement;
use App\Models\Classroom;
use App\Models\Competition;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();
    $this->withoutVite();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    $this->admin = F::staff(RoleName::Admin, 'Operator STH');
});

dataset('kesiswaan routes', [
    'beranda' => ['kesiswaan.beranda'],
    'kelola lomba' => ['kesiswaan.lomba.index'],
    'verifikasi hasil' => ['kesiswaan.verifikasi.index'],
    'talenta' => ['kesiswaan.talenta.index'],
    'import siswa' => ['kesiswaan.import'],
    'apresiasi (shared Humas screen)' => ['kesiswaan.apresiasi.index'],
]);

it('opens every Kesiswaan screen for Kesiswaan and Admin', function (string $route): void {
    F::pendingReport();
    F::application();

    $this->actingAs($this->kesiswaan)->get(route($route))->assertOk();
    $this->actingAs($this->admin)->get(route($route))->assertOk();
})->with('kesiswaan routes');

it('forbids the other roles and redirects guests', function (string $route): void {
    foreach ([RoleName::Guru, RoleName::KepalaSekolah, RoleName::TataUsaha, RoleName::Humas] as $role) {
        $this->actingAs(F::staff($role, 'Staf '.$role->value))->get(route($route))->assertForbidden();
    }

    $this->actingAs(F::student()->user)->get(route($route))->assertForbidden();

    auth()->logout();
    $this->get(route($route))->assertRedirect(route('login'));
})->with('kesiswaan routes');

it('serves the admin import route to Admin only', function (): void {
    $this->actingAs($this->admin)->get(route('admin.import'))->assertOk()->assertSee('Unduh template Excel');
    $this->actingAs($this->kesiswaan)->get(route('admin.import'))->assertForbidden();
});

it('opens a report detail by URL and 404s unknown ids', function (): void {
    $report = F::pendingReport();

    $this->actingAs($this->kesiswaan)->get(route('kesiswaan.verifikasi.show', $report))->assertOk()->assertSee('Olimpiade Kimia Nasional');
    $this->actingAs($this->kesiswaan)->get('/kesiswaan/verifikasi-hasil/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();
});

it('lists every pending upload in the queue, from any role incl. Kesiswaan itself (D-27)', function (): void {
    Competition::factory()->proposal()->create(['name' => 'ISPO 2027', 'bidang' => 'riset', 'submitted_by' => F::student('Raka Pradipta', 'XII-2')->user_id]);
    Competition::factory()->create(['name' => 'Lomba Guru Upload', 'submitted_by' => F::staff(RoleName::Guru, 'Bu Retno Wulandari')->getKey()]);
    Competition::factory()->create(['name' => 'Lomba Kesiswaan Sendiri', 'submitted_by' => $this->kesiswaan->getKey()]);
    F::competition(['name' => 'Lomba Peneliti Belia (LPB) 2026']);

    Livewire::withQueryParams(['tab' => 'verifikasi'])->actingAs($this->kesiswaan)->test(Index::class)
        ->assertSee('ISPO 2027')->assertSee('Usulan siswa')
        ->assertSee('Lomba Guru Upload')->assertSee('Upload guru')
        ->assertSee('Lomba Kesiswaan Sendiri')->assertSee('Upload kamu')
        ->assertSee('Perlu dilengkapi')
        ->assertDontSee('Lomba Peneliti Belia (LPB) 2026');

    Livewire::actingAs($this->kesiswaan)->test(Index::class)
        ->call('setTab', 'katalog')
        ->assertSee('Lomba Peneliti Belia (LPB) 2026')
        ->assertSee('ISPO 2027');
});

it('publishes a complete queued lomba and requires a note to reject', function (): void {
    $ready = Competition::factory()->create(['name' => 'Lomba Siap', 'submitted_by' => $this->kesiswaan->getKey()]);
    $bad = Competition::factory()->create(['name' => 'Lomba Palsu']);

    Livewire::withQueryParams(['tab' => 'verifikasi'])->actingAs($this->kesiswaan)->test(Index::class)
        ->call('publish', $ready->getKey())
        ->assertDispatched('sth-toast')
        ->call('startReject', $bad->getKey())
        ->call('reject')
        ->assertHasErrors('rejectNote')
        ->set('rejectNote', 'Info tidak resmi.')
        ->call('reject')
        ->assertHasNoErrors();

    expect($ready->fresh()->status)->toBe(CompetitionStatus::Dipublikasikan)
        ->and($bad->fresh()->status)->toBe(CompetitionStatus::Ditolak)
        ->and($bad->fresh()->verification_note)->toBe('Info tidak resmi.');
});

it('creates a lomba from the drawer form into the queue and shows field errors', function (): void {
    Livewire::actingAs($this->kesiswaan)->test(Form::class)
        ->call('open')
        ->assertDispatched('sth-modal-open')
        ->set('form', F::form(['website_url' => 'http://tidak-aman.test']))
        ->call('save')
        ->assertHasErrors('form.website_url')
        ->set('form.website_url', 'https://example.org/nsif')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('lomba-saved');

    $competition = Competition::query()->where('name', 'National Science and Invention Fair (NSIF) 2026')->firstOrFail();
    expect($competition->status)->toBe(CompetitionStatus::MenungguVerifikasi);
});

it('shows the live rumus preview from the active weights', function (): void {
    Livewire::actingAs($this->kesiswaan)->test(Form::class)
        ->set('form.level', 'nasional')
        ->set('form.weight_category', 'D')
        ->assertSee('32'); // 80 × 1,00 × 0,40
});

it('verifies a report from the screen only after the four checks', function (): void {
    $report = F::pendingReport(claimed: ResultType::Juara3);

    $component = Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class)
        ->assertSet('selectedId', $report->getKey())
        ->assertSee('33,6')
        ->call('verify')
        ->assertHasErrors('checks');

    expect($report->fresh()->status)->toBe(ReportStatus::Dikirim);

    $component->set('checks', F::allChecks())->call('verify')->assertHasNoErrors()->assertSee('Terverifikasi · 33,6 poin');

    expect($report->fresh()->status)->toBe(ReportStatus::Diverifikasi)
        ->and(Achievement::query()->count())->toBe(1);
});

it('needs a note to ask for a revision from the screen', function (): void {
    $report = F::pendingReport();

    Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class)
        ->call('requestRevision')
        ->assertHasErrors('note')
        ->set('note', 'Sertifikat buram.')
        ->call('requestRevision')
        ->assertHasNoErrors();

    expect($report->fresh()->status)->toBe(ReportStatus::PerluRevisi);
});

it('masks NIS in the talent table and moves checked rows in bulk', function (): void {
    $hana = F::student('Hana Safira', 'X-8', '226227088');
    $a = F::application($hana);
    $b = F::application(F::student('Aurelia Maharani', 'X-3'), TalentStatus::Pemetaan, 'Kimia');

    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('showPanel', $b->getKey())
        ->assertSee('Hana Safira')
        ->assertSee('2262•••88')
        ->assertDontSee('226227088')
        ->set('checked', [$a->getKey(), $b->getKey()])
        ->call('bulkAdvance');

    expect($a->fresh()->status)->toBe(TalentStatus::Pemetaan)
        ->and($b->fresh()->status)->toBe(TalentStatus::Seleksi);

    $component->set('checked', [$a->getKey()])
        ->call('startBulkReject')
        ->call('bulkReject')
        ->assertHasErrors('bulkNote')
        ->set('bulkNote', 'Belum memenuhi kuota.')
        ->call('bulkReject');

    expect($a->fresh()->status)->toBe(TalentStatus::TidakLolos);
});

it('filters talent rows by stage and needs a note for tidak lolos in the panel', function (): void {
    $a = F::application(F::student('Galang Putra Mahendra', 'X-TAHFIDZ'));
    $bima = F::application(F::student('Bima Satria Wicaksono', 'XII-7'), TalentStatus::Seleksi, 'Kimia');

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('showPanel', $bima->getKey())
        ->call('setTahap', 'seleksi')
        ->assertSee('Bima Satria Wicaksono')
        ->assertDontSee('Galang Putra Mahendra')
        ->call('setTahap', 'semua')
        ->call('showPanel', $a->getKey())
        ->call('rejectPanel')
        ->assertHasErrors('panelNote');

    expect($a->fresh()->status)->toBe(TalentStatus::Terdaftar);
});

it('exports the talent list as Excel', function (): void {
    session()->put('auth.password_confirmed_at', time()); // gate + cap + audit: KesiswaanHardeningTest
    F::application();

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('export')
        ->assertFileDownloaded();
});

it('imports students in three steps and never shows the initial password pattern', function (): void {
    Classroom::factory()->level(10)->create(['name' => 'X-3']);
    Classroom::factory()->level(10)->create(['name' => 'X-8']);

    $csv = "Nama lengkap;NIS;NISN;Tanggal lahir;Kelas saat pendaftaran;No HP\n"
        ."Aurelia Maharani;226227031;0101234557;12-05-2011;X-3;\n"
        ."Hana Safira;226227088;0103456714;03-01-2011;X-8;081234567890\n"
        ."Galang Putra Mahendra;226227004;0102345686;31-02-2011;X-3;\n";
    $file = UploadedFile::fake()->createWithContent('data-siswa-2026-2027.csv', $csv);

    $component = Livewire::actingAs($this->kesiswaan)->test(ImportSiswa::class)
        ->call('downloadTemplate')
        ->assertFileDownloaded('template-import-siswa.xlsx')
        ->set('schoolYear', '2026/2027')
        ->set('file', $file)
        ->assertHasNoErrors()
        ->assertSee('Siap diimpor')
        ->assertSee('Tanggal lahir tidak valid')
        ->assertSee('Impor 2 siswa')
        ->assertDontSee('sm4g4');

    expect(Student::query()->count())->toBe(0);

    $component->call('import')->assertSee('2 akun siswa dibuat')->assertDontSee('sm4g4');

    expect(Student::query()->whereIn('nis', ['226227031', '226227088'])->count())->toBe(2)
        ->and(Student::query()->where('nis', '226227004')->exists())->toBeFalse();
});

it('rejects a non-spreadsheet upload on the import screen', function (): void {
    Livewire::actingAs($this->admin)->test(ImportSiswa::class)
        ->set('file', UploadedFile::fake()->createWithContent('foto.png', 'not really'))
        ->assertHasErrors('file');
});
