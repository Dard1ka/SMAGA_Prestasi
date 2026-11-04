<?php

declare(strict_types=1);

use App\Actions\Accounts\ImportStudents;
use App\Domain\Archive\GraduationCalculator;
use App\Domain\Archive\SchoolYear;
use App\Domain\Files\StoreUpload;
use App\Enums\RoleName;
use App\Jobs\ImportStudentsJob;
use App\Livewire\Akun\ImportSiswa;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\StudentImport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

/*
 | SV4 (lead, 2026-11-04): "tambahkan opsi untuk menambahkan tahun ajaran, jadi bisa manual contoh tahun 2028". Import
 | siswa › "Tahun ajaran data" keeps its three listed years and ends with "Tahun lain…"; the start year typed under it
 | ("Tahun mulai", the current start year ± 10) gives "YYYY/YYYY+1", which the dry run, the import and the queued import
 | use. Fictional data only (DESIGN-SPEC-v2 §5).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Queue::fake();
    $this->withoutVite();
    Classroom::factory()->create(['name' => 'X-1', 'level' => 10]);
    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    session(A::passwordConfirmed());
});

/** Two new fictional students in X-1. */
function tlFile(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('data-siswa.csv', implode("\n", [
        'Nama lengkap,NIS,NISN,Tanggal lahir,Kelas,No HP',
        'Aurelia Maharani,226227031,0101234557,12-05-2011,X-1,',
        'Hana Safira,226227088,0103456714,03-01-2011,X-1,',
    ])."\n");
}

/** Letters only (names may not hold digits): 1 → A, 27 → AA. */
function tlLetters(int $n): string
{
    $s = '';

    do {
        $s = chr(65 + $n % 26).$s;
        $n = intdiv($n, 26) - 1;
    } while ($n >= 0);

    return $s;
}

/** SYNC_MAX_ROWS + 1 new students: the import is queued. */
function tlBigFile(): UploadedFile
{
    $lines = ['Nama lengkap,NIS,NISN,Tanggal lahir,Kelas,No HP'];

    foreach (range(1, ImportStudentsJob::SYNC_MAX_ROWS + 1) as $i) {
        $lines[] = sprintf('Siswa Uji %s,2272%05d,02%08d,12-05-2011,X-1,', tlLetters($i), 100 + $i, 100 + $i);
    }

    return UploadedFile::fake()->createWithContent('data-siswa-besar.csv', implode("\n", $lines)."\n");
}

function tlCurrentStart(): int
{
    return SchoolYear::containing(CarbonImmutable::now())->startYear;
}

function tlScreen(): Testable
{
    return Livewire::actingAs(test()->kesiswaan)->test(ImportSiswa::class);
}

it('offers "Tahun lain…" after the three listed years and opens "Tahun mulai" only when it is chosen', function (): void {
    $current = tlCurrentStart();
    $screen = tlScreen()
        ->assertViewHas('yearOptions', fn (array $options): bool => array_keys($options) === [
            ($current + 1).'/'.($current + 2),
            $current.'/'.($current + 1),
            ($current - 1).'/'.$current,
            ImportSiswa::OTHER_YEAR,
        ] && end($options) === 'Tahun lain…')
        ->assertSeeHtml('id="import-tahun-ajaran"')
        ->assertDontSeeHtml('id="import-tahun-mulai"')
        ->assertDontSee('Tahun mulai');

    $screen->set('schoolYear', ImportSiswa::OTHER_YEAR)
        ->assertHasNoErrors()
        ->assertSee('Tahun mulai')
        ->assertSeeHtml('id="import-tahun-mulai"')
        ->assertSeeHtml('for="import-tahun-mulai"')
        ->assertSeeHtml('inputmode="numeric"')
        ->assertSeeHtml('wire:model.live.debounce.400ms="customYear"')
        ->assertDontSee('Tahun saat tahun ajaran dimulai')
        ->assertDontSee('Tahun ajaran 20');
});

