<?php

declare(strict_types=1);

use App\Actions\Accounts\ImportStudents;
use App\Actions\Accounts\StudentImportReport;
use App\Domain\Files\StoreUpload;
use App\Enums\RoleName;
use App\Enums\StudentImportStatus;
use App\Enums\UserStatus;
use App\Jobs\ImportStudentsJob;
use App\Livewire\Akun\ImportSiswa;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\StudentImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\SimpleExcel\SimpleExcelReader;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

uses(RefreshDatabase::class);

/*
 | SV1 browser check (2026-10-30), Import siswa: (a) the button said "Impor 66 siswa" while the dialog and the result
 | said 65: StudentImportReport::countChanges() counts every row the import writes, also a registered student who stays
 | in the class but gets the archive dates or the No HP of the file, while the dialog and the result named only new
 | accounts and class moves. The button, the tiles, the dialog and the result now name the same three kinds. (b) After a
 | queued import (more than 50 changes) step 3 never said "Selesai". Fictional data only.
 */

beforeEach(function (): void {
    F::boot();
    Queue::fake();
    $this->withoutVite();
    $this->xa = Classroom::factory()->create(['name' => 'X-1', 'level' => 10]);
    Classroom::factory()->create(['name' => 'XI-4', 'level' => 11]);
    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    session(A::passwordConfirmed());
});

/** A registered student in X-1 with the dates of X-1 in 2026/2027 (kelas XII from Jul 2028, archive Jan 2030). */
function ui2ImportRegistered(string $name, string $nis, string $nisn, array $student = []): Student
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
        ...$student,
    ]);
    $created->user->forceFill(['name' => $name, 'status' => UserStatus::Active, 'must_change_password' => false])->save();

    return $created->refresh();
}

/** @param list<list<string>> $rows */
function ui2ImportFile(array $rows): UploadedFile
{
    $lines = ['Nama lengkap,NIS,NISN,Tanggal lahir,Kelas,No HP', ...array_map(fn (array $row): string => implode(',', $row), $rows)];

    return UploadedFile::fake()->createWithContent('data-siswa.csv', implode("\n", $lines)."\n");
}

/** Letters only (names may not hold digits): 1 → A, 27 → AA. */
function ui2ImportLetters(int $n): string
{
    $s = '';

    do {
        $s = chr(65 + $n % 26).$s;
        $n = intdiv($n, 26) - 1;
    } while ($n >= 0);

    return $s;
}

it('counts a correction of a registered student in the button, the tiles, the dialog and the result alike (SV1)', function (): void {
    $rafi = ui2ImportRegistered('Rafi Pratama', '226227001', '0101234501');                                   // moves to XI-4
    $salsa = ui2ImportRegistered('Salsabila Putri', '226227002', '0101234502');                              // gets a No HP
    $bima = ui2ImportRegistered('Bima Saputra', '226227003', '0101234503', ['archive_at' => '2031-01-01']);  // wrong archive date
    ui2ImportRegistered('Citra Lestari', '226227004', '0101234504');                                         // nothing to write

    $screen = Livewire::actingAs($this->kesiswaan)->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', ui2ImportFile([
            ['Rafi Pratama', '226227001', '0101234501', '12-05-2011', 'XI-4', ''],
            ['Salsabila Putri', '226227002', '0101234502', '12-05-2011', 'X-1', '081234567890'],
            ['Bima Saputra', '226227003', '0101234503', '12-05-2011', 'X-1', ''],
            ['Citra Lestari', '226227004', '0101234504', '12-05-2011', 'X-1', ''],
            ['Dewi Anggraini', '226227005', '0101234505', '12-05-2011', 'X-1', ''],
        ]))
        ->assertHasNoErrors()
        ->assertViewHas('changeCount', 4)
        ->assertViewHas('kinds', ['new' => 1, 'moved' => 1, 'corrected' => 2])
        // "Tidak berubah" holds only the row nothing is written for; the corrections have their own tile.
        ->assertViewHas('unchangedCount', 1)
        ->assertSee('Data diperbarui')
        ->assertSee('Impor 4 siswa')
        ->call('askImport')
        ->assertDispatched('sth-modal-open', id: ImportSiswa::IMPORT_DIALOG)
        ->assertSee('Buat 1 akun siswa, pindahkan 1 siswa ke kelas di file, dan perbarui tanggal arsip atau No HP untuk 2 siswa.');

    $screen->call('import')
        ->assertHasNoErrors()
        ->assertSet('imported', ['created' => 1, 'moved' => 1, 'corrected' => 2, 'invalid' => 0])
        ->assertDispatched('sth-toast', message: '1 akun siswa dibuat, 1 siswa pindah kelas, tanggal arsip atau No HP 2 siswa diperbarui.', tone: 'success')
        ->assertSee('1 akun siswa dibuat, 1 siswa pindah kelas, tanggal arsip atau No HP 2 siswa diperbarui')
        ->assertViewHas('stepNote', 'Selesai');

    // What the sentences name is what was written.
    expect($rafi->refresh()->classroom->name)->toBe('XI-4')
        ->and($salsa->refresh()->phone)->toBe('081234567890')
        ->and($bima->refresh()->archive_at->format('Y-m-d'))->toBe('2030-01-01')
        ->and(Student::query()->where('nis', '226227005')->exists())->toBeTrue();
});

