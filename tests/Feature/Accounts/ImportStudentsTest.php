<?php

declare(strict_types=1);

use App\Actions\Accounts\ImportStudents;
use App\Domain\Accounts\InitialPassword;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

const W1C_IMPORT_HEADER = ['Nama lengkap', 'NIS', 'NISN', 'Tanggal lahir', 'Kelas saat pendaftaran', 'No HP'];

beforeEach(function (): void {
    H::seedRoles();

    Classroom::factory()->create(['name' => 'X-1', 'level' => 10]);
    Classroom::factory()->level(11)->create(['name' => 'XI-4']);
    Classroom::factory()->create(['name' => 'X-9', 'level' => 10, 'active' => false]);

    // An existing student whose NIS/NISN are already taken (fictional).
    Student::factory()->create(['nis' => '225226999', 'nisn' => '0011111111']);

    $this->kesiswaan = H::staff(RoleName::Kesiswaan);
    $GLOBALS['w1c_tmp_files'] = [];
});

afterEach(function (): void {
    foreach ($GLOBALS['w1c_tmp_files'] ?? [] as $file) {
        @unlink($file);
    }
});

/** Writes a CSV fixture (fictional data) and returns its path. */
function w1cCsv(array $rows, array $header = W1C_IMPORT_HEADER, string $delimiter = ','): string
{
    $path = tempnam(sys_get_temp_dir(), 'sth-import-');
    $handle = fopen($path, 'wb');
    fputcsv($handle, $header, $delimiter, '"', '');

    foreach ($rows as $row) {
        fputcsv($handle, $row, $delimiter, '"', '');
    }

    fclose($handle);
    $GLOBALS['w1c_tmp_files'][] = $path;

    return $path;
}

function w1cMixedRows(): array
{
    return [
        ['Nadia Putri Anindya', '225226201', '0098765432', '02-03-2010', 'X-1', '081234567890'],        // 2 valid
        ['Raka Pratama', '225226202', '0098765433', '2009-11-20', 'xi-4', '+6281298765432'],            // 3 valid
        ['=HYPERLINK("http://evil.example","klik")', '225226203', '0098765434', '01-01-2010', 'X-1', ''], // 4 formula
        ['Salsa Maharani', '225226204', '009876543', '05-05-2010', 'X-1', ''],                            // 5 NISN 9 digits
        ['Dimas Saputra', '225226204', '0098765435', '06-06-2010', 'X-1', ''],                             // 6 same NIS as row 5
        ['Intan Lestari', '225226206', '0098765436', '07-07-2010', 'XIII-9', ''],                          // 7 unknown class
        ['Bayu Nugroho', '225226207', '0098765437', '31-02-2010', 'X-1', ''],                              // 8 impossible date
        ['Citra Ayu', '225226999', '0098765438', '08-08-2010', 'X-1', ''],                                 // 9 NIS already registered
        ['Fajar Hidayat', '225226209', '0098765439', '09-09-2020', 'X-1', ''],                             // 10 too young
        ['Gita Permata', '225226210', '0098765440', '10-10-2010', 'X-1', '12345'],                         // 11 bad phone
        ['Hana Safitri', '225226211', '0098765441', '11-11-2010', 'X-9', ''],                              // 12 inactive class
        ['', '', '', '', '', ''],                                                                            // blank, skipped
    ];
}

it('reports valid and invalid rows in a dry run without creating anything', function (): void {
    $report = app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv(w1cMixedRows()), 'csv', '2026/2027');

    expect($report->dryRun)->toBeTrue()
        ->and($report->created)->toBe(0)
        ->and($report->total())->toBe(11)
        ->and($report->validCount())->toBe(2)
        ->and($report->invalidCount())->toBe(9)
        ->and(User::query()->count())->toBe(2); // existing student + kesiswaan only

    $byRow = collect($report->rows)->keyBy('row');

    expect($byRow[2]['errors'])->toBe([])
        ->and($byRow[2]['grade12_start_date'])->toBe('2028-07-01')
        ->and($byRow[2]['archive_at'])->toBe('2030-01-01')
        ->and($byRow[3]['classroom'])->toBe('XI-4')
        ->and($byRow[3]['grade12_start_date'])->toBe('2027-07-01')
        ->and($byRow[3]['archive_at'])->toBe('2029-01-01')
        ->and($byRow[4]['errors'])->toContain('Nama lengkap hanya boleh berisi huruf.')
        ->and($byRow[4]['name'])->not->toStartWith('=')
        ->and($byRow[5]['errors'])->toContain('NISN harus 10 angka (format sel sebagai teks).')
        ->and($byRow[6]['errors'])->toContain('NIS dobel di file ini.')
        ->and($byRow[7]['errors'])->toContain('Kelas tidak ditemukan.')
        ->and($byRow[8]['errors'])->toContain('Tanggal lahir tidak valid (pakai DD-MM-YYYY).')
        ->and($byRow[9]['errors'])->toContain('NIS sudah terdaftar.')
        ->and($byRow[10]['errors'])->toContain('Usia harus 13–21 tahun.')
        ->and($byRow[11]['errors'])->toContain('No HP tidak valid (contoh 0812…).')
        ->and($byRow[12]['errors'])->toContain('Kelas tidak ditemukan.');

    // The report never carries birth dates, phones or passwords.
    expect(array_keys($report->rows[0]))->toBe(['row', 'name', 'nis', 'classroom', 'errors', 'grade12_start_date', 'archive_at']);
});

