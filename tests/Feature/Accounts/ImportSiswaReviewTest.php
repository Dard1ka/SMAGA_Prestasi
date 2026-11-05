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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

uses(RefreshDatabase::class);

/*
 | SV1 review of the round-3 fixes (2026-11-01), Import siswa. (1) A file read while a queued import of the same person
 | ran kept that preview after the import was done, so the tiles, "Impor N siswa", the dialog sentence and the choice
 | between the synchronous and the queued way counted the database before the import wrote its rows. (3) Step 2 showed
 | no password button while an import was on screen, so a file chosen to wait for a long import usually ended on the
 | password page and was lost. (5) An import started in another tab was refused with nothing on screen to wait on.
 | Items (2) and (4) are in ImportSiswaRecheckTest and ImportSiswaCountsTest. Last review fixes at the end: the refusal
 | IMPORT_RUNNING does not outlive the import it waited for, an import that ends during the dry run still stales the
 | preview, and the toast of a file read again also holds for a file with nothing left to import. Fictional data only.
 */

beforeEach(function (): void {
    F::boot();
    Queue::fake();
    $this->withoutVite();
    Classroom::factory()->create(['name' => 'X-1', 'level' => 10]);
    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    session(A::passwordConfirmed());
});

/** @param list<list<string>> $rows */
function sv1rFile(array $rows): UploadedFile
{
    $lines = ['Nama lengkap,NIS,NISN,Tanggal lahir,Kelas,No HP', ...array_map(fn (array $row): string => implode(',', $row), $rows)];

    return UploadedFile::fake()->createWithContent('data-siswa.csv', implode("\n", $lines)."\n");
}

/** Letters only (names may not hold digits): 1 → B, 26 → AA. */
function sv1rLetters(int $n): string
{
    $s = '';

    do {
        $s = chr(65 + $n % 26).$s;
        $n = intdiv($n, 26) - 1;
    } while ($n >= 0);

    return $s;
}

/** Row $i of the big file: "Siswa Uji <letters>", NIS 2262001xx, NISN 01000001xx, X-1. */
function sv1rRow(int $i): array
{
    return ['Siswa Uji '.sv1rLetters($i), sprintf('2262%05d', 100 + $i), sprintf('01%08d', 100 + $i), '12-05-2011', 'X-1', ''];
}

/** The import screen after "Impor 51 siswa" queued its import (nothing ran yet: Queue::fake()). */
function sv1rQueued(User $actor): Testable
{
    return Livewire::actingAs($actor)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', sv1rFile(array_map(sv1rRow(...), range(1, ImportStudentsJob::SYNC_MAX_ROWS + 1))))
        ->call('askImport')
        ->call('import')
        ->assertHasNoErrors()
        ->assertSee('Impor file lain');
}

/** A queued import of $actor in the given state, as ImportStudentsJob leaves it between slices (no file needed). */
function sv1rJob(User $actor, array $state): StudentImport
{
    $import = new StudentImport([
        'user_id' => $actor->getKey(),
        'extension' => 'csv',
        'school_year' => '2026/2027',
        'file_name' => 'data-siswa.csv',
        'file_sha256' => str_repeat('d', 64),
    ]);
    $import->forceFill($state)->save();

    return $import;
}

/** The "Impor N siswa" button under the preview carries `disabled`. */
function sv1rImportDisabled(Testable $screen): bool
{
    return preg_match('/<button[^>]*wire:click="askImport"[^>]*\sdisabled[\s>]/', $screen->html()) === 1;
}

function sv1rWaiting(): string
{
    return 'File ini bisa diimpor setelah impor yang sedang berjalan selesai.';
}

function sv1rHint(): string
{
    return 'Konfirmasi kata sandi sudah lebih dari 15 menit. Sebelum impor kata sandi diminta lagi, sesudahnya upload file ini lagi.';
}

// (1) A preview read while an import ran ---------------------------------------------------------------------------