it('names a file of corrections only as corrections, not as a number of students to import (SV1)', function (): void {
    $salsa = ui2ImportRegistered('Salsabila Putri', '226227002', '0101234502');

    Livewire::actingAs($this->kesiswaan)->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', ui2ImportFile([['Salsabila Putri', '226227002', '0101234502', '12-05-2011', 'X-1', '081234567890']]))
        ->assertSee('Impor 1 siswa')
        ->call('askImport')
        ->assertSee('Perbarui tanggal arsip atau No HP untuk 1 siswa.')
        ->assertDontSee('perbaikan data')
        ->call('import')
        ->assertDispatched('sth-toast', message: 'Tanggal arsip atau No HP 1 siswa diperbarui.', tone: 'success')
        ->assertSee('Tanggal arsip atau No HP 1 siswa diperbarui')
        ->assertDontSee('0 akun siswa dibuat');

    expect($salsa->refresh()->phone)->toBe('081234567890');
});

it('counts the kinds from the rows the import writes', function (): void {
    $rows = [
        ['errors' => [], 'action' => StudentImportReport::NEW, 'changes' => true],
        ['errors' => [], 'action' => StudentImportReport::MOVED, 'changes' => true],
        ['errors' => [], 'action' => StudentImportReport::UNCHANGED, 'changes' => true],
        ['errors' => [], 'action' => StudentImportReport::UNCHANGED, 'changes' => false],
        ['errors' => [], 'action' => StudentImportReport::SKIPPED, 'changes' => false],
        ['errors' => ['Kelas tidak ditemukan.'], 'action' => StudentImportReport::REJECTED, 'changes' => false],
    ];

    $kinds = StudentImportReport::changeKinds($rows);

    expect($kinds)->toBe(['new' => 1, 'moved' => 1, 'corrected' => 1])
        ->and(array_sum($kinds))->toBe(StudentImportReport::countChanges($rows))
        // One helper names every row: the unchanged row that is still written is a correction, the other stays unchanged.
        ->and(array_map(StudentImportReport::kindOf(...), $rows))->toBe([
            StudentImportReport::NEW,
            StudentImportReport::MOVED,
            StudentImportReport::CORRECTED,
            StudentImportReport::UNCHANGED,
            StudentImportReport::SKIPPED,
            StudentImportReport::REJECTED,
        ])
        // A row without an action reads as summarize() reads it.
        ->and(StudentImportReport::kindOf(['errors' => []]))->toBe(StudentImportReport::NEW)
        ->and(StudentImportReport::kindOf(['errors' => ['Kelas tidak ditemukan.']]))->toBe(StudentImportReport::REJECTED);
});

