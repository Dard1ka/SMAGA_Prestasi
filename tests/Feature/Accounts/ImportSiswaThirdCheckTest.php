<?php

declare(strict_types=1);

use App\Actions\Accounts\ImportStudents;
use App\Domain\Files\StoreUpload;
use App\Enums\RoleName;
use App\Enums\StudentImportStatus;
use App\Jobs\ImportStudentsJob;
use App\Livewire\Akun\ImportSiswa;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\StudentImport;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

uses(RefreshDatabase::class);

/*
 | SV1 third browser check (Playwright on copies of the school's server, 2026-11-01), Import siswa. (1) Two staff
 | importing the same new students at the same moment: the second one got HTTP 500 (Livewire's error modal) from the
 | unique index students_nis_unique, synchronously and next to a queued import. (2) A finished queued import's green
 | banner ("150 akun siswa dibuat" / "Semua baris sudah sesuai dengan file.") stood under the header of a DIFFERENT file
 | that was not imported yet. (3) At 1024 px "Download baris yang perlu dicek" stuck out of the step-3 card. The other
 | import is simulated in the same test process: it writes its student when the import's transaction starts (the check
 | of the file is then stale) or right before the import inserts that row (the check inside the transaction is stale
 | too). Fictional data only.
 */

beforeEach(function (): void {
    F::boot();
    Queue::fake();
    $this->withoutVite();
    Classroom::factory()->create(['name' => 'X-1', 'level' => 10]);
    Classroom::factory()->create(['name' => 'XI-4', 'level' => 11]);
    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    session(A::passwordConfirmed());
});

/** @param list<list<string>> $rows */
function v3iFile(array $rows, string $name = 'data-siswa.csv'): UploadedFile
{
    $lines = ['Nama lengkap,NIS,NISN,Tanggal lahir,Kelas,No HP', ...array_map(fn (array $row): string => implode(',', $row), $rows)];

    return UploadedFile::fake()->createWithContent($name, implode("\n", $lines)."\n");
}

/** Letters only (names may not hold digits): 0 → A, 26 → AA. */
function v3iLetters(int $n): string
{
    $s = '';

    do {
        $s = chr(65 + $n % 26).$s;
        $n = intdiv($n, 26) - 1;
    } while ($n >= 0);

    return $s;
}

/** Three new students in X-1; the second one is the student the other import registers too. */
function v3iThree(): array
{
    return [
        ['Nadia Putri Anindya', '226227001', '0101234501', '12-05-2011', 'X-1', ''],
        ['Rafi Pratama', '226227002', '0101234502', '12-05-2011', 'X-1', ''],
        ['Dewi Anggraini', '226227003', '0101234503', '12-05-2011', 'X-1', ''],
    ];
}

/** The student the other import wrote, in XI-4 (so a write of this import over it would show). */
function v3iOther(string $nis, string $nisn): Student
{
    return Student::factory()->create([
        'nis' => $nis,
        'nisn' => $nisn,
        'classroom_id' => Classroom::query()->where('name', 'XI-4')->value('id'),
        'import_school_year' => '2025/2026',
    ]);
}

/** The other import commits its student when the next transaction of this test starts (the import's own write). */
function v3iOtherAtTransactionStart(string $nis, string $nisn): void
{
    $done = false;

    DB::connection()->beforeStartingTransaction(function () use (&$done, $nis, $nisn): void {
        if ($done) {
            return;
        }

        $done = true;
        v3iOther($nis, $nisn);
    });
}

/**
 * The other import commits its student after this import checked the row's numbers again inside its transaction (the
 * NISN check is the last query of that check) and before the row's own savepoint starts: the check found nothing, the
 * insert meets the unique index.
 */
function v3iOtherAfterCheckOf(string $nis, string $nisn): void
{
    $armed = false;
    $done = false;

    DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$armed, $nisn): void {
        if (str_contains($query, '"nisn" = ?') && in_array($nisn, $bindings, true)) {
            $armed = true;
        }
    });

    DB::connection()->beforeStartingTransaction(function () use (&$armed, &$done, $nis, $nisn): void {
        if (! $armed || $done) {
            return;
        }

        $done = true;
        v3iOther($nis, $nisn);
    });
}