it('reads a file again that was read while a queued import ran, before the dialog asks anything (SV1 review)', function (): void {
    $screen = sv1rQueued($this->kesiswaan);
    $import = StudentImport::query()->sole();

    // A file that waits: one row of the queued file and one new student. While the import waits, both are "Baru".
    $screen->set('file', sv1rFile([
        sv1rRow(1),
        ['Dewi Anggraini', '226227005', '0101234505', '12-05-2011', 'X-1', ''],
    ]))
        ->assertHasNoErrors()
        ->assertSet('previewWhileImporting', true)
        ->assertViewHas('kinds', ['new' => 2, 'moved' => 0, 'corrected' => 0])
        ->assertSee('Impor 2 siswa')
        ->assertSee(sv1rWaiting());

    (new ImportStudentsJob($import->getKey()))->handle(app(ImportStudents::class), app(StoreUpload::class));
    expect($import->refresh()->status)->toBe(StudentImportStatus::Done);

    // The first click after the import only reads the file again: the shared row is no longer new, nothing is asked,
    // nothing is written, and the warning says why the numbers changed.
    $screen->call('$refresh')
        ->call('askImport')
        ->assertHasNoErrors()
        ->assertNoRedirect()
        ->assertNotDispatched('sth-modal-open')
        ->assertDispatched('sth-toast', message: ImportSiswa::PREVIEW_RECHECKED, tone: 'warning')
        ->assertSet('previewWhileImporting', false)
        ->assertViewHas('kinds', ['new' => 1, 'moved' => 0, 'corrected' => 0])
        ->assertViewHas('unchangedCount', 1)
        ->assertViewHas('changeCount', 1)
        ->assertSee('Impor 1 siswa')
        ->assertDontSee('Impor 2 siswa')
        ->assertDontSee('Buat 2 akun siswa.')
        // The finished import keeps its result above the file until the next import.
        ->assertSee('51 akun siswa dibuat');

    expect(Student::query()->where('nis', '226227005')->exists())->toBeFalse()
        ->and(sv1rImportDisabled($screen))->toBeFalse();

    // The second click asks about the counts of now, and the result matches the question.
    $screen->call('askImport')
        ->assertDispatched('sth-modal-open', id: ImportSiswa::IMPORT_DIALOG)
        ->assertSee('Buat 1 akun siswa.')
        ->call('import')
        ->assertHasNoErrors()
        ->assertSet('imported', ['created' => 1, 'moved' => 0, 'corrected' => 0, 'invalid' => 0])
        ->assertSee('1 akun siswa dibuat')
        ->assertDontSee('51 akun siswa dibuat');

    expect(Student::query()->count())->toBe(ImportStudentsJob::SYNC_MAX_ROWS + 2)
        ->and(StudentImport::query()->count())->toBe(1);
});

it('keeps refusing a file read while the import runs, without reading it again yet (SV1 review)', function (): void {
    $screen = sv1rQueued($this->kesiswaan);

    $screen->set('file', sv1rFile([['Dewi Anggraini', '226227005', '0101234505', '12-05-2011', 'X-1', '']]))
        ->call('askImport')
        ->assertHasErrors(['file'])
        ->assertSee(ImportSiswa::IMPORT_RUNNING)
        ->assertNotDispatched('sth-toast')
        ->assertNotDispatched('sth-modal-open')
        // Still waiting: it is read again once the import is over.
        ->assertSet('previewWhileImporting', true);
});

it('asks at once about a file read when no import of the same person runs (SV1 review)', function (): void {
    // Forbidden for the rule: another person's running import neither blocks nor stales this preview.
    sv1rJob(F::staff(RoleName::Admin, 'Operator STH'), ['status' => StudentImportStatus::Running, 'total_rows' => 300, 'processed_rows' => 100]);

    Livewire::actingAs($this->kesiswaan)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)
        ->assertSet('importId', null)
        ->set('schoolYear', '2026/2027')
        ->set('file', sv1rFile([['Dewi Anggraini', '226227005', '0101234505', '12-05-2011', 'X-1', '']]))
        ->assertSet('previewWhileImporting', false)
        ->call('askImport')
        ->assertHasNoErrors()
        ->assertNotDispatched('sth-toast')
        ->assertDispatched('sth-modal-open', id: ImportSiswa::IMPORT_DIALOG)
        ->assertSet('importId', null);
});

