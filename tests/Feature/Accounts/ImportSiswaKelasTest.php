<?php

declare(strict_types=1);

use App\Actions\Accounts\ImportStudents;
use App\Domain\Archive\GraduationCalculator;
use App\Domain\Files\StoreUpload;
use App\Enums\RoleName;
use App\Jobs\ImportStudentsJob;
use App\Livewire\Akun\ImportSiswa;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\StudentImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

/*
 | SV6, D-184 (the lead, 5 Nov 2026, sementara; replaces the D-177 "Tahun lain…" tests): Import siswa › step 2 offers
 | "Tahun ajaran" 2026/2027 only (a forged year is put back, only 2026/2027 reaches ImportStudents) and under it the
 | required "Kelas" X, XI, XII. A row of another tingkat is refused with one sentence, in the dry run and in the import.
 | Fictional data only (DESIGN-SPEC-v2 §5).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Queue::fake();
    $this->withoutVite();
    Classroom::factory()->create(['name' => 'X-1', 'level' => 10]);
    Classroom::factory()->create(['name' => 'XI-4', 'level' => 11]);
    Classroom::factory()->create(['name' => 'XII-2', 'level' => 12]);
    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    session(A::passwordConfirmed());
});

/** One fictional student in XII-2 and one in XI-4. */
function kfMixedFile(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('data-siswa.csv', implode("\n", [
        'Nama lengkap,NIS,NISN,Tanggal lahir,Kelas,No HP',
        'Aurelia Maharani,226227031,0101234557,12-05-2009,XII-2,',
        'Hana Safira,226227088,0103456714,03-01-2010,XI-4,',
    ])."\n");
}

/** SYNC_MAX_ROWS + 1 fictional students in XII-2 and one in X-1: the import is queued. */
function kfBigFile(): UploadedFile
{
    $lines = ['Nama lengkap,NIS,NISN,Tanggal lahir,Kelas,No HP'];

    foreach (range(1, ImportStudentsJob::SYNC_MAX_ROWS + 1) as $i) {
        $letters = '';
        $n = $i;

        do {
            $letters = chr(65 + $n % 26).$letters;
            $n = intdiv($n, 26) - 1;
        } while ($n >= 0);

        $lines[] = sprintf('Siswa Uji %s,2272%05d,02%08d,12-05-2009,XII-2,', $letters, 100 + $i, 100 + $i);
    }

    $lines[] = 'Hana Safira,226227088,0103456714,03-01-2011,X-1,';

    return UploadedFile::fake()->createWithContent('data-siswa-besar.csv', implode("\n", $lines)."\n");
}

function kfScreen(?string $level = null): Testable
{
    $livewire = Livewire::actingAs(test()->kesiswaan);

    return ($level !== null ? $livewire->withQueryParams([ImportSiswa::LEVEL_QUERY => $level]) : $livewire)->test(ImportSiswa::class);
}

it('offers "Tahun ajaran" 2026/2027 only, without "Tahun lain…", and "Kelas" X, XI, XII under it', function (): void {
    kfScreen()
        ->assertSet('schoolYear', ImportSiswa::FIXED_SCHOOL_YEAR)
        ->assertViewHas('yearOptions', ['2026/2027' => '2026/2027'])
        ->assertViewHas('levelOptions', ['10' => 'X', '11' => 'XI', '12' => 'XII'])
        ->assertSeeHtml('id="import-kelas"')
        ->assertSee('Tahun ajaran')
        ->assertDontSee('Tahun ajaran data')
        ->assertSee('Dipakai untuk menghitung kelas XII dan tanggal arsip.')
        ->assertSee('Pilih kelas')
        ->assertDontSee('Tahun lain')
        ->assertDontSee('Tahun mulai')
        ->assertDontSeeHtml('customYear');
});

it('puts a forged school year back and reads and imports with 2026/2027 only', function (): void {
    $screen = kfScreen('12')
        ->set('schoolYear', '2030/2031')
        ->assertSet('schoolYear', '2026/2027')
        ->set('file', kfMixedFile())
        ->assertSee('data-siswa.csv · Tahun ajaran 2026/2027 · Kelas XII')
        ->assertViewHas('preview', fn (?array $preview): bool => $preview['schoolYear'] === '2026/2027' && $preview['level'] === 12)
        ->set('schoolYear', 'lain')
        ->assertSet('schoolYear', '2026/2027')
        ->call('askImport')
        ->call('import')
        ->assertHasNoErrors();

    $dates = app(GraduationCalculator::class)->calculate(12, '2026/2027');
    $student = Student::query()->sole();

    expect($student->import_school_year)->toBe('2026/2027')
        ->and($student->archive_at->toDateString())->toBe($dates->archiveAt->toDateString());
    $screen->assertSee('1 akun siswa dibuat');
});

it('ignores a school year in the address and keeps the chosen kelas across the password page', function (): void {
    Livewire::withQueryParams(['tahun' => 'lain', 'tahun_mulai' => '2028', ImportSiswa::LEVEL_QUERY => '11'])
        ->actingAs($this->kesiswaan)
        ->test(ImportSiswa::class)
        ->assertSet('schoolYear', '2026/2027')
        ->assertSet('level', '11');

    session()->forget('auth.password_confirmed_at');

    kfScreen()
        ->set('level', '12')
        ->call('askPassword')
        ->assertRedirect(route('password.confirm'));

    expect(session('url.intended'))->toBe(route('kesiswaan.import').'?'.http_build_query([ImportSiswa::LEVEL_QUERY => '12']));
});