/** The import screen with $rows read and confirmed in the dialog, nothing clicked yet. */
function v3iAsked(User $actor, array $rows, string $name = 'data-siswa.csv'): Testable
{
    return Livewire::actingAs($actor)->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', v3iFile($rows, $name))
        ->call('askImport')
        ->assertDispatched('sth-modal-open', id: ImportSiswa::IMPORT_DIALOG);
}

/** SYNC_MAX_ROWS + 1 new students: the import is queued. */
function v3iBigRows(): array
{
    return array_map(
        fn (int $i): array => ['Siswa Uji '.v3iLetters($i), sprintf('2262%05d', 100 + $i), sprintf('01%08d', 100 + $i), '12-05-2011', 'X-1', ''],
        range(1, ImportStudentsJob::SYNC_MAX_ROWS + 1),
    );
}

function v3iRunJob(StudentImport $import): void
{
    (new ImportStudentsJob($import->getKey()))->handle(app(ImportStudents::class), app(StoreUpload::class));
}

/** A queued import of $actor started in another tab, in the given state (no file needed). */
function v3iOtherTabJob(User $actor, array $state, string $fileName = 'kelas-x.csv'): StudentImport
{
    $import = new StudentImport([
        'user_id' => $actor->getKey(),
        'extension' => 'csv',
        'school_year' => '2026/2027',
        'file_name' => $fileName,
        'file_sha256' => str_repeat('e', 64),
    ]);
    $import->forceFill($state)->save();

    return $import;
}

/** The class attribute of the `<button>` whose wire:click is $method, or null. */
function v3iButtonClass(Testable $screen, string $method): ?string
{
    preg_match_all('/<button\b[^>]*>/', $screen->html(), $tags);

    foreach ($tags[0] as $tag) {
        if (str_contains($tag, 'wire:click="'.$method.'"') && preg_match('/\sclass="([^"]*)"/', $tag, $class) === 1) {
            return $class[1];
        }
    }

    return null;
}

// (1) Two imports of the same new students at once -----------------------------------------------------------------

it('leaves a new row to the import that registered it after the check, and writes the rest (SV1 third check)', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'v3i');
    file_put_contents($path, v3iFile(v3iThree())->getContent());
    v3iOtherAtTransactionStart('226227002', '0101234502');

    $report = app(ImportStudents::class)->handle($this->kesiswaan, $path, 'csv', '2026/2027', dryRun: false);
    @unlink($path);

    // Counted only what was written: the other import's student is not created twice, not moved, not counted.
    $other = Student::query()->where('nis', '226227002')->sole();

    expect($report->created)->toBe(2)
        ->and($report->moved)->toBe(0)
        ->and(Student::query()->whereIn('nis', ['226227001', '226227003'])->count())->toBe(2)
        ->and($other->classroom->name)->toBe('XI-4')
        ->and($other->import_school_year)->toBe('2025/2026')
        ->and(User::query()->where('name', 'Rafi Pratama')->exists())->toBeFalse();

    $audit = Activity::query()->where('event', 'student.import')->sole();
    expect($audit->properties['created'])->toBe(2);
});

it('rolls back only the row whose insert meets the other import, with its account (SV1 third check)', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'v3i');
    file_put_contents($path, v3iFile(v3iThree())->getContent());
    // The check inside the transaction found nothing; the other import's row arrives before this insert.
    v3iOtherAfterCheckOf('226227002', '0101234502');
    $users = User::query()->count();
    $inserts = 0;
    DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$inserts): void {
        if (str_starts_with($query, 'insert into "students"') && in_array('0101234502', $bindings, true)) {
            $inserts++;
        }
    });

    $report = app(ImportStudents::class)->handle($this->kesiswaan, $path, 'csv', '2026/2027', dryRun: false);
    @unlink($path);

    // The other import's row, then this import's insert, which the unique index refused (no exception left the import).
    expect($inserts)->toBe(2)
        ->and($report->created)->toBe(2)
        ->and(Student::query()->where('nis', '226227002')->sole()->classroom->name)->toBe('XI-4')
        // The account this import had already inserted for that row went with its savepoint: no student-less user.
        ->and(User::query()->where('name', 'Rafi Pratama')->exists())->toBeFalse()
        ->and(User::query()->count())->toBe($users + 3)
        ->and(User::query()->whereDoesntHave('student')->role(RoleName::Siswa->value)->exists())->toBeFalse()
        ->and(Student::query()->whereIn('nis', ['226227001', '226227003'])->count())->toBe(2);
});