// (5) An import started in another tab -------------------------------------------------------------------------------

it('takes over an import started in another tab when it refuses, then reads the file again after it (SV1 review)', function (): void {
    $screen = Livewire::actingAs($this->kesiswaan)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', sv1rFile([['Dewi Anggraini', '226227005', '0101234505', '12-05-2011', 'X-1', '']]))
        ->assertSet('importId', null)
        ->assertSet('previewWhileImporting', false)
        ->assertDontSeeHtml('wire:poll.2s');

    // Another tab of the same person queued an import meanwhile.
    $other = sv1rJob($this->kesiswaan, ['status' => StudentImportStatus::Running, 'total_rows' => 300, 'processed_rows' => 100, 'created_count' => 40]);

    // The refusal comes with the import it waits for: its banner and its poll.
    $screen->call('askImport')
        ->assertHasErrors(['file'])
        ->assertSee(ImportSiswa::IMPORT_RUNNING)
        ->assertNotDispatched('sth-modal-open')
        ->assertNoRedirect()
        ->assertSet('importId', $other->getKey())
        ->assertSet('queuedPreview', null)
        ->assertSet('previewWhileImporting', true)
        ->assertSee(StudentImportStatus::Running->label())
        ->assertSee('100 dari 300 baris diproses · 40 akun siswa dibuat.')
        ->assertSeeHtml('wire:poll.2s')
        ->assertSee(sv1rWaiting())
        ->assertViewHas('stepNote', '1 baris terbaca');

    expect(sv1rImportDisabled($screen))->toBeTrue();

    // That import ends: its result shows, and this file is read again before anything is asked.
    $other->forceFill(['status' => StudentImportStatus::Done, 'processed_rows' => 300, 'created_count' => 120])->save();

    $screen->call('$refresh')
        ->assertSee('120 akun siswa dibuat')
        ->assertDontSeeHtml('wire:poll.2s')
        ->assertDontSee(sv1rWaiting());

    expect(sv1rImportDisabled($screen))->toBeFalse();

    $screen->call('askImport')
        ->assertNotDispatched('sth-modal-open')
        ->assertDispatched('sth-toast', message: ImportSiswa::PREVIEW_RECHECKED, tone: 'warning')
        ->call('askImport')
        ->assertDispatched('sth-modal-open', id: ImportSiswa::IMPORT_DIALOG);
});

it('keeps its own running import on screen when it refuses (SV1 review)', function (): void {
    $screen = sv1rQueued($this->kesiswaan);
    $import = StudentImport::query()->sole();

    // A forged click on the queued preview: refused, the import stays the one the screen started.
    $screen->call('askImport')
        ->assertHasErrors(['file'])
        ->assertSet('importId', $import->getKey())
        ->assertSet('queuedPreview', $screen->get('previewToken'))
        ->assertSee('Impor file lain');
});

// (3) The password while an import runs ------------------------------------------------------------------------------

it('asks for the password in step 2 also while an import runs, and finds the import again afterwards (SV1 review)', function (): void {
    session()->forget('auth.password_confirmed_at');
    $job = sv1rJob($this->kesiswaan, ['status' => StudentImportStatus::Running, 'total_rows' => 300, 'processed_rows' => 100]);

    $screen = Livewire::actingAs($this->kesiswaan)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)
        ->assertSet('importId', $job->getKey())
        ->assertSee(StudentImportStatus::Running->label())
        ->assertSeeHtml('wire:poll.2s')
        // The password first, so a file chosen to wait is not dropped by the password page later.
        ->assertSeeHtml('wire:click="askPassword"')
        ->assertSee('Konfirmasi kata sandi')
        ->assertDontSee('Pilih file atau seret ke sini');

    $screen->call('askPassword')->assertRedirect(route('password.confirm'));
    expect(session('url.intended'))->toBe(route('kesiswaan.import').'?'.ImportSiswa::LEVEL_QUERY.'=10');

    // Back from the password page: the running import is found again and the file can be chosen.
    session(A::passwordConfirmed());

    Livewire::actingAs($this->kesiswaan)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)
        ->assertSet('importId', $job->getKey())
        ->assertSee(StudentImportStatus::Running->label())
        ->assertSee('Pilih file atau seret ke sini')
        ->assertDontSeeHtml('wire:click="askPassword"');
});

