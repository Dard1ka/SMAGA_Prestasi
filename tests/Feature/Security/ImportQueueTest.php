<?php

declare(strict_types=1);

use App\Actions\Accounts\ImportStudents;
use App\Domain\Files\StoreUpload;
use App\Enums\FilePurpose;
use App\Enums\RoleName;
use App\Enums\StudentImportStatus;
use App\Jobs\ImportStudentsJob;
use App\Livewire\Akun\ImportSiswa;
use App\Models\Classroom;
use App\Models\File;
use App\Models\Student;
use App\Models\StudentImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

uses(RefreshDatabase::class);

/*
 | Backlog #19: big student imports run as a queued job (slices + progress row + polling on the import screen);
 | the dry run stays synchronous; cells starting with = + - @ are still stripped AND the row is flagged
 | "Sel dibersihkan" (lead decision).
 */

beforeEach(function (): void {
    F::boot();
    $this->withoutVite();
    Classroom::factory()->create(['name' => 'X-1', 'level' => 10]);
    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    session(['auth.password_confirmed_at' => time()]); // QA9: the import needs a recent password confirmation
});

/** Letters-only suffix (names may not contain digits). */
function b2Letters(int $n): string
{
    $s = '';

    do {
        $s = chr(65 + $n % 26).$s;
        $n = intdiv($n, 26) - 1;
    } while ($n >= 0);

    return $s;
}

/** CSV content with $valid valid fictional rows and $invalid rows with a 9-digit NISN. */
function b2ImportCsv(int $valid, int $invalid = 0, array $extra = []): string
{
    $lines = ['Nama lengkap,NIS,NISN,Tanggal lahir,Kelas saat pendaftaran,No HP'];

    for ($i = 1; $i <= $valid; $i++) {
        $lines[] = sprintf('Siswa Uji %s,2252%05d,00%08d,02-03-2010,X-1,', b2Letters($i), $i, $i);
    }

    for ($i = 1; $i <= $invalid; $i++) {
        $lines[] = sprintf('Siswa Salah %s,2253%05d,%09d,02-03-2010,X-1,', b2Letters($i), $i, $i);
    }

    foreach ($extra as $row) {
        $lines[] = implode(',', $row);
    }

    return implode("\n", $lines)."\n";
}

function b2CsvPath(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'b2-import-');
    file_put_contents($path, $content);

    return $path;
}

function b2QueuedImport(User $actor, string $csv): StudentImport
{
    $file = app(StoreUpload::class)->storeSpreadsheet(UploadedFile::fake()->createWithContent('data-siswa.csv', $csv), $actor);

    $import = new StudentImport([
        'user_id' => $actor->getKey(),
        'extension' => 'csv',
        'school_year' => '2026/2027',
        'file_name' => 'data-siswa.csv',
        'file_sha256' => $file->sha256,
    ]);
    $import->forceFill(['status' => StudentImportStatus::Queued, 'file_id' => $file->getKey()])->save();

    return $import;
}

it('strips formula prefixes but flags those rows as "Sel dibersihkan"', function (): void {
    $csv = b2ImportCsv(0, 0, [
        ['=Nadia Putri Anindya', '225226201', '0098765432', '02-03-2010', 'X-1', ''],
        ['@Raka Pratama', '225226202', '0098765433', '02-03-2010', 'X-1', ''],
        ['Salsa Maharani', '-225226203', '0098765434', '02-03-2010', 'X-1', '+6281298765432'],
        ['Dimas Saputra', '225226204', '0098765435', '02-03-2010', 'X-1', '+6281298765433'],
        ['"=HYPERLINK(""http://evil.example"",""klik"")"', '225226205', '0098765436', '02-03-2010', 'X-1', ''],
    ]);

    $report = app(ImportStudents::class)->handle($this->kesiswaan, b2CsvPath($csv), 'csv', '2026/2027');
    $byRow = collect($report->rows)->keyBy('row');

    expect($byRow[2])->toMatchArray(['name' => 'Nadia Putri Anindya', 'sanitized' => true, 'errors' => []])
        ->and($byRow[3])->toMatchArray(['name' => 'Raka Pratama', 'sanitized' => true, 'errors' => []])
        ->and($byRow[4])->toMatchArray(['nis' => '225226203', 'sanitized' => true, 'errors' => []])
        ->and($byRow[5]['sanitized'])->toBeFalse()
        ->and($byRow[6]['sanitized'])->toBeTrue()
        ->and($byRow[6]['errors'])->toContain('Nama lengkap hanya boleh berisi huruf.')
        ->and($report->sanitizedCount())->toBe(4)
        ->and($report->validCount())->toBe(4);
});