it('labels a corrected row "Data diperbarui" in the preview and in its download, like its tile (SV1 follow-up)', function (): void {
    ui2ImportRegistered('Rafi Pratama', '226227001', '0101234501');                                   // moves to XI-4
    ui2ImportRegistered('Salsabila Putri', '226227002', '0101234502');                              // gets a No HP
    ui2ImportRegistered('Bima Saputra', '226227003', '0101234503', ['archive_at' => '2031-01-01']);  // wrong archive date
    ui2ImportRegistered('Citra Lestari', '226227004', '0101234504');                                // a note, nothing written

    $screen = Livewire::actingAs($this->kesiswaan)->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', ui2ImportFile([
            ['Rafi Pratama', '226227001', '0101234501', '12-05-2011', 'XI-4', ''],
            ['Salsabila Putri', '226227002', '0101234502', '12-05-2011', 'X-1', '081234567890'],
            ['Bima Saputra', '226227003', '0101234503', '12-05-2011', 'X-1', ''],
            ['Citra Lestary', '226227004', '0101234504', '12-05-2011', 'X-1', ''],
            ['Dewi Anggraini', '226227005', '0101234505', '12-05-2011', 'X-1', ''],
        ]))
        ->assertHasNoErrors()
        ->assertViewHas('kinds', ['new' => 1, 'moved' => 1, 'corrected' => 2])
        ->assertViewHas('unchangedCount', 1);

    // Every row carries the name of its tile: the two corrections are no longer "Tidak berubah".
    expect(collect($screen->viewData('list'))->mapWithKeys(fn (array $r): array => [$r['name'] => [$r['status'], $r['tone']]])->all())->toBe([
        'Rafi Pratama' => ['Pindah kelas', 'blue'],
        'Salsabila Putri' => ['Data diperbarui', 'blue'],
        'Bima Saputra' => ['Data diperbarui', 'blue'],
        'Citra Lestary' => ['Tidak berubah', 'gray'],
        'Dewi Anggraini' => ['Baru', 'green'],
    ]);

    // The chips on the screen: blue "Data diperbarui" next to the orange note of each correction; the gray "Tidak
    // berubah" only on the row nothing is written for, its name note notwithstanding. (Livewire's block markers out.)
    $html = (string) preg_replace('/<!--\[if (?:END)?BLOCK\]><!\[endif\]-->/', '', $screen->html());

    expect(preg_match_all('/class="chip c-blue chip-sm">\s*Data diperbarui\s*<\/span>/', $html))->toBe(2)
        ->and(preg_match_all('/class="chip c-gray chip-sm">\s*Tidak berubah\s*<\/span>/', $html))->toBe(1)
        ->and($html)->toContain('No HP diperbarui.')
        ->and($html)->toContain('Tanggal arsip dikoreksi: Jan 2031 → Jan 2030.')
        ->and($html)->toContain('Nama berbeda dari data lama, tidak diubah.');

    // "Download baris yang perlu dicek" names the same kinds (the new row has no note and is not in it).
    $base = (string) tempnam(sys_get_temp_dir(), 'sth');
    $path = $base.'.xlsx';
    file_put_contents($path, base64_decode((string) $screen->call('downloadInvalid')->effects['download']['content']));
    $status = SimpleExcelReader::create($path)->getRows()->mapWithKeys(fn (array $r): array => [$r['Nama lengkap'] => $r['Status']])->all();
    @unlink($path);
    @unlink($base);

    expect($status)->toBe([
        'Rafi Pratama' => 'Pindah kelas',
        'Salsabila Putri' => 'Data diperbarui',
        'Bima Saputra' => 'Data diperbarui',
        'Citra Lestary' => 'Tidak berubah',
    ]);
});