it('also leaves a row out whose NIS alone was registered meanwhile (SV1 third check)', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'v3i');
    file_put_contents($path, v3iFile(v3iThree())->getContent());
    // Another student with this NIS and another NISN: the row is no longer new, and it is never written over.
    v3iOtherAtTransactionStart('226227002', '0109999999');

    $report = app(ImportStudents::class)->handle($this->kesiswaan, $path, 'csv', '2026/2027', dryRun: false);
    @unlink($path);

    expect($report->created)->toBe(2)
        ->and(Student::query()->where('nisn', '0101234502')->exists())->toBeFalse()
        ->and(Student::query()->where('nis', '226227002')->sole()->nisn)->toBe('0109999999');
});

it('names the rows the other import registered under the result instead of a 500 (SV1 third check)', function (): void {
    $screen = v3iAsked($this->kesiswaan, v3iThree());
    v3iOtherAtTransactionStart('226227002', '0101234502');

    $screen->call('import')
        ->assertHasNoErrors()
        ->assertSet('imported', ['created' => 2, 'moved' => 0, 'corrected' => 0, 'invalid' => 0, 'taken' => 1])
        ->assertDispatched('sth-toast', message: '2 akun siswa dibuat.', tone: 'success')
        ->assertSee('2 akun siswa dibuat')
        ->assertSee('1 siswa tidak dibuat karena NIS atau NISN-nya baru saja didaftarkan oleh impor lain.')
        ->assertSee('Siswa baru bisa masuk dengan NISN dan kata sandi awal dari sekolah.')
        ->assertSee('Impor file lain');

    expect(Student::query()->where('nis', '226227002')->count())->toBe(1);

    // SV1 re-check: the rows under the result are the rows the commit checked. The two it created read "Baru"; the
    // one the other import registered is "Dilewati" with its note, never "Baru" (it was not created from this file).
    $list = collect($screen->viewData('list'));
    $taken = $list->firstWhere('nis', '226227002');

    expect($taken['status'])->toBe('Dilewati')
        ->and($taken['notes'])->toContain(ImportStudents::NOTE_TAKEN)
        ->and($list->where('status', 'Baru')->count())->toBe(2)
        // SV1 last check: a taken row is skipped, but it is no alumni account.
        ->and($screen->viewData('alumniCount'))->toBe(0);
    $screen->assertSee(ImportStudents::NOTE_TAKEN)
        ->assertDontSee('akun alumni (sudah diarsipkan)');
});

it('claims no matching classes when the other import registered every row (SV1 third check)', function (): void {
    $screen = v3iAsked($this->kesiswaan, [['Rafi Pratama', '226227002', '0101234502', '12-05-2011', 'X-1', '']]);
    v3iOtherAtTransactionStart('226227002', '0101234502');

    $screen->call('import')
        ->assertHasNoErrors()
        ->assertSet('imported', ['created' => 0, 'moved' => 0, 'corrected' => 0, 'invalid' => 0, 'taken' => 1])
        ->assertSee('Tidak ada data yang berubah')
        ->assertSee('1 siswa tidak dibuat karena NIS atau NISN-nya baru saja didaftarkan oleh impor lain.')
        ->assertDontSee('Data kelas sudah sesuai dengan file.')
        ->assertDontSee('akun siswa dibuat');
});

it('keeps the result sentences of an import nobody raced, without the sentence of the other import (SV1 third check)', function (): void {
    v3iAsked($this->kesiswaan, v3iThree())
        ->call('import')
        ->assertSet('imported', ['created' => 3, 'moved' => 0, 'corrected' => 0, 'invalid' => 0])
        ->assertSee('3 akun siswa dibuat')
        ->assertSee('Siswa baru bisa masuk dengan NISN dan kata sandi awal dari sekolah.')
        ->assertDontSee('impor lain');
});