it('commits one slice at a time and never creates a row twice', function (): void {
    $path = b2CsvPath(b2ImportCsv(5, 1));
    $importer = app(ImportStudents::class);

    $first = $importer->importSlice($this->kesiswaan, $path, 'csv', '2026/2027', 0, 2);
    $again = $importer->importSlice($this->kesiswaan, $path, 'csv', '2026/2027', 0, 2);
    $rest = $importer->importSlice($this->kesiswaan, $path, 'csv', '2026/2027', 2, 10);

    expect($first)->toMatchArray(['rows' => 6, 'valid' => 5, 'invalid' => 1, 'created' => 2])
        ->and($again['created'])->toBe(0)
        ->and($rest['created'])->toBe(3)
        ->and(Student::query()->count())->toBe(5)
        ->and(Activity::query()->where('event', 'student.import')->count())->toBe(0);
});

it('runs a queued import slice by slice, records progress, audits once and deletes the file', function (): void {
    $import = b2QueuedImport($this->kesiswaan, b2ImportCsv(230, 3));
    $fileId = $import->file_id;
    $storedPath = File::query()->find($fileId)->path;

    ImportStudentsJob::dispatch($import->getKey()); // sync queue in tests: every slice runs now

    $import->refresh();
    $audit = Activity::query()->where('event', 'student.import')->get();

    expect($import->status)->toBe(StudentImportStatus::Done)
        ->and($import->total_rows)->toBe(233)
        ->and($import->valid_rows)->toBe(230)
        ->and($import->invalid_rows)->toBe(3)
        ->and($import->processed_rows)->toBe(233)
        ->and($import->created_count)->toBe(230)
        ->and($import->progressPercent())->toBe(100)
        ->and($import->started_at)->not->toBeNull()
        ->and($import->finished_at)->not->toBeNull()
        ->and($import->file_id)->toBeNull()
        ->and(File::query()->find($fileId))->toBeNull()
        ->and(Storage::disk('local')->exists($storedPath))->toBeFalse()
        ->and(Student::query()->count())->toBe(230)
        ->and($audit)->toHaveCount(1)
        ->and($audit[0]->properties->all())->toMatchArray(['rows' => 233, 'created' => 230, 'invalid' => 3, 'file_sha256' => $import->file_sha256]);
});

it('keeps progress between slices when the queue runs them later', function (): void {
    Queue::fake();
    $import = b2QueuedImport($this->kesiswaan, b2ImportCsv(150));

    (new ImportStudentsJob($import->getKey()))->handle(app(ImportStudents::class), app(StoreUpload::class));

    $import->refresh();

    expect($import->status)->toBe(StudentImportStatus::Running)
        ->and($import->processed_rows)->toBe(ImportStudentsJob::CHUNK)
        ->and($import->created_count)->toBe(ImportStudentsJob::CHUNK)
        ->and($import->progressPercent())->toBe(66);

    Queue::assertPushed(ImportStudentsJob::class, fn (ImportStudentsJob $job): bool => $job->offset === ImportStudentsJob::CHUNK);
});

