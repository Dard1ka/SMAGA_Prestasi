<?php

declare(strict_types=1);

use App\Actions\Accounts\ImportStudents;
use App\Domain\Files\StoreUpload;
use App\Enums\RoleName;
use App\Enums\StudentImportStatus;
use App\Enums\UserStatus;
use App\Jobs\ImportStudentsJob;
use App\Livewire\Akun\ImportSiswa;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\StudentImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\SimpleExcel\SimpleExcelReader;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

uses(RefreshDatabase::class);

/*
 | SV1 second browser check (Playwright, 2026-10-31), Import siswa. (1) After a QUEUED import finished, the next file
 | showed its preview with only "Impor file lain": the finished import stayed on the screen and hid "Impor N siswa".
 | (2) The step-3 tiles broke "1114" into "111" / "4" at 1024-1440 px (viewport breakpoints inside the 1fr column next
 | to steps 1-2) and the row status was the last table column, off-screen there. (3) A file without students said
 | "Tidak ada baris yang ditolak.". (4) A valid row with a cleaned cell could not be found under "Ada catatan" or in
 | "Download baris yang perlu dicek". (5) The first slice of a queued import read "Menunggu giliran" under "Sedang
 | mengimpor", and a run of corrections only read "0 akun siswa dibuat". Fictional data only.
 */

beforeEach(function (): void {
    F::boot();
    Queue::fake();
    $this->withoutVite();
    Classroom::factory()->create(['name' => 'X-1', 'level' => 10]);
    Classroom::factory()->create(['name' => 'XI-4', 'level' => 11]);
    // SV6, D-184: a file holds one tingkat, so a class move in these files stays inside kelas X.
    Classroom::factory()->create(['name' => 'X-2', 'level' => 10]);
    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    session(A::passwordConfirmed());
});

/** @param list<list<string>> $rows */
function r3iFile(array $rows, string $name = 'data-siswa.csv'): UploadedFile
{
    $lines = ['Nama lengkap,NIS,NISN,Tanggal lahir,Kelas,No HP', ...array_map(fn (array $row): string => implode(',', $row), $rows)];

    return UploadedFile::fake()->createWithContent($name, implode("\n", $lines)."\n");
}

/** Letters only (names may not hold digits): 1 → A, 27 → AA. */
function r3iLetters(int $n): string
{
    $s = '';

    do {
        $s = chr(65 + $n % 26).$s;
        $n = intdiv($n, 26) - 1;
    } while ($n >= 0);

    return $s;
}

/** A registered student in X-1 with the dates of X-1 in 2026/2027 (kelas XII from Jul 2028, archive Jan 2030). */
function r3iRegistered(string $name, string $nis, string $nisn): Student
{
    $created = Student::factory()->create([
        'nis' => $nis,
        'nisn' => $nisn,
        'birth_date' => '2011-05-12',
        'classroom_id' => Classroom::query()->where('name', 'X-1')->value('id'),
        'import_school_year' => '2026/2027',
        'grade12_start_date' => '2028-07-01',
        'archive_at' => '2030-01-01',
        'phone' => null,
    ]);
    $created->user->forceFill(['name' => $name, 'status' => UserStatus::Active, 'must_change_password' => false])->save();

    return $created->refresh();
}

/** SYNC_MAX_ROWS + 1 new students: more rows to write than the synchronous limit, so the import is queued. */
function r3iBigFile(): UploadedFile
{
    $rows = [];

    foreach (range(1, ImportStudentsJob::SYNC_MAX_ROWS + 1) as $i) {
        $rows[] = ['Siswa Uji '.r3iLetters($i), sprintf('2262%05d', 100 + $i), sprintf('01%08d', 100 + $i), '12-05-2011', 'X-1', ''];
    }

    return r3iFile($rows);
}

/** The import screen after "Impor 51 siswa" queued its import (nothing ran yet: Queue::fake()). */
function r3iQueued(User $actor): Testable
{
    return Livewire::actingAs($actor)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', r3iBigFile())
        ->call('askImport')
        ->call('import')
        ->assertHasNoErrors()
        ->assertSee('Impor file lain');
}