// SV1 review: this test first expected "…, tanggal arsip atau No HP 1 siswa diperbarui" in the result, a number taken
// from the preview (queuedCorrections, gone): the job counts new accounts and class moves only, so only those are named.
it('says "Selesai" at step 3 once a queued import is done, and names only what the job counted (SV1)', function (): void {
    ui2ImportRegistered('Rafi Pratama', '226227001', '0101234501');
    ui2ImportRegistered('Salsabila Putri', '226227002', '0101234502');

    $rows = [
        ['Rafi Pratama', '226227001', '0101234501', '12-05-2011', 'XI-4', ''],
        ['Salsabila Putri', '226227002', '0101234502', '12-05-2011', 'X-1', '081234567890'],
    ];

    foreach (range(1, ImportStudentsJob::SYNC_MAX_ROWS) as $i) {
        $rows[] = ['Siswa Uji '.ui2ImportLetters($i), sprintf('2262%05d', 100 + $i), sprintf('01%08d', 100 + $i), '12-05-2011', 'X-1', ''];
    }

    // 50 new + 1 class move + 1 correction = 52 rows to write: more than SYNC_MAX_ROWS, so the import is queued.
    $screen = Livewire::actingAs($this->kesiswaan)->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', ui2ImportFile($rows))
        ->assertSee('Impor 52 siswa')
        ->call('askImport')
        ->call('import')
        ->assertHasNoErrors()
        ->assertViewHas('stepNote', StudentImportStatus::Queued->label())
        // Nothing is claimed before the job wrote it.
        ->assertDontSee('tanggal arsip atau No HP 1 siswa diperbarui');

    $import = StudentImport::query()->sole();
    (new ImportStudentsJob($import->getKey()))->handle(app(ImportStudents::class), app(StoreUpload::class));

    expect($import->refresh()->status)->toBe(StudentImportStatus::Done);

    $screen->call('$refresh')
        ->assertViewHas('stepNote', 'Selesai')
        ->assertSeeHtml('<span class="ellip text-xs text-muted">Selesai</span>')
        ->assertSee('50 akun siswa dibuat, 1 siswa pindah kelas')
        ->assertDontSee('siswa diperbarui')
        ->assertDontSeeHtml('wire:poll.2s');

    // The correction was written all the same.
    expect(Student::query()->where('nis', '226227002')->sole()->phone)->toBe('081234567890');

    // "Impor file lain" forgets the finished import.
    $screen->call('startOver')->assertSet('importId', null)->assertSet('queuedPreview', null)->assertViewHas('stepNote', 'Belum ada file');
});

it('names no correction for a queued import it did not start, which it cannot count (SV1)', function (): void {
    // The screen opened again on an import of the same person (mount() picks up a running one): no preview here.
    $import = new StudentImport([
        'user_id' => $this->kesiswaan->getKey(),
        'extension' => 'csv',
        'school_year' => '2026/2027',
        'file_name' => 'data-siswa.csv',
        'file_sha256' => str_repeat('b', 64),
    ]);
    $import->forceFill(['status' => StudentImportStatus::Running, 'total_rows' => 120, 'processed_rows' => 100, 'created_count' => 0, 'updated_count' => 12])->save();

    $screen = Livewire::actingAs($this->kesiswaan)->test(ImportSiswa::class)
        ->assertViewHas('stepNote', StudentImportStatus::Running->label())
        // A kind with 0 is left out: no "0 akun siswa dibuat" in front of the class moves.
        ->assertSee('100 dari 120 baris diproses · 12 siswa pindah kelas.');

    $import->forceFill(['status' => StudentImportStatus::Done, 'processed_rows' => 120, 'created_count' => 3, 'updated_count' => 15])->save();

    $screen->call('$refresh')
        ->assertViewHas('stepNote', 'Selesai')
        ->assertSee('3 akun siswa dibuat, 15 siswa pindah kelas')
        ->assertDontSee('siswa diperbarui');
});