it('reads the file again when the write still meets a unique index, never Livewire\'s error page (SV1 third check)', function (): void {
    $screen = v3iAsked($this->kesiswaan, v3iThree());
    $token = $screen->get('previewToken');
    $thrown = false;

    // A unique index the import could not foresee: its write stops before anything is stored.
    DB::connection()->beforeStartingTransaction(function () use (&$thrown): void {
        if ($thrown) {
            return;
        }

        $thrown = true;

        throw new UniqueConstraintViolationException('sqlite', 'insert into "students" ("nis") values (?)', ['226227002'], new PDOException('UNIQUE constraint failed: students.nis'));
    });

    $screen->call('import')
        ->assertHasNoErrors()
        ->assertDispatched('sth-modal-close', id: ImportSiswa::IMPORT_DIALOG)
        ->assertDispatched('sth-toast', message: ImportSiswa::IMPORT_COLLIDED, tone: 'warning')
        ->assertSet('imported', null)
        ->assertSet('importAsked', null)
        // A new preview of the same file, counted as the database is now; it can be imported again.
        ->assertViewHas('stepNote', '3 baris terbaca')
        ->assertSee('Impor 3 siswa')
        ->assertDontSee('Impor file lain');

    expect($thrown)->toBeTrue()
        ->and($screen->get('previewToken'))->not->toBeNull()->not->toBe($token)
        ->and(Student::query()->whereIn('nis', ['226227001', '226227002', '226227003'])->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'student.import')->exists())->toBeFalse();

    // Forbidden: the question went with the old preview, so a second confirm writes nothing.
    $screen->call('import')->assertSet('imported', null);
    expect(Student::query()->whereIn('nis', ['226227001', '226227002', '226227003'])->exists())->toBeFalse();
});

it('finishes a queued slice whose student another import registered meanwhile (SV1 third check)', function (): void {
    $rows = v3iBigRows();
    $screen = v3iAsked($this->kesiswaan, $rows)->call('import')->assertHasNoErrors();
    $import = StudentImport::query()->sole();
    v3iOtherAtTransactionStart($rows[4][1], $rows[4][2]);

    v3iRunJob($import);

    expect($import->refresh()->status)->toBe(StudentImportStatus::Done)
        ->and($import->created_count)->toBe(ImportStudentsJob::SYNC_MAX_ROWS)
        ->and(Student::query()->where('nis', $rows[4][1])->sole()->classroom->name)->toBe('XI-4')
        ->and(Student::query()->where('nis', 'like', '2262%')->count())->toBe(ImportStudentsJob::SYNC_MAX_ROWS + 1);

    // The banner claims no match with the file: one row of it stays as the other import wrote it (XI-4, not X-1).
    $screen->call('$refresh')
        ->assertSee(ImportStudentsJob::SYNC_MAX_ROWS.' akun siswa dibuat')
        ->assertDontSee('Semua baris sudah sesuai dengan file.')
        ->assertDontSee('Baris lain sudah sesuai.');
});

// (2) A finished queued import above another file -------------------------------------------------------------------

it('names its own file above the file that waited for it, and says that file is not imported yet (SV1 third check)', function (): void {
    $screen = v3iAsked($this->kesiswaan, v3iBigRows())->call('import')->assertHasNoErrors();
    $import = StudentImport::query()->sole();

    $screen->set('file', v3iFile([['Dewi Anggraini', '226227005', '0101234505', '12-05-2011', 'X-1', '']], 'siswa-baru.csv'))
        ->assertSee('File ini bisa diimpor setelah impor yang sedang berjalan selesai.');

    v3iRunJob($import);
    $title = 'Impor '.$import->refresh()->file_name.' selesai: 51 akun siswa dibuat';

    expect($import->file_name)->toContain('data-siswa');

    $screen->call('$refresh')
        ->assertSee($title)
        ->assertSee('File siswa-baru.csv di bawah ini belum diimpor.')
        ->assertDontSee('Semua baris sudah sesuai dengan file.')
        ->assertSee('Impor 1 siswa');

    // Read again on the first click (SV1 review): still the same file on screen, still not imported.
    $screen->call('askImport')
        ->assertDispatched('sth-toast', message: ImportSiswa::PREVIEW_RECHECKED, tone: 'warning')
        ->assertSee($title)
        ->assertSee('File siswa-baru.csv di bawah ini belum diimpor.');

    // Imported: the finished import gives way to the result of this file.
    $screen->call('askImport')
        ->call('import')
        ->assertHasNoErrors()
        ->assertSee('1 akun siswa dibuat')
        ->assertDontSee($title)
        ->assertDontSee('belum diimpor');
});