it('says under a file that waits for a running import when the password confirmation got old (SV1 review)', function (): void {
    sv1rJob($this->kesiswaan, ['status' => StudentImportStatus::Running, 'total_rows' => 300, 'processed_rows' => 100]);

    // Confirmed when the file was chosen: no hint yet.
    $screen = Livewire::actingAs($this->kesiswaan)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', sv1rFile([['Dewi Anggraini', '226227005', '0101234505', '12-05-2011', 'X-1', '']]))
        ->assertSee(sv1rWaiting())
        ->assertDontSee(sv1rHint());

    // The 15 minutes pass while the import runs (the poll renders the screen again).
    session(['auth.password_confirmed_at' => time() - ImportSiswa::PASSWORD_WINDOW_SECONDS - 1]);

    $screen->call('$refresh')
        ->assertSee(sv1rWaiting())
        ->assertSee(sv1rHint());
});

// Last review fixes (2026-11-01) -------------------------------------------------------------------------------------

/** The file input of step 2 carries aria-invalid="true" (an error on `file`). */
function sv1rFileInvalid(Testable $screen): bool
{
    return preg_match('/<input\s[^>]*id="f-file"[^>]*aria-invalid="true"/s', $screen->html()) === 1;
}

it('drops the refusal once the import it waited for is over, so it never stands beside the result (SV1 review)', function (): void {
    $screen = Livewire::actingAs($this->kesiswaan)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', sv1rFile([['Dewi Anggraini', '226227005', '0101234505', '12-05-2011', 'X-1', '']]));

    // Another tab of the same person queued an import meanwhile: the click is refused and takes that import over.
    $other = sv1rJob($this->kesiswaan, ['status' => StudentImportStatus::Running, 'total_rows' => 300, 'processed_rows' => 100, 'created_count' => 40]);

    $screen->call('askImport')
        ->assertHasErrors(['file'])
        ->assertSee(ImportSiswa::IMPORT_RUNNING)
        ->assertSet('importId', $other->getKey());

    expect(sv1rFileInvalid($screen))->toBeTrue();

    // While that import runs, the poll keeps the refusal on screen.
    $screen->call('$refresh')
        ->assertHasErrors(['file'])
        ->assertSee(ImportSiswa::IMPORT_RUNNING)
        ->assertSeeHtml('wire:poll.2s');

    expect(sv1rFileInvalid($screen))->toBeTrue();

    // It ends: the result and an enabled "Impor 1 siswa", without the red sentence under the field or in step 3.
    $other->forceFill(['status' => StudentImportStatus::Done, 'processed_rows' => 300, 'created_count' => 120])->save();

    $screen->call('$refresh')
        ->assertHasNoErrors()
        ->assertSee('120 akun siswa dibuat')
        ->assertSee('Impor 1 siswa')
        ->assertDontSee(ImportSiswa::IMPORT_RUNNING)
        ->assertDontSeeHtml('wire:poll.2s');

    expect(sv1rFileInvalid($screen))->toBeFalse()
        ->and(sv1rImportDisabled($screen))->toBeFalse();

    // Still a request away from a stale dialog: the file is read again first.
    $screen->call('askImport')
        ->assertNotDispatched('sth-modal-open')
        ->assertDispatched('sth-toast', message: ImportSiswa::PREVIEW_RECHECKED, tone: 'warning')
        ->assertDontSee(ImportSiswa::IMPORT_RUNNING);
});