/** A queued import of $actor in the given state, as ImportStudentsJob leaves it between slices. */
function r3iJob(User $actor, array $state): StudentImport
{
    $import = new StudentImport([
        'user_id' => $actor->getKey(),
        'extension' => 'csv',
        'school_year' => '2026/2027',
        'file_name' => 'data-siswa.csv',
        'file_sha256' => str_repeat('c', 64),
    ]);
    $import->forceFill($state)->save();

    return $import;
}

function r3iRunJob(StudentImport $import): void
{
    (new ImportStudentsJob($import->getKey()))->handle(app(ImportStudents::class), app(StoreUpload::class));
}

/** The "Impor N siswa" button under the preview carries `disabled`. */
function r3iImportDisabled(Testable $screen): bool
{
    return preg_match('/<button[^>]*wire:click="askImport"[^>]*\sdisabled[\s>]/', $screen->html()) === 1;
}

/** The rendered HTML without Livewire's block markers. */
function r3iHtml(Testable $screen): string
{
    return (string) preg_replace('/<!--\[if (?:END)?BLOCK\]><!\[endif\]-->/', '', $screen->html());
}

// (1) The next file after a queued import ---------------------------------------------------------------------------

it('offers "Impor N siswa" for the next file once a queued import is done (SV1 second check)', function (): void {
    $screen = r3iQueued($this->kesiswaan);
    $import = StudentImport::query()->sole();
    r3iRunJob($import);

    expect($import->refresh()->status)->toBe(StudentImportStatus::Done);

    $screen->call('$refresh')
        ->assertSee('51 akun siswa dibuat')
        ->assertSee('Impor file lain')
        // The next file: the finished import goes with the old preview, the new preview offers its own import.
        ->set('file', r3iFile([['Dewi Anggraini', '226227005', '0101234505', '12-05-2011', 'X-1', '']]))
        ->assertHasNoErrors()
        ->assertSet('importId', null)
        ->assertSet('queuedPreview', null)
        // Read after the import was over: nothing to read again before the dialog.
        ->assertSet('previewWhileImporting', false)
        ->assertViewHas('stepNote', '1 baris terbaca')
        ->assertSee('Impor 1 siswa')
        ->assertDontSee('Impor file lain')
        ->assertDontSee('51 akun siswa dibuat');

    expect(r3iImportDisabled($screen))->toBeFalse();

    $screen->call('askImport')
        ->assertDispatched('sth-modal-open', id: ImportSiswa::IMPORT_DIALOG)
        ->call('import')
        ->assertHasNoErrors()
        ->assertSee('1 akun siswa dibuat')
        ->assertViewHas('stepNote', 'Selesai');

    expect(Student::query()->where('nis', '226227005')->exists())->toBeTrue();
});

it('lets a file read while a queued import runs wait for it, and keeps the banner (SV1 second check)', function (): void {
    $screen = r3iQueued($this->kesiswaan);
    $import = StudentImport::query()->sole();

    $screen->set('file', r3iFile([['Dewi Anggraini', '226227005', '0101234505', '12-05-2011', 'X-1', '']]))
        ->assertHasNoErrors()
        // The running import stays on screen and keeps polling.
        ->assertSet('importId', $import->getKey())
        ->assertSee('Menunggu antrean')
        ->assertSeeHtml('wire:poll.2s')
        // The new file is counted itself and offers its own import, which waits.
        ->assertViewHas('stepNote', '1 baris terbaca')
        ->assertSee('Impor 1 siswa')
        ->assertSee('File ini bisa diimpor setelah impor yang sedang berjalan selesai.')
        ->assertDontSee('Impor file lain');

    expect(r3iImportDisabled($screen))->toBeTrue();

    // Forbidden: a forged click is refused before the dialog and before the password page; nothing is written.
    $screen->call('askImport')
        ->assertHasErrors(['file'])
        ->assertNotDispatched('sth-modal-open')
        ->assertNoRedirect()
        ->assertSee(ImportSiswa::IMPORT_RUNNING)
        ->call('import')
        ->assertSet('imported', null);

    expect(Student::query()->where('nis', '226227005')->exists())->toBeFalse()
        ->and(StudentImport::query()->count())->toBe(1);

    // The import finishes: the waiting file can go, and the finished import gives way to its result.
    r3iRunJob($import);

    $screen->call('$refresh')
        ->assertSee('51 akun siswa dibuat')
        ->assertDontSee('File ini bisa diimpor setelah impor yang sedang berjalan selesai.')
        ->assertDontSeeHtml('wire:poll.2s');

    expect(r3iImportDisabled($screen))->toBeFalse();

    // SV1 review: the file was read while the import ran, so the first click reads it again and asks nothing yet;
    // the finished import keeps its banner until the next import is written.
    $screen->call('askImport')
        ->assertNotDispatched('sth-modal-open')
        ->assertDispatched('sth-toast', message: ImportSiswa::PREVIEW_RECHECKED, tone: 'warning')
        ->assertSet('previewWhileImporting', false)
        ->assertSee('Impor 1 siswa')
        ->assertSee('51 akun siswa dibuat');

    $screen->call('askImport')
        ->assertDispatched('sth-modal-open', id: ImportSiswa::IMPORT_DIALOG)
        ->call('import')
        ->assertHasNoErrors()
        ->assertSet('importId', null)
        ->assertSee('1 akun siswa dibuat')
        ->assertDontSee('51 akun siswa dibuat');

    expect(Student::query()->where('nis', '226227005')->exists())->toBeTrue();
});

