<?php

declare(strict_types=1);

use App\Actions\Reports\RequestReportRevision;
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
use App\Models\RegistrationMember;
use App\Models\Student;
use App\Models\TalentApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\SimpleExcel\SimpleExcelReader;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();
    $this->withoutVite();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    $this->admin = F::staff(RoleName::Admin, 'Operator STH');
});

/** Is the cell $ref of an .xlsx sheet printed bold? Follows cell → cellXfs style → font, as Excel does. */
function kesiswaanCellIsBold(string $sheetXml, string $stylesXml, string $ref): bool
{
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $sheet = simplexml_load_string($sheetXml);
    $styles = simplexml_load_string($stylesXml);
    $sheet->registerXPathNamespace('m', $ns);
    $styles->registerXPathNamespace('m', $ns);

    $cell = $sheet->xpath("//m:c[@r='{$ref}']")[0] ?? null;
    $xf = $cell === null ? null : ($styles->xpath('//m:cellXfs/m:xf')[(int) $cell['s']] ?? null);
    $font = $xf === null ? null : ($styles->xpath('//m:fonts/m:font')[(int) $xf['fontId']] ?? null);

    return $font !== null && $font->children($ns)->b->count() > 0;
}

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
    $this->actingAs($this->admin)->get(route('admin.import'))->assertOk()->assertSee('Download template Excel');
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
        ->assertSee('Lomba Kesiswaan Sendiri')->assertSee('Upload sendiri')
        ->assertSee('Perlu dilengkapi')
        ->assertDontSee('Lomba Peneliti Belia (LPB) 2026');

    Livewire::actingAs($this->kesiswaan)->test(Index::class)
        ->call('setTab', 'katalog')
        ->assertSee('Lomba Peneliti Belia (LPB) 2026')
        ->assertSee('ISPO 2027');
});