it('drops the refusal of a click on the queued preview once its own import is done (SV1 review)', function (): void {
    $screen = sv1rQueued($this->kesiswaan);
    $import = StudentImport::query()->sole();

    // A forged click on the queued preview: refused while the import waits.
    $screen->call('askImport')->assertSee(ImportSiswa::IMPORT_RUNNING);

    (new ImportStudentsJob($import->getKey()))->handle(app(ImportStudents::class), app(StoreUpload::class));

    $screen->call('$refresh')
        ->assertHasNoErrors()
        ->assertDontSee(ImportSiswa::IMPORT_RUNNING)
        ->assertSee('51 akun siswa dibuat')
        ->assertSee('Impor file lain');

    expect(sv1rFileInvalid($screen))->toBeFalse();
});

it('counts a file as read next to an import that ended while its dry run read the database (SV1 review)', function (): void {
    $running = sv1rJob($this->kesiswaan, ['status' => StudentImportStatus::Running, 'total_rows' => 300, 'processed_rows' => 280, 'created_count' => 40]);

    $screen = Livewire::actingAs($this->kesiswaan)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)
        ->assertSet('importId', $running->getKey())
        ->set('schoolYear', '2026/2027');

    // The worker finishes while the dry run reads the classes: no import runs any more once the read is over, but the
    // rows of that import were written during it.
    $ended = false;
    DB::listen(function (QueryExecuted $query) use (&$ended, $running): void {
        if ($ended || ! str_contains($query->sql, 'classrooms')) {
            return;
        }

        $ended = true;
        StudentImport::query()->whereKey($running->getKey())->update(['status' => StudentImportStatus::Done->value, 'processed_rows' => 300]);
    });

    $screen->set('file', sv1rFile([['Dewi Anggraini', '226227005', '0101234505', '12-05-2011', 'X-1', '']]))
        ->assertHasNoErrors()
        ->assertSet('previewWhileImporting', true);

    expect($ended)->toBeTrue()
        ->and($running->refresh()->status)->toBe(StudentImportStatus::Done);

    // So the first click reads the file again, the second asks.
    $screen->call('askImport')
        ->assertNotDispatched('sth-modal-open')
        ->assertDispatched('sth-toast', message: ImportSiswa::PREVIEW_RECHECKED, tone: 'warning')
        ->assertSet('previewWhileImporting', false)
        ->call('askImport')
        ->assertDispatched('sth-modal-open', id: ImportSiswa::IMPORT_DIALOG);
});

it('words the toast of a file read again so that it also holds when nothing is left to import (SV1 review)', function (): void {
    expect(ImportSiswa::PREVIEW_RECHECKED)->toBe('Impor sebelumnya sudah berakhir. File ini sudah diperiksa ulang; cek angkanya sebelum mengimpor.')
        ->not->toContain('lalu impor');

    $screen = sv1rQueued($this->kesiswaan);
    $import = StudentImport::query()->sole();

    // One row of the queued file only: new while that import waits, nothing to write once it is done.
    $screen->set('file', sv1rFile([sv1rRow(1)]))
        ->assertSet('previewWhileImporting', true)
        ->assertViewHas('changeCount', 1);

    (new ImportStudentsJob($import->getKey()))->handle(app(ImportStudents::class), app(StoreUpload::class));

    $screen->call('$refresh')
        ->call('askImport')
        ->assertNotDispatched('sth-modal-open')
        ->assertDispatched('sth-toast', message: ImportSiswa::PREVIEW_RECHECKED, tone: 'warning')
        ->assertViewHas('changeCount', 0)
        ->assertViewHas('unchangedCount', 1)
        ->assertSee('Impor 0 siswa');

    expect(sv1rImportDisabled($screen))->toBeTrue()
        ->and(Student::query()->count())->toBe(ImportStudentsJob::SYNC_MAX_ROWS + 1);
});