it('keeps a running import on screen when the file read meanwhile is dropped (SV1 second check)', function (): void {
    $screen = r3iQueued($this->kesiswaan);
    $import = StudentImport::query()->sole();

    $screen->set('file', r3iFile([['Dewi Anggraini', '226227005', '0101234505', '12-05-2011', 'X-1', '']]))
        ->call('startOver')
        ->assertSet('importId', $import->getKey())
        ->assertSet('queuedPreview', null)
        ->assertSee('Menunggu antrean')
        ->assertSeeHtml('wire:poll.2s')
        ->assertViewHas('stepNote', StudentImportStatus::Queued->label());
});

// (2) Tiles and table in the step-3 column --------------------------------------------------------------------------

it('takes the tile columns from the step-3 column and shows the row status under the name (SV1 second check)', function (): void {
    r3iRegistered('Rafi Pratama', '226227001', '0101234501');

    $screen = Livewire::actingAs($this->kesiswaan)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', r3iFile([
            ['Rafi Pratama', '226227001', '0101234501', '12-05-2011', 'X-2', ''],       // moves to X-2
            ['Dewi Anggraini', '226227005', '0101234505', '12-05-2011', 'X-1', ''],     // new
            ['Galang Putra Mahendra', '226227006', '010123450', '12-05-2011', 'X-1', ''], // NISN of 9 digits
        ]))
        ->assertHasNoErrors();
    $html = r3iHtml($screen);

    // The grid reads the width of its own column (a container query), never the viewport: 1 column of compact rows
    // under 460 px (1024 px screens, phones), 2 from 460 px (1280-1440 px screens), 3 from 720 px.
    expect($html)->toContain('<div class="@container">')
        ->toContain('<div class="grid auto-rows-fr grid-cols-1 gap-3 @min-[460px]:grid-cols-2 @min-[720px]:grid-cols-3">')
        ->not->toContain('xl:grid-cols-4')
        // Siswa baru, Pindah kelas, Tidak berubah (0, quiet), Ditolak: one compact row each (layout="row"); "Data
        // diperbarui" is hidden at 0.
        ->and(substr_count($html, 'class="card flex items-center gap-3 px-4 py-3"'))->toBe(4)
        // No last "Status" column that scrolls out of the card.
        ->and($html)->not->toContain('<th scope="col">Status</th>')
        ->toContain('<th scope="col" class="min-w-48">Nama</th>');

    // Per row: Baris, then Nama with the status under the name, then NISN.
    $cells = function (int $line) use ($html): array {
        preg_match('/<tr wire:key="row-'.$line.'"[^>]*>(.*?)<\/tr>/s', $html, $row);
        preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $row[1] ?? '', $cells);

        return $cells[1];
    };

    expect($cells(2))->toHaveCount(7)
        ->and($cells(2)[1])->toContain('Rafi Pratama')->toMatch('/class="chip c-blue chip-sm">\s*Pindah kelas\s*<\/span>/')
        ->and($cells(2)[2])->toContain('0101234501')
        ->and($cells(3)[1])->toContain('Dewi Anggraini')->toMatch('/class="chip c-green chip-sm">\s*Baru\s*<\/span>/')
        ->and($cells(4)[1])->toContain('Galang Putra Mahendra')->toContain('NISN harus 10 angka (format sel sebagai teks).');
});