it('reads and imports the file with the typed school year: 2028 → 2028/2029, also for the archive dates', function (): void {
    $screen = tlScreen()
        ->set('schoolYear', ImportSiswa::OTHER_YEAR)
        ->set('customYear', '2028')
        ->assertHasNoErrors()
        // The live line under the field.
        ->assertSee('Tahun ajaran 2028/2029')
        ->assertDontSee('Juli 2028 sampai Juni 2029')
        ->set('file', tlFile())
        ->assertHasNoErrors()
        // The preview line of step 3 names the year the dry run used.
        ->assertSee('data-siswa.csv · Tahun ajaran 2028/2029')
        ->assertViewHas('preview', fn (?array $preview): bool => $preview['schoolYear'] === '2028/2029'
            && $preview['rows'][0]['grade12_start_date'] === '2030-07-01')
        ->call('askImport')
        ->assertDispatched('sth-modal-open', id: ImportSiswa::IMPORT_DIALOG)
        ->call('import')
        ->assertHasNoErrors()
        ->assertSee('2 akun siswa dibuat');

    $dates = app(GraduationCalculator::class)->calculate(10, '2028/2029');

    expect(Student::query()->count())->toBe(2);

    Student::query()->get()->each(function (Student $student) use ($dates): void {
        expect($student->import_school_year)->toBe('2028/2029')
            ->and($student->grade12_start_date->toDateString())->toBe($dates->grade12StartDate->toDateString())
            ->and($student->archive_at->toDateString())->toBe($dates->archiveAt->toDateString());
    });

    $screen->assertSet('schoolYear', ImportSiswa::OTHER_YEAR)->assertSet('customYear', '2028');
});

it('keeps a chosen file while "Tahun lain…" has no start year yet, and reads it once the year is typed', function (): void {
    tlScreen()
        ->set('schoolYear', '2026/2027')
        ->set('file', tlFile())
        ->assertViewHas('preview', fn (?array $preview): bool => $preview['schoolYear'] === '2026/2027')
        // "Tahun lain…" with an empty field: the preview of 2026/2027 goes, the file stays, no red line yet.
        ->set('schoolYear', ImportSiswa::OTHER_YEAR)
        ->assertHasNoErrors()
        ->assertSet('previewToken', null)
        ->assertSet('file', fn ($file): bool => $file !== null)
        ->assertSee('Tahun ajaran belum lengkap')
        ->assertSee('Sesudah itu file data-siswa.csv langsung diperiksa.')
        ->assertSee('Menunggu tahun ajaran')
        ->assertDontSeeHtml('wire:click="askImport"')
        ->set('customYear', '2028')
        ->assertHasNoErrors()
        ->assertViewHas('preview', fn (?array $preview): bool => $preview['schoolYear'] === '2028/2029')
        ->assertSee('Tahun ajaran 2028/2029');
});

it('refuses an empty, short, non-numeric or out-of-range start year under the field and imports nothing', function (string $typed, string $kind): void {
    $current = tlCurrentStart();
    $example = (string) ($current + 2);
    $message = match ($kind) {
        'empty' => 'Isi tahun mulai, misalnya '.$example.'.',
        'digits' => 'Tahun mulai harus 4 angka, misalnya '.$example.'.',
        'range' => 'Tahun mulai harus antara '.($current - 10).' dan '.($current + 10).'.',
    };
    $typed = match ($typed) {
        'below' => (string) ($current - 11),
        'above' => (string) ($current + 11),
        default => $typed,
    };

    $screen = tlScreen()
        ->set('schoolYear', ImportSiswa::OTHER_YEAR)
        ->set('customYear', '2028')
        ->set('file', tlFile())
        ->assertViewHas('preview', fn (?array $preview): bool => $preview !== null)
        ->set('customYear', $typed)
        ->assertHasErrors(['customYear'])
        ->assertSee($message)
        ->assertSeeHtml('aria-invalid="true"')
        ->assertDontSee('Tahun ajaran 2028/2029')
        // The preview of 2028/2029 is gone, the file stays chosen.
        ->assertSet('previewToken', null)
        ->assertSet('file', fn ($file): bool => $file !== null)
        ->assertDontSeeHtml('wire:click="askImport"')
        // A forged click writes nothing and opens no dialog.
        ->call('askImport')
        ->assertNotDispatched('sth-modal-open')
        ->call('import')
        ->assertSet('imported', null);

    expect($screen->errors()->first('customYear'))->toBe($message)
        ->and(Student::query()->count())->toBe(0)
        ->and(StudentImport::query()->count())->toBe(0);
})->with([
    'emptied' => ['', 'empty'],
    'spaces only' => ['   ', 'empty'],
    'two digits' => ['20', 'digits'],
    'letters' => ['20a8', 'digits'],
    'a school year' => ['2028/2029', 'digits'],
    'before the range' => ['below', 'range'],
    'after the range' => ['above', 'range'],
]);