it('creates only the valid rows as pending students with a hashed initial password', function (): void {
    // Rows 5 and 6 share a NIS: both are rejected so the file owner fixes the duplicate.
    $rows = w1cMixedRows();

    $report = app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv($rows), 'csv', '2026/2027', dryRun: false);

    expect($report->created)->toBe(2);

    $nadia = Student::query()->where('nis', '225226201')->firstOrFail();
    $user = $nadia->user;

    expect($user->name)->toBe('Nadia Putri Anindya')
        ->and($user->status)->toBe(UserStatus::PendingActivation)
        ->and($user->must_change_password)->toBeTrue()
        ->and($user->email)->toBeNull()
        ->and($user->hasRole('siswa'))->toBeTrue()
        ->and($user->password)->not->toContain(app(InitialPassword::class)->make('2010-03-02'))
        ->and(Hash::check(app(InitialPassword::class)->make('2010-03-02'), $user->password))->toBeTrue()
        ->and($nadia->nisn)->toBe('0098765432')
        ->and($nadia->birth_date->format('Y-m-d'))->toBe('2010-03-02')
        ->and($nadia->import_school_year)->toBe('2026/2027')
        ->and($nadia->grade12_start_date->format('Y-m-d'))->toBe('2028-07-01')
        ->and($nadia->archive_at->format('Y-m-d'))->toBe('2030-01-01')
        ->and($nadia->phone)->toBe('081234567890');

    expect(Student::query()->where('nis', '225226202')->firstOrFail()->phone)->toBe('+6281298765432');

    $log = Activity::query()->where('event', 'student.import')->firstOrFail();
    expect($log->properties['created'])->toBe(2)
        ->and($log->properties['file_sha256'])->toHaveLength(64)
        ->and($log->causer_id)->toBe($this->kesiswaan->getKey());

    // The student can now log in with the NISN and lands on activation.
    $this->from('/login')->post('/login', ['identifier' => '0098765432', 'password' => app(InitialPassword::class)->make('2010-03-02')])
        ->assertRedirect(route('aktivasi'));
});

it('strips formula trigger characters from text cells', function (): void {
    $report = app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv([
        ['@Nadia Putri Anindya', '=225226201', '+0098765432', '02-03-2010', '-X-1', ''],
    ]), 'csv', '2026/2027', dryRun: false);

    expect($report->validCount())->toBe(1);
    expect(Student::query()->where('nis', '225226201')->firstOrFail()->user->name)->toBe('Nadia Putri Anindya');
});

it('reads semicolon CSV exports and xlsx files', function (): void {
    $csv = w1cCsv([['Nadia Putri Anindya', '225226201', '0098765432', '02/03/2010', 'X-1', '']], W1C_IMPORT_HEADER, ';');
    expect(app(ImportStudents::class)->handle($this->kesiswaan, $csv, 'csv', '2026/2027')->validCount())->toBe(1);

    $xlsx = tempnam(sys_get_temp_dir(), 'sth-import-').'.xlsx';
    $GLOBALS['w1c_tmp_files'][] = $xlsx;
    SimpleExcelWriter::create($xlsx)
        ->addHeader(W1C_IMPORT_HEADER)
        ->addRow(['Nadia Putri Anindya', '225226201', '0098765432', '02-03-2010', 'X-1', '081234567890'])
        ->close();

    $report = app(ImportStudents::class)->handle($this->kesiswaan, $xlsx, 'xlsx', '2026/2027');
    expect($report->validCount())->toBe(1)->and($report->invalidCount())->toBe(0);
});

it('rejects a file without the required columns', function (): void {
    app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv([['Nadia', '225226201']], ['Nama', 'NIS']), 'csv', '2026/2027');
})->throws(ValidationException::class);

it('rejects other file types and a bad school year', function (string $extension, string $year): void {
    app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv(w1cMixedRows()), $extension, $year);
})->with([
    ['xls', '2026/2027'],
    ['csv', '2026-2027'],
])->throws(ValidationException::class);

it('allows Admin and Kesiswaan only', function (): void {
    $path = w1cCsv(w1cMixedRows());

    expect(app(ImportStudents::class)->handle(H::staff(RoleName::Admin), $path, 'csv', '2026/2027')->validCount())->toBe(2);

    expect(fn () => app(ImportStudents::class)->handle(H::staff(RoleName::Guru), $path, 'csv', '2026/2027'))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ImportStudents::class)->handle(H::staff(RoleName::TataUsaha), $path, 'csv', '2026/2027'))
        ->toThrow(AuthorizationException::class);
});