it('publishes a complete queued lomba and rejects one with or without a reason', function (): void {
    $ready = Competition::factory()->create(['name' => 'Lomba Siap', 'submitted_by' => $this->kesiswaan->getKey()]);
    $bad = Competition::factory()->create(['name' => 'Lomba Palsu']);
    $old = Competition::factory()->create(['name' => 'Lomba Lama']);

    // D-103: the reason is optional.
    Livewire::withQueryParams(['tab' => 'verifikasi'])->actingAs($this->kesiswaan)->test(Index::class)
        ->call('publish', $ready->getKey())
        ->assertDispatched('sth-toast')
        ->call('startReject', $old->getKey())
        ->call('reject')
        ->assertHasNoErrors()
        ->call('startReject', $bad->getKey())
        ->set('rejectNote', 'Info tidak resmi.')
        ->call('reject')
        ->assertHasNoErrors();

    expect($ready->fresh()->status)->toBe(CompetitionStatus::Dipublikasikan)
        ->and($old->fresh()->status)->toBe(CompetitionStatus::Ditolak)
        ->and($old->fresh()->verification_note)->toBeNull()
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

it('shows the NISN in full in the talent table (D-137)', function (): void {
    $hana = F::student('Hana Safira', 'X-8', '226227088', '0103456714');
    F::application($hana);

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->assertSee('Hana Safira')
        ->assertSee('0103456714')
        ->assertDontSee('226227088');
});

it('keeps every other role away from the talent component', function (): void {
    $rejected = TalentApplication::factory()->rejected()->create(['student_id' => F::student()->getKey(), 'talent_type_id' => F::talentType()->getKey()]);

    foreach ([F::staff(RoleName::Guru, 'Pak Hendra Kusuma'), F::staff(RoleName::Humas, 'Mas Galih'), $rejected->student->user] as $user) {
        Livewire::actingAs($user)->test(Talenta::class)->assertForbidden();
    }

    expect($rejected->fresh()->status)->toBe(TalentStatus::TidakLolos);
});

it('exports the talenta list with the FS1 columns', function (): void {
    session()->put('auth.password_confirmed_at', time()); // gate + cap + audit: KesiswaanHardeningTest
    $a = F::application();
    $a->forceFill(['note' => 'Catatan internal pembina.', 'student_note' => 'Catatan lama untuk siswa.', 'other_text' => 'Biologi molekuler'])->save();

    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('export')
        ->assertFileDownloaded();

    $path = sys_get_temp_dir().'/talenta-'.uniqid().'.xlsx';
    file_put_contents($path, base64_decode((string) $component->effects['download']['content']));
    $rows = SimpleExcelReader::create($path)->noHeaderRow()->getRows()->all();
    @unlink($path);

    $header = collect($rows)->search(fn (array $row): bool => ($row[0] ?? null) === 'Nama');
    $row = array_combine($rows[$header], $rows[$header + 1]);

    expect($rows[$header])->toBe(Talenta::EXPORT_HEADER)
        ->and($rows[$header])->not->toContain('Catatan')
        ->and($rows[$header])->not->toContain('Tahap')
        ->and($row['Keterangan lainnya'])->toBe('Biologi molekuler')
        ->and(collect($rows)->flatten()->all())->not->toContain('Catatan internal pembina.')
        ->and(collect($rows)->flatten()->all())->not->toContain('Catatan lama untuk siswa.');
});

it('marks the talent export like the other exports: title, who exported it and when, filter, bold header', function (): void {
    session()->put('auth.password_confirmed_at', time());
    $a = F::application();
    $a->forceFill(['other_text' => '=HYPERLINK("http://x","klik")'])->save();

    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('export')
        ->assertFileDownloaded();

    $path = sys_get_temp_dir().'/talenta-'.uniqid().'.xlsx';
    file_put_contents($path, base64_decode((string) $component->effects['download']['content']));
    $rows = SimpleExcelReader::create($path)->noHeaderRow()->getRows()->all();
    $zip = new ZipArchive;
    $zip->open($path);
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $styles = (string) $zip->getFromName('xl/styles.xml');
    $zip->close();
    @unlink($path);

    expect($rows[0][0])->toStartWith('Database talenta siswa')
        ->and($rows[1][0])->toStartWith('Diekspor oleh '.$this->kesiswaan->name.' · ')
        ->and($rows[1][0])->toEndWith(' WIB')
        ->and($rows[2][0])->toBe('Filter: semua talenta')
        // the reader skips the empty 4th row; in the sheet the header is row 5 (A5 below)
        ->and($rows[3][0])->toBe('Nama')
        ->and(collect($rows)->flatten()->all())->toContain('\'=HYPERLINK("http://x","klik")')
        ->and($sheet)->not->toContain('<f>')
        ->and(kesiswaanCellIsBold($sheet, $styles, 'A1'))->toBeTrue()
        ->and(kesiswaanCellIsBold($sheet, $styles, 'A5'))->toBeTrue()
        ->and(kesiswaanCellIsBold($sheet, $styles, 'A6'))->toBeFalse();
});

it('imports students in three steps and never shows the initial password pattern', function (): void {
    session(['auth.password_confirmed_at' => time()]); // QA9: the import needs a recent password confirmation
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
        ->assertSee('Siswa baru') // M1-C: the preview sums up baru · pindah kelas · tidak berubah · ditolak
        ->assertSee('Tanggal lahir tidak valid')
        ->assertSee('Impor 2 siswa')
        ->assertDontSee('sm4g4');

    expect(Student::query()->count())->toBe(0);

    // D-128: "Impor N siswa" asks in its own dialog first (details: ImportSiswaDialogTest).
    $component->call('askImport')
        ->assertDispatched('sth-modal-open', id: 'impor-siswa')
        ->assertSee('Buat 2 akun siswa.')
        ->call('import')->assertSee('2 akun siswa dibuat')->assertDontSee('sm4g4');

    expect(Student::query()->whereIn('nis', ['226227031', '226227088'])->count())->toBe(2)
        ->and(Student::query()->where('nis', '226227004')->exists())->toBeFalse();
});

it('rejects a non-spreadsheet upload on the import screen', function (): void {
    Livewire::actingAs($this->admin)->test(ImportSiswa::class)
        ->set('file', UploadedFile::fake()->createWithContent('foto.png', 'not really'))
        ->assertHasErrors('file');
});

// QA7: explore round after UI3 (2026-10-08) ---------------------------------------------------------

it('names every picture type the cover field takes when the file is refused (QA7)', function (): void {
    $component = Livewire::actingAs($this->kesiswaan)->test(Form::class)
        ->call('open')
        ->set('form', F::form())
        ->set('cover', UploadedFile::fake()->image('poster.gif'))
        ->call('save')
        ->assertHasErrors('cover');

    expect($component->errors()->first('cover'))->toBe('Pilih gambar JPG, PNG, atau WEBP.');
});

it('lets the penyelenggara tag of a report take two lines instead of running out of the panel (QA7)', function (): void {
    F::pendingReport();

    Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class)
        ->assertSeeHtml('chip c-gray chip-sm chip-wrap');
});

it('tells a second tab that a report was already decided instead of showing an error page (QA8)', function (string $decision): void {
    $report = F::pendingReport();
    $first = Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class);
    $second = Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class)->assertSet('selectedId', $report->getKey());

    $first->set('checks', F::allChecks())->call('verify')->assertHasNoErrors();

    // The Action refuses (ReportPolicy::verify), which used to surface as a full-screen 403.
    $second->set('checks', F::allChecks())->set('note', 'Sertifikat buram, kirim ulang.')->call($decision)
        ->assertDispatched('sth-toast', message: 'Laporan ini sudah diputuskan. Antrean sudah diperbarui.', tone: 'warning')
        ->assertSet('selectedId', null);

    expect($report->fresh()->status)->toBe(ReportStatus::Diverifikasi)
        ->and(Achievement::query()->count())->toBe(1);
})->with(['verify', 'requestRevision']);