// (3) A file without students ---------------------------------------------------------------------------------------

it('says that the untouched template holds no student data (SV1 second check)', function (): void {
    $screen = Livewire::actingAs($this->kesiswaan)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)->call('downloadTemplate');
    $template = base64_decode((string) data_get($screen->effects, 'download.content'));

    $screen->set('schoolYear', '2026/2027')
        ->set('file', UploadedFile::fake()->createWithContent('template-import-siswa.xlsx', $template))
        ->assertHasNoErrors()
        ->assertViewHas('rowCount', 0)
        ->assertViewHas('stepNote', '0 baris terbaca')
        ->assertSee(ImportSiswa::EMPTY_FILE)
        ->assertSee('File ini tidak berisi data siswa. Isi template, lalu upload lagi.')
        ->assertDontSee('Tidak ada baris yang ditolak.')
        // Nothing to filter, count or explain; the file can be replaced.
        ->assertDontSeeHtml('aria-label="Tampilkan baris"')
        ->assertDontSeeHtml('<div class="@container">')
        ->assertDontSee('Arsip otomatis =')
        ->assertSee('Ganti file');

    expect(r3iImportDisabled($screen))->toBeTrue();
});

it('keeps the sentences of the empty filters for a file with students (SV1 second check)', function (): void {
    Livewire::actingAs($this->kesiswaan)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', r3iFile([['Dewi Anggraini', '226227005', '0101234505', '12-05-2011', 'X-1', '']]))
        ->set('filter', 'bad')
        ->assertSee('Tidak ada baris yang ditolak.')
        ->assertDontSee(ImportSiswa::EMPTY_FILE)
        ->set('filter', 'notes')
        ->assertSee('Tidak ada baris dengan catatan.')
        ->assertDontSee(ImportSiswa::EMPTY_FILE);
});

// (4) Cleaned cells -------------------------------------------------------------------------------------------------

it('gives a valid row with a cleaned cell a note, and leaves a refused one without (SV1 second check)', function (): void {
    r3iRegistered('Rafi Pratama', '226227001', '0101234501');
    $path = tempnam(sys_get_temp_dir(), 'r3i-');
    file_put_contents($path, implode("\n", [
        'Nama lengkap,NIS,NISN,Tanggal lahir,Kelas,No HP',
        '=Nadia Putri Anindya,226227011,0101234511,12-05-2011,X-1,',  // new, a cleaned name
        'Rafi Pratama,-226227001,0101234501,12-05-2011,X-1,',         // registered, a cleaned NIS, nothing to write
        '=Galang Putra,226227013,010123451,12-05-2011,X-1,',          // a cleaned name, refused (NISN of 9 digits)
        'Dewi Anggraini,226227005,0101234505,12-05-2011,X-1,',        // nothing cleaned
    ])."\n");

    $rows = collect(app(ImportStudents::class)->handle($this->kesiswaan, $path, 'csv', '2026/2027')->rows)->keyBy('row');
    @unlink($path);

    expect($rows[2])->toMatchArray(['name' => 'Nadia Putri Anindya', 'sanitized' => true, 'errors' => [], 'notes' => [ImportStudents::NOTE_SANITIZED]])
        ->and($rows[3])->toMatchArray(['nis' => '226227001', 'sanitized' => true, 'errors' => [], 'changes' => false, 'notes' => [ImportStudents::NOTE_SANITIZED]])
        ->and($rows[4])->toMatchArray(['sanitized' => true, 'notes' => []])
        ->and($rows[4]['errors'])->not->toBe([])
        ->and($rows[5])->toMatchArray(['sanitized' => false, 'notes' => []])
        ->and(ImportStudents::NOTE_SANITIZED)->toBe('Sel dibersihkan: tanda = + - @ di awal sel dibuang.');
});