it('accepts both ends of the range', function (int $offset): void {
    $year = tlCurrentStart() + $offset;

    tlScreen()
        ->set('schoolYear', ImportSiswa::OTHER_YEAR)
        ->set('customYear', (string) $year)
        ->set('file', tlFile())
        ->assertHasNoErrors()
        ->assertViewHas('preview', fn (?array $preview): bool => $preview['schoolYear'] === $year.'/'.($year + 1));
})->with([-10, 10]);

it('names the empty start year when a file is chosen under "Tahun lain…" before it is typed', function (): void {
    $screen = tlScreen()
        ->set('schoolYear', ImportSiswa::OTHER_YEAR)
        ->set('file', tlFile())
        ->assertHasErrors(['customYear'])
        ->assertSet('previewToken', null)
        ->assertSet('file', fn ($file): bool => $file !== null)
        ->assertSee('Tahun ajaran belum lengkap');

    expect($screen->errors()->first('customYear'))->toBe('Isi tahun mulai, misalnya '.(tlCurrentStart() + 2).'.');
});

it('keeps the typed text as typed and reads it trimmed (SV1: no trimmed write-back)', function (): void {
    tlScreen()
        ->set('schoolYear', ImportSiswa::OTHER_YEAR)
        ->set('customYear', ' 2028 ')
        ->assertSet('customYear', ' 2028 ')
        ->assertHasNoErrors()
        ->assertSee('Tahun ajaran 2028/2029')
        ->set('file', tlFile())
        ->assertViewHas('preview', fn (?array $preview): bool => $preview['schoolYear'] === '2028/2029');
});

it('switches back to a listed year: the error goes and the file is read with that year', function (): void {
    tlScreen()
        ->set('schoolYear', ImportSiswa::OTHER_YEAR)
        ->set('file', tlFile())
        ->set('customYear', '19')
        ->assertHasErrors(['customYear'])
        ->set('schoolYear', '2026/2027')
        ->assertHasNoErrors()
        ->assertDontSee('Tahun mulai')
        ->assertSee('data-siswa.csv · Tahun ajaran 2026/2027')
        ->call('askImport')
        ->call('import')
        ->assertSee('2 akun siswa dibuat');

    expect(Student::query()->pluck('import_school_year')->unique()->values()->all())->toBe(['2026/2027']);
});

it('reads the file again when the typed year changes to another valid year', function (): void {
    tlScreen()
        ->set('schoolYear', ImportSiswa::OTHER_YEAR)
        ->set('customYear', '2028')
        ->set('file', tlFile())
        ->assertViewHas('preview', fn (?array $preview): bool => $preview['schoolYear'] === '2028/2029')
        ->set('customYear', '2030')
        ->assertHasNoErrors()
        ->assertViewHas('preview', fn (?array $preview): bool => $preview['schoolYear'] === '2030/2031')
        ->assertSee('data-siswa.csv · Tahun ajaran 2030/2031');
});