it('ignores a kelas in the address that is not X, XI or XII', function (string $asked): void {
    Livewire::withQueryParams([ImportSiswa::LEVEL_QUERY => $asked])
        ->actingAs($this->kesiswaan)
        ->test(ImportSiswa::class)
        ->assertSet('level', '');
})->with(['9', '13', '12a', ' 12', 'XII', '']);

it('needs "Kelas" before the file is read: the file waits, nothing is imported', function (): void {
    $screen = kfScreen()
        ->set('file', kfMixedFile())
        ->assertHasErrors(['level'])
        ->assertSee(ImportSiswa::LEVEL_MISSING)
        ->assertSee('Kelas belum dipilih')
        ->assertSee('Menunggu pilihan kelas')
        ->assertSet('previewToken', null)
        ->call('askImport')
        ->assertHasErrors(['level'])
        ->assertNotDispatched('sth-modal-open');

    expect(Student::query()->count())->toBe(0)
        ->and($screen->get('file'))->not->toBeNull();

    // A forged tingkat is emptied and the file still waits.
    $screen->set('level', '13')->assertSet('level', '')->assertHasErrors(['level'])->assertSet('previewToken', null);

    // Once a tingkat is chosen the waiting file is read.
    $screen->set('level', '12')
        ->assertHasNoErrors()
        ->assertViewHas('preview', fn (?array $preview): bool => $preview !== null && $preview['level'] === 12);
});

it('refuses the rows of another tingkat with the sentence and imports only the matching rows', function (): void {
    $screen = kfScreen('12')
        ->set('file', kfMixedFile())
        ->assertHasNoErrors()
        ->assertSee('Kelas XI-4 bukan kelas XII. File ini untuk kelas XII saja.')
        ->assertViewHas('preview', function (?array $preview): bool {
            [$xii, $xi] = $preview['rows'];

            return $xii['action'] === 'new' && $xii['errors'] === []
                && $xi['action'] === 'rejected' && $xi['errors'] === ['Kelas XI-4 bukan kelas XII. File ini untuk kelas XII saja.'];
        })
        ->call('askImport')
        ->call('import')
        ->assertHasNoErrors()
        ->assertSee('1 akun siswa dibuat');

    expect(Student::query()->with('user')->get()->map(fn (Student $s): string => $s->user->name)->all())->toBe(['Aurelia Maharani']);
});

it('imports nothing from a file that holds only rows of another tingkat', function (): void {
    kfScreen('10')
        ->set('file', kfMixedFile())
        ->assertSee('Kelas XII-2 bukan kelas X. File ini untuk kelas X saja.')
        ->assertSee('Kelas XI-4 bukan kelas X. File ini untuk kelas X saja.')
        ->assertViewHas('changeCount', 0)
        ->call('askImport')
        ->call('import');

    expect(Student::query()->count())->toBe(0);
});

it('reads the chosen file again when "Kelas" changes', function (): void {
    $screen = kfScreen('12')->set('file', kfMixedFile());
    $first = $screen->get('previewToken');

    $screen->set('level', '11')
        ->assertHasNoErrors()
        ->assertSee('Kelas XII-2 bukan kelas XI. File ini untuk kelas XI saja.')
        ->assertSee('data-siswa.csv · Tahun ajaran 2026/2027 · Kelas XI')
        ->assertViewHas('preview', fn (?array $preview): bool => $preview['level'] === 11
            && $preview['rows'][1]['action'] === 'new');

    expect($screen->get('previewToken'))->not->toBe($first)->not->toBeNull();
});

it('passes the tingkat to the queued import: the job refuses the row of another tingkat', function (): void {
    kfScreen('12')
        ->set('file', kfBigFile())
        ->assertHasNoErrors()
        ->call('askImport')
        ->call('import')
        ->assertHasNoErrors()
        ->assertSee('Impor file lain');

    $import = StudentImport::query()->sole();

    expect($import->school_year)->toBe('2026/2027');
    Queue::assertPushed(ImportStudentsJob::class, fn (ImportStudentsJob $job): bool => $job->level === 12);

    (new ImportStudentsJob($import->getKey(), 0, 12))->handle(app(ImportStudents::class), app(StoreUpload::class));

    expect(Student::query()->count())->toBe(ImportStudentsJob::SYNC_MAX_ROWS + 1)
        ->and(Student::query()->where('nisn', '0103456714')->exists())->toBeFalse()
        ->and($import->refresh()->invalid_rows)->toBe(1);
});

it('checks no tingkat when the Action gets none, and refuses a level outside X, XI, XII', function (): void {
    $file = kfMixedFile();
    $path = $file->getRealPath();
    $import = app(ImportStudents::class);

    $report = $import->handle($this->kesiswaan, $path, 'csv', '2026/2027');

    expect(array_column($report->rows, 'errors'))->toBe([[], []]);

    expect(fn () => $import->handle($this->kesiswaan, $path, 'csv', '2026/2027', level: 9))
        ->toThrow(ValidationException::class);
});