it('lists a valid row with a cleaned cell under "Ada catatan" and in its download (SV1 second check)', function (): void {
    r3iRegistered('Rafi Pratama', '226227001', '0101234501');

    $screen = Livewire::actingAs($this->kesiswaan)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', r3iFile([
            ['=Nadia Putri Anindya', '226227011', '0101234511', '12-05-2011', 'X-1', ''],
            ['Rafi Pratama', '-226227001', '0101234501', '12-05-2011', 'X-1', ''],
            ['=Galang Putra', '226227013', '010123451', '12-05-2011', 'X-1', ''],
            ['Dewi Anggraini', '226227005', '0101234505', '12-05-2011', 'X-1', ''],
        ]))
        ->assertHasNoErrors()
        ->assertViewHas('notedCount', 2)
        ->assertViewHas('sanitizedCount', 3)
        ->assertSee('Ada catatan · 2')
        ->assertSee('3 baris berisi sel yang diawali = + - @');

    // A valid row says it in its note; the chip stays for the refused row, which carries no notes.
    $html = r3iHtml($screen);
    expect(substr_count($html, ImportStudents::NOTE_SANITIZED))->toBe(2)
        ->and(preg_match_all('/class="chip c-orange chip-sm">\s*Sel dibersihkan\s*<\/span>/', $html))->toBe(1);

    $screen->set('filter', 'notes')
        ->assertViewHas('listTotal', 2)
        ->assertSee('Nadia Putri Anindya')
        ->assertSee('Rafi Pratama')
        ->assertDontSee('Dewi Anggraini')
        ->assertSee(ImportStudents::NOTE_SANITIZED);

    // "Download baris yang perlu dicek": both cleaned valid rows with the note, the refused row with its error.
    $base = (string) tempnam(sys_get_temp_dir(), 'r3i');
    $path = $base.'.xlsx';
    file_put_contents($path, base64_decode((string) $screen->call('downloadInvalid')->effects['download']['content']));
    $sheet = SimpleExcelReader::create($path)->getRows()
        ->mapWithKeys(fn (array $r): array => [$r['Nama lengkap'] => [$r['Status'], $r['Keterangan']]])->all();
    @unlink($path);
    @unlink($base);

    expect(array_keys($sheet))->toBe(['Nadia Putri Anindya', 'Rafi Pratama', 'Galang Putra'])
        ->and($sheet['Nadia Putri Anindya'])->toBe(['Baru', ImportStudents::NOTE_SANITIZED])
        ->and($sheet['Rafi Pratama'])->toBe(['Tidak berubah', ImportStudents::NOTE_SANITIZED])
        ->and($sheet['Galang Putra'][0])->toBe('Ditolak')
        ->and($sheet['Galang Putra'][1])->toContain('NISN harus 10 angka');
});

// (5) Progress of a queued import -----------------------------------------------------------------------------------

it('says what a queued import is doing before and during its first slice (SV1 second check)', function (): void {
    $import = r3iJob($this->kesiswaan, ['status' => StudentImportStatus::Queued]);

    $screen = Livewire::actingAs($this->kesiswaan)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)
        ->assertSee(StudentImportStatus::Queued->label())
        ->assertSee('Menunggu giliran. Halaman ini diperbarui otomatis dan boleh ditinggalkan.')
        ->assertDontSee('akun siswa dibuat');

    // The worker took it: the first slice runs, the job has not saved the row count of the file yet (total_rows 0).
    // That slice already writes rows, so it says "Mulai mengimpor…" (SV1 review; it said "Menyiapkan impor…").
    $import->forceFill(['status' => StudentImportStatus::Running, 'started_at' => now()])->save();

    $screen->call('$refresh')
        ->assertSee(StudentImportStatus::Running->label())
        ->assertSee('Mulai mengimpor… Halaman ini diperbarui otomatis dan boleh ditinggalkan.')
        ->assertDontSee('Menyiapkan impor')
        ->assertDontSee('Menunggu giliran')
        ->assertDontSee('akun siswa dibuat')
        ->assertSeeHtml('wire:poll.2s');
});