it('counts only the members still in the team on a queue card (QA8)', function (): void {
    $leader = F::student('Nadia Putri Anindya', 'XI-4');
    $stays = F::student('Raka Pradipta', 'XI-4');
    $left = F::student('Bima Satria Wicaksono', 'XI-4');
    $report = F::pendingReport($leader, [$stays, $left]);
    RegistrationMember::query()->where('registration_id', $report->registration_id)->where('student_id', $left->getKey())
        ->firstOrFail()->forceFill(['confirmed_at' => null, 'declined_at' => now()])->save();

    // The detail and the points count two people; the card said "+2" with the declined member.
    $card = Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class)->viewData('queue')->first();

    expect($card['student'])->toBe('Nadia Putri Anindya · XI-4 +1');
});

it('keeps the address on the report it shows (QA8)', function (): void {
    // The cards and the move after a decision changed the panel but not the address, so a reload or a copied link
    // opened the report of the first link again. The screen names the shown report for the hook in app.js.
    $first = F::pendingReport();
    $second = F::pendingReport(F::student('Raka Pradipta', 'XI-4'));
    $second->forceFill(['submitted_at' => now()->subHours(2)])->save(); // the queue runs oldest first: $first is older

    $screen = Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class)
        ->assertSet('selectedId', $first->getKey())
        ->call('select', $second->getKey())
        ->assertDispatched('sth-address', url: route('kesiswaan.verifikasi.show', $second))
        ->call('select', $first->getKey())
        ->set('checks', F::allChecks())
        ->call('verify')
        ->assertHasNoErrors()
        ->assertSet('selectedId', $second->getKey())
        ->assertDispatched('sth-address', url: route('kesiswaan.verifikasi.show', $second));

    // Decided in another tab with nothing left in the queue: the panel empties and the address goes back to the list.
    app(RequestReportRevision::class)->handle($this->kesiswaan, $second->fresh(), 'Sertifikat buram, kirim ulang.');
    $screen->set('note', 'Sertifikat buram, kirim ulang.')->call('requestRevision')
        ->assertSet('selectedId', null)
        ->assertDispatched('sth-address', url: route('kesiswaan.verifikasi.index'));
});