it('names the file of an import taken over from another tab, its rejected rows and the file on screen (SV1 third check)', function (): void {
    $screen = Livewire::actingAs($this->kesiswaan)->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', v3iFile([['Dewi Anggraini', '226227005', '0101234505', '12-05-2011', 'X-1', '']], 'siswa-baru.csv'));
    $other = v3iOtherTabJob($this->kesiswaan, ['status' => StudentImportStatus::Running, 'total_rows' => 152, 'processed_rows' => 100]);

    $screen->call('askImport')
        ->assertSee(ImportSiswa::IMPORT_RUNNING)
        ->assertSet('importId', $other->getKey());

    $other->forceFill(['status' => StudentImportStatus::Done, 'processed_rows' => 152, 'created_count' => 150, 'invalid_rows' => 2])->save();

    $screen->call('$refresh')
        ->assertSee('Impor kelas-x.csv selesai: 150 akun siswa dibuat')
        ->assertSee('2 baris file itu ditolak dan tidak diimpor.')
        ->assertSee('File siswa-baru.csv di bawah ini belum diimpor.')
        ->assertDontSee('Baris lain sudah sesuai.')
        ->assertDontSee('Semua baris sudah sesuai dengan file.')
        ->assertViewHas('stepNote', '1 baris terbaca');

    // Nothing it counted: the same clause as QUEUED_NOTHING_COUNTED, with its file.
    $other->forceFill(['created_count' => 0, 'invalid_rows' => 0])->save();

    $screen->call('$refresh')
        ->assertSee('Impor kelas-x.csv selesai tanpa siswa baru atau pindah kelas')
        ->assertDontSee(ImportSiswa::QUEUED_NOTHING_COUNTED)
        ->assertSee('File siswa-baru.csv di bawah ini belum diimpor.');

    // Failed: the error title names its file too.
    $other->forceFill(['status' => StudentImportStatus::Failed, 'error' => 'File impor tidak ditemukan. Upload ulang file.'])->save();

    $screen->call('$refresh')
        ->assertSee('Impor kelas-x.csv gagal')
        ->assertSee('File impor tidak ditemukan. Upload ulang file.')
        ->assertSee('File siswa-baru.csv di bawah ini belum diimpor.');
});

it('keeps the plain banner over the file the import started from (SV1 third check)', function (): void {
    $screen = v3iAsked($this->kesiswaan, v3iBigRows())->call('import')->assertHasNoErrors();
    v3iRunJob(StudentImport::query()->sole());

    $screen->call('$refresh')
        ->assertSee('51 akun siswa dibuat')
        // The queued result names no "all rows match" claim (the job does not count rows another import took), only
        // the login hint for the new students (review of the SV1 third check).
        ->assertSee(ImportSiswa::NEW_STUDENTS_LOGIN)
        ->assertDontSee('Semua baris sudah sesuai dengan file.')
        ->assertDontSee('selesai: 51 akun siswa dibuat')
        ->assertDontSee('belum diimpor')
        ->assertSee('Impor file lain');

    // Opened again with no file on screen: the plain banner as before.
    $failed = v3iOtherTabJob($this->kesiswaan, ['status' => StudentImportStatus::Running]);
    $alone = Livewire::actingAs($this->kesiswaan)->test(ImportSiswa::class)->assertSet('importId', $failed->getKey());
    $failed->forceFill(['status' => StudentImportStatus::Failed, 'error' => 'File impor tidak ditemukan. Upload ulang file.'])->save();

    $alone->call('$refresh')
        ->assertSee('Impor gagal')
        ->assertDontSee('Impor kelas-x.csv gagal')
        ->assertDontSee('belum diimpor');
});

// (3) "Download baris yang perlu dicek" in a narrow step-3 card ----------------------------------------------------

it('lets "Download baris yang perlu dicek" wrap inside the step-3 card (SV1 third check)', function (): void {
    $screen = Livewire::actingAs($this->kesiswaan)->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', v3iFile([['Nadia Putri Anindya', '22', '0101234501', '12-05-2011', 'X-1', '']]))
        ->assertSee('Download baris yang perlu dicek');

    $class = (string) v3iButtonClass($screen, 'downloadInvalid');
    $classes = explode(' ', $class);

    // .btn keeps a 44 px line with white-space: nowrap; these utilities (a later layer) let the label wrap and the
    // button grow from 44 px, so it shrinks with the flex row instead of leaving the ~246 px card at 1024 px.
    expect($classes)->toContain('btn', 'btn-quiet', 'whitespace-normal', 'h-auto', 'min-h-11')
        ->and($classes)->not->toContain('whitespace-nowrap');

    // The other buttons of the row keep the one-line .btn (short labels).
    expect(explode(' ', (string) v3iButtonClass($screen, 'startOver')))->not->toContain('whitespace-normal');
});