it('names no "0 akun siswa dibuat" while a run of corrections goes on (SV1 second check)', function (): void {
    $import = r3iJob($this->kesiswaan, ['status' => StudentImportStatus::Running, 'total_rows' => 300, 'processed_rows' => 100, 'created_count' => 0, 'updated_count' => 0]);

    $screen = Livewire::actingAs($this->kesiswaan)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)
        ->assertSee('100 dari 300 baris diproses. Halaman ini diperbarui otomatis dan boleh ditinggalkan.')
        ->assertDontSee('akun siswa dibuat');

    // Done, opened again on the running import: the job counts no corrections, so it names no number.
    $import->forceFill(['status' => StudentImportStatus::Done, 'processed_rows' => 300])->save();

    $screen->call('$refresh')
        ->assertSee(ImportSiswa::QUEUED_NOTHING_COUNTED)
        // One clause without a final period, like every other result title ("51 akun siswa dibuat").
        ->assertSee('Impor selesai tanpa siswa baru atau pindah kelas')
        ->assertDontSee('Impor selesai tanpa siswa baru atau pindah kelas.')
        ->assertDontSee('akun siswa dibuat')
        ->assertViewHas('stepNote', 'Selesai');

    // A failed run that wrote nothing it counted names nothing either.
    $import->forceFill(['status' => StudentImportStatus::Failed, 'error' => 'File impor tidak ditemukan. Upload ulang file.'])->save();

    $screen->call('$refresh')
        ->assertSee('Impor gagal')
        ->assertSee('File impor tidak ditemukan. Upload ulang file.')
        ->assertDontSee('Sebelum berhenti')
        ->assertDontSee('akun siswa dibuat');
});

// SV1 review: this test first expected "Tanggal arsip atau No HP 51 siswa diperbarui" at the end, a number taken from
// the preview (queuedCorrections, gone). The job counts no corrections, so the result names none (CLAUDE.md §7).
it('names no predicted number for a queued run of corrections, only what the job counted (SV1 review)', function (): void {
    $rows = [];

    foreach (range(1, ImportStudentsJob::SYNC_MAX_ROWS + 1) as $i) {
        $nis = sprintf('2262%05d', 200 + $i);
        $nisn = sprintf('01%08d', 200 + $i);
        r3iRegistered('Siswa Lama '.r3iLetters($i), $nis, $nisn);
        // Same class, a new No HP: a correction for every row, no account and no class move.
        $rows[] = ['Siswa Lama '.r3iLetters($i), $nis, $nisn, '12-05-2011', 'X-1', sprintf('081234567%03d', $i)];
    }

    $screen = Livewire::actingAs($this->kesiswaan)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', r3iFile($rows))
        ->assertViewHas('kinds', ['new' => 0, 'moved' => 0, 'corrected' => 51])
        ->assertSee('Impor 51 siswa')
        ->call('askImport')
        ->call('import')
        ->assertHasNoErrors()
        ->assertSee('Menunggu giliran.')
        ->assertDontSee('akun siswa dibuat');

    $import = StudentImport::query()->sole();
    $import->forceFill(['status' => StudentImportStatus::Running])->save();

    $screen->call('$refresh')->assertSee('Mulai mengimpor…')->assertDontSee('akun siswa dibuat');

    r3iRunJob($import);

    expect($import->refresh()->status)->toBe(StudentImportStatus::Done)
        ->and($import->created_count)->toBe(0)
        ->and($import->updated_count)->toBe(0);

    $screen->call('$refresh')
        ->assertSee(ImportSiswa::QUEUED_NOTHING_COUNTED)
        ->assertDontSee('51 siswa diperbarui')
        ->assertDontSee('akun siswa dibuat')
        ->assertViewHas('stepNote', 'Selesai');

    expect(Student::query()->whereNotNull('phone')->count())->toBe(51);
});