it('reads the file again when the preview on screen was read with another year than the chosen one', function (): void {
    $screen = tlScreen()
        ->set('schoolYear', ImportSiswa::OTHER_YEAR)
        ->set('customYear', '2028')
        ->set('file', tlFile());

    // A preview of another year under the chosen 2028/2029 (the year hooks never leave one; this is the guard).
    $key = 'import-siswa:'.$this->kesiswaan->getKey().':'.$screen->get('previewToken');
    Cache::put($key, ['schoolYear' => '2027/2028'] + Cache::get($key), now()->addMinutes(30));

    $screen->call('askImport')
        ->assertNotDispatched('sth-modal-open')
        ->assertDispatched('sth-toast', message: ImportSiswa::PREVIEW_YEAR_RECHECKED, tone: 'warning')
        ->assertViewHas('preview', fn (?array $preview): bool => $preview['schoolYear'] === '2028/2029')
        ->call('askImport')
        ->assertDispatched('sth-modal-open', id: ImportSiswa::IMPORT_DIALOG)
        ->call('import')
        ->assertSee('2 akun siswa dibuat');

    expect(Student::query()->pluck('import_school_year')->unique()->values()->all())->toBe(['2028/2029']);
});

it('records the typed school year on a queued import and the job writes it', function (): void {
    tlScreen()
        ->set('schoolYear', ImportSiswa::OTHER_YEAR)
        ->set('customYear', '2028')
        ->set('file', tlBigFile())
        ->assertHasNoErrors()
        ->call('askImport')
        ->call('import')
        ->assertHasNoErrors()
        ->assertSee('Impor file lain');

    $import = StudentImport::query()->sole();

    expect($import->school_year)->toBe('2028/2029');
    Queue::assertPushed(ImportStudentsJob::class);

    (new ImportStudentsJob($import->getKey()))->handle(app(ImportStudents::class), app(StoreUpload::class));

    $dates = app(GraduationCalculator::class)->calculate(10, '2028/2029');

    expect(Student::query()->count())->toBe(ImportStudentsJob::SYNC_MAX_ROWS + 1)
        ->and(Student::query()->pluck('import_school_year')->unique()->values()->all())->toBe(['2028/2029'])
        ->and(Student::query()->pluck('archive_at')->map->toDateString()->unique()->values()->all())->toBe([$dates->archiveAt->toDateString()]);
});

it('keeps the chosen year across the password page: the return address carries it and the page reads it back', function (): void {
    session()->forget('auth.password_confirmed_at');

    tlScreen()
        ->set('schoolYear', ImportSiswa::OTHER_YEAR)
        ->set('customYear', '2028')
        ->call('askPassword')
        ->assertRedirect(route('password.confirm'));

    expect(session('url.intended'))->toBe(route('kesiswaan.import').'?'.http_build_query([ImportSiswa::YEAR_QUERY => 'lain', ImportSiswa::CUSTOM_YEAR_QUERY => '2028']));

    Livewire::withQueryParams([ImportSiswa::YEAR_QUERY => 'lain', ImportSiswa::CUSTOM_YEAR_QUERY => '2028'])
        ->actingAs($this->kesiswaan)
        ->test(ImportSiswa::class)
        ->assertSet('schoolYear', ImportSiswa::OTHER_YEAR)
        ->assertSet('customYear', '2028')
        ->assertSee('Tahun ajaran 2028/2029');

    $next = (tlCurrentStart() + 1).'/'.(tlCurrentStart() + 2);

    Livewire::withQueryParams([ImportSiswa::YEAR_QUERY => $next])
        ->actingAs($this->kesiswaan)
        ->test(ImportSiswa::class)
        ->assertSet('schoolYear', $next)
        ->assertSet('customYear', '');
});

it('ignores a year in the address that is not one of the choices', function (mixed $asked): void {
    Livewire::withQueryParams([ImportSiswa::YEAR_QUERY => $asked, ImportSiswa::CUSTOM_YEAR_QUERY => '<b>20x28</b>'])
        ->actingAs($this->kesiswaan)
        ->test(ImportSiswa::class)
        ->assertSet('schoolYear', SchoolYear::containing(CarbonImmutable::now())->toString())
        ->assertSet('customYear', '');
})->with([['1999/2000'], ['bukan-tahun'], [['lain']]]);

it('leaves the return address plain for the current school year', function (): void {
    session()->forget('auth.password_confirmed_at');

    tlScreen()->call('askPassword')->assertRedirect(route('password.confirm'));

    expect(session('url.intended'))->toBe(route('kesiswaan.import'));
});