it('fails gracefully when the importer lost the permission or the file is gone', function (): void {
    $import = b2QueuedImport($this->kesiswaan, b2ImportCsv(3));
    $this->kesiswaan->roles()->detach();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    ImportStudentsJob::dispatch($import->getKey());

    expect($import->refresh()->status)->toBe(StudentImportStatus::Failed)
        ->and($import->error)->toBe('Akun pengimpor tidak lagi berhak mengimpor data siswa.')
        ->and($import->file_id)->toBeNull()
        ->and(Student::query()->count())->toBe(0);

    $other = F::staff(RoleName::Admin, 'Operator STH');
    $second = b2QueuedImport($other, b2ImportCsv(3));
    $second->file->storage()->delete($second->file->path);

    ImportStudentsJob::dispatch($second->getKey());

    expect($second->refresh()->status)->toBe(StudentImportStatus::Failed)
        ->and($second->error)->toBe('File impor tidak ditemukan. Upload ulang file.');
});

it('queues a big import from the screen and shows its progress, then the result', function (): void {
    Queue::fake();
    $rows = ImportStudentsJob::SYNC_MAX_ROWS + 1;

    $component = Livewire::actingAs($this->kesiswaan)->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', UploadedFile::fake()->createWithContent('data-siswa.csv', b2ImportCsv($rows)))
        ->assertSee("Impor {$rows} siswa")
        ->call('import')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast')
        ->assertSee('Menunggu antrean')
        ->assertSeeHtml('wire:poll.2s');

    $import = StudentImport::query()->sole();

    expect($import->status)->toBe(StudentImportStatus::Queued)
        ->and($import->user_id)->toBe($this->kesiswaan->getKey())
        ->and($import->file->purpose)->toBe(FilePurpose::Import)
        ->and(Student::query()->count())->toBe(0);

    Queue::assertPushed(ImportStudentsJob::class, fn (ImportStudentsJob $job): bool => $job->importId === $import->getKey() && $job->offset === 0);

    // A second big import while the first one waits is refused.
    $component->call('import')->assertHasErrors(['file']);
    expect(StudentImport::query()->count())->toBe(1);

    // Coming back to the page shows the running import.
    Livewire::actingAs($this->kesiswaan)->test(ImportSiswa::class)->assertSee('Menunggu antrean');

    (new ImportStudentsJob($import->getKey()))->handle(app(ImportStudents::class), app(StoreUpload::class));

    $component->call('$refresh')
        ->assertSee("{$rows} akun siswa dibuat")
        ->assertDontSeeHtml('wire:poll.2s')
        ->assertDontSee('sm4g4');
});

it('keeps small imports synchronous', function (): void {
    Queue::fake();

    Livewire::actingAs($this->kesiswaan)->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', UploadedFile::fake()->createWithContent('data-siswa.csv', b2ImportCsv(3)))
        ->call('import')
        ->assertSee('3 akun siswa dibuat');

    Queue::assertNothingPushed();
    expect(StudentImport::query()->count())->toBe(0)->and(Student::query()->count())->toBe(3);
});

it('shows the "Sel dibersihkan" chip and a note on the preview', function (): void {
    $csv = b2ImportCsv(1, 0, [['=Nadia Putri Anindya', '225226201', '0098765432', '02-03-2010', 'X-1', '']]);

    Livewire::actingAs($this->kesiswaan)->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', UploadedFile::fake()->createWithContent('data-siswa.csv', $csv))
        ->assertSee('Sel dibersihkan')
        ->assertSee('1 baris berisi sel yang diawali = + - @');
});

it('shows an import only to the person who started it', function (): void {
    $import = b2QueuedImport($this->kesiswaan, b2ImportCsv(1));
    $admin = F::staff(RoleName::Admin, 'Operator STH');

    expect($this->kesiswaan->can('view', $import))->toBeTrue()
        ->and($admin->can('view', $import))->toBeFalse()
        ->and($admin->can('create', StudentImport::class))->toBeTrue()
        ->and(F::staff(RoleName::Guru, 'Pak Guru Uji')->can('create', StudentImport::class))->toBeFalse();

    Livewire::actingAs($admin)->test(ImportSiswa::class)->assertDontSee('Menunggu antrean');
});