it('says "Tidak ada data yang berubah" for an import that wrote nothing, not "0 akun siswa dibuat" (SV1 second check)', function (): void {
    r3iRegistered('Rafi Pratama', '226227001', '0101234501');

    Livewire::actingAs($this->kesiswaan)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', r3iFile([['Rafi Pratama', '226227001', '0101234501', '12-05-2011', 'X-1', '']]))
        ->assertViewHas('changeCount', 0)
        ->call('askImport')
        ->call('import')
        ->assertHasNoErrors()
        ->assertSet('imported', ['created' => 0, 'moved' => 0, 'corrected' => 0, 'invalid' => 0])
        ->assertDispatched('sth-toast', message: 'Tidak ada data yang berubah.', tone: 'success')
        ->assertSee('Tidak ada data yang berubah')
        ->assertDontSee('akun siswa dibuat');
});

// SV1 review: the future-tense lines of step 3 ------------------------------------------------------------------------

it('drops "akan berubah" and "sebelum mengimpor" once the file went to a queued import, also when it is done (SV1 review)', function (): void {
    // A registered student in his class with a wrong archive date (a correction), a new student with a cleaned name and
    // SYNC_MAX_ROWS more new students: 52 rows to write, so the import is queued.
    r3iRegistered('Rafi Pratama', '226227001', '0101234501')->forceFill(['archive_at' => '2031-01-01'])->save();
    $rows = [
        ['Rafi Pratama', '226227001', '0101234501', '12-05-2011', 'X-1', ''],
        ['=Nadia Putri Anindya', '226227011', '0101234511', '12-05-2011', 'X-1', ''],
    ];

    foreach (range(1, ImportStudentsJob::SYNC_MAX_ROWS) as $i) {
        $rows[] = ['Siswa Uji '.r3iLetters($i), sprintf('2262%05d', 100 + $i), sprintf('01%08d', 100 + $i), '12-05-2011', 'X-1', ''];
    }

    $screen = Livewire::actingAs($this->kesiswaan)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', r3iFile($rows))
        ->assertViewHas('archiveCorrections', 1)
        ->assertViewHas('sanitizedCount', 1)
        ->assertSee('Tanggal arsip 1 siswa lama akan berubah')
        ->assertSee('periksa datanya sebelum mengimpor')
        ->assertSee('Impor 52 siswa')
        ->call('askImport')
        ->call('import')
        ->assertHasNoErrors()
        ->assertSee('Impor file lain')
        // Handed to the queued import: nothing is "before the import" any more.
        ->assertDontSee('akan berubah')
        ->assertDontSee('sebelum mengimpor');

    $import = StudentImport::query()->sole();
    r3iRunJob($import);

    $screen->call('$refresh')
        ->assertViewHas('stepNote', 'Selesai')
        ->assertSee('51 akun siswa dibuat')
        ->assertDontSee('akan berubah')
        ->assertDontSee('sebelum mengimpor')
        // The rows keep their notes.
        ->assertSee('Tanggal arsip dikoreksi: Jan 2031 → Jan 2030.')
        ->assertSee(ImportStudents::NOTE_SANITIZED);

    // The next file is before its import again.
    $screen->call('startOver')
        ->set('file', r3iFile([['=Dewi Anggraini', '226227005', '0101234505', '12-05-2011', 'X-1', '']]))
        ->assertSee('periksa datanya sebelum mengimpor');
});

it('drops "akan berubah" and "sebelum mengimpor" after a synchronous import too (SV1 review)', function (): void {
    r3iRegistered('Rafi Pratama', '226227001', '0101234501')->forceFill(['archive_at' => '2031-01-01'])->save();

    Livewire::actingAs($this->kesiswaan)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', r3iFile([
            ['Rafi Pratama', '226227001', '0101234501', '12-05-2011', 'X-1', ''],
            ['=Nadia Putri Anindya', '226227011', '0101234511', '12-05-2011', 'X-1', ''],
        ]))
        ->assertSee('Tanggal arsip 1 siswa lama akan berubah')
        ->assertSee('periksa datanya sebelum mengimpor')
        ->call('askImport')
        ->call('import')
        ->assertHasNoErrors()
        ->assertSee('1 akun siswa dibuat, tanggal arsip atau No HP 1 siswa diperbarui')
        ->assertDontSee('akan berubah')
        ->assertDontSee('sebelum mengimpor')
        ->assertSee(ImportStudents::NOTE_SANITIZED);
});
