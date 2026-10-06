<?php

declare(strict_types=1);

use App\Actions\Accounts\ImportStudents;
use App\Actions\Accounts\StudentImportReport;
use App\Domain\Accounts\InitialPassword;
use App\Domain\Files\StoreUpload;
use App\Enums\RoleName;
use App\Enums\StudentImportStatus;
use App\Enums\UserStatus;
use App\Jobs\ImportStudentsJob;
use App\Livewire\Akun\ImportSiswa;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\StudentImport;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

// M1-C (lead, 2026-10-04): the class column is the class at the moment of the upload, header "Kelas".
const W1C_IMPORT_HEADER = ['Nama lengkap', 'NIS', 'NISN', 'Tanggal lahir', 'Kelas', 'No HP'];

beforeEach(function (): void {
    H::seedRoles();
    $this->travelTo(now()->setDate(2026, 10, 4)->setTime(9, 0));

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
/** numFmtId of cell $ref in the first sheet of an .xlsx (null when the cell is not written at all). */
function w1cTemplateFormat(string $xlsx, string $ref): ?int
{
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $zip = new ZipArchive;
    $zip->open($xlsx);
    $sheet = simplexml_load_string((string) $zip->getFromName('xl/worksheets/sheet1.xml'));
    $styles = simplexml_load_string((string) $zip->getFromName('xl/styles.xml'));
    $zip->close();
    $sheet->registerXPathNamespace('m', $ns);
    $styles->registerXPathNamespace('m', $ns);

    $cell = $sheet->xpath("//m:c[@r='{$ref}']")[0] ?? null;
    $xf = $cell === null ? null : ($styles->xpath('//m:cellXfs/m:xf')[(int) $cell['s']] ?? null);

    return $xf === null ? null : (int) $xf['numFmtId'];
}

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
        ['Citra Ayu', '225226999', '0098765438', '08-08-2010', 'X-1', ''],                                 // 9 NIS registered with another NISN
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
        ->and($byRow[9]['errors'])->toBe(['NIS sudah terdaftar dengan NISN lain.'])
        ->and($byRow[10]['errors'])->toContain('Usia harus 13–21 tahun.')
        ->and($byRow[11]['errors'])->toContain('No HP tidak valid (contoh 0812…).')
        ->and($byRow[12]['errors'])->toContain('Kelas tidak ditemukan.');

    // The report never carries birth dates, phones or passwords.
    // B2 (#19): `sanitized` flags rows whose cells lost a leading = + - @ ("Sel dibersihkan").
    // M1-C: `action` (new/moved/unchanged/skipped/rejected), the class a student leaves, notes, and `changes`.
    expect(array_keys($report->rows[0]))->toBe(['row', 'name', 'nis', 'classroom', 'errors', 'grade12_start_date', 'archive_at', 'sanitized', 'action', 'previous_classroom', 'notes', 'changes'])
        ->and($byRow[4]['sanitized'])->toBeTrue()
        ->and($byRow[2]['sanitized'])->toBeFalse()
        ->and($byRow[2])->toMatchArray(['action' => StudentImportReport::NEW, 'previous_classroom' => null, 'notes' => [], 'changes' => true])
        ->and($byRow[9])->toMatchArray(['action' => StudentImportReport::REJECTED, 'changes' => false])
        ->and($report->summary())->toBe(['new' => 2, 'moved' => 0, 'unchanged' => 0, 'skipped' => 0, 'rejected' => 9])
        ->and($report->changeCount())->toBe(2);
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

// --- M1-C (lead, 2026-10-04): the yearly file lists every student with the current class --------------------

/**
 * A registered, activated student (fictional): Nadia in X-1, registered for 2026/2027
 * (kelas XII from 2028-07-01, archive 2030-01-01).
 *
 * @param  array<string, mixed>  $student  students columns; `kelas` = class name
 * @param  array<string, mixed>  $user  users columns
 */
function m1cRegistered(array $student = [], array $user = []): Student
{
    $classroom = Classroom::query()->where('name', $student['kelas'] ?? 'X-1')->firstOrFail();
    unset($student['kelas']);

    $created = Student::factory()->create([
        'nis' => '225226201',
        'nisn' => '0098765432',
        'birth_date' => '2010-03-02',
        'classroom_id' => $classroom->getKey(),
        'import_school_year' => '2026/2027',
        'photo_consent' => true,
        'publish_consent' => true,
        ...$student,
    ]);

    $created->user->forceFill([
        'name' => 'Nadia Putri Anindya',
        'email' => 'nadia.anindya@example.test',
        'email_verified_at' => now(),
        'status' => UserStatus::Active,
        'must_change_password' => false,
        'password_changed_at' => now(),
        ...$user,
    ])->save();

    return $created->refresh();
}

/** The row of the registered student of m1cRegistered(), in the given class. */
function m1cNadiaRow(string $kelas, string $phone = ''): array
{
    return ['Nadia Putri Anindya', '225226201', '0098765432', '02-03-2010', $kelas, $phone];
}

/** Everything of an account the import must never touch. */
function m1cAccount(Student $student): array
{
    $user = User::query()->findOrFail($student->user_id);

    return [
        'name' => $user->name,
        'email' => $user->email,
        'password' => $user->password,
        'status' => $user->status,
        'must_change_password' => $user->must_change_password,
        'email_verified_at' => (string) $user->email_verified_at,
        'initial_password_expires_at' => (string) $user->getAttribute('initial_password_expires_at'),
        'birth_date' => $student->refresh()->birth_date->format('Y-m-d'),
        'photo_consent' => $student->photo_consent,
        'publish_consent' => $student->publish_consent,
    ];
}

it('still reads the old class header "Kelas saat pendaftaran"', function (): void {
    $old = ['Nama lengkap', 'NIS', 'NISN', 'Tanggal lahir', 'Kelas saat pendaftaran', 'No HP'];
    $report = app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv([['Nadia Putri Anindya', '225226201', '0098765432', '02-03-2010', 'X-1', '']], $old), 'csv', '2026/2027');

    expect($report->validCount())->toBe(1)
        ->and($report->rows[0])->toMatchArray(['classroom' => 'X-1', 'action' => StudentImportReport::NEW]);
});

it('moves a registered student to the class in the file and leaves the account alone', function (): void {
    $nadia = m1cRegistered();
    $before = m1cAccount($nadia);
    $version = (int) Cache::get(PublicAchievements::VERSION_KEY, 1);

    $path = w1cCsv([
        m1cNadiaRow('XI-4'),
        ['Raka Pratama', '225226202', '0098765433', '20-11-2011', 'X-1', ''],
    ]);

    // The dry run shows what will happen and writes nothing.
    $preview = app(ImportStudents::class)->handle($this->kesiswaan, $path, 'csv', '2027/2028');

    expect($preview->summary())->toBe(['new' => 1, 'moved' => 1, 'unchanged' => 0, 'skipped' => 0, 'rejected' => 0])
        ->and($preview->rows[0])->toMatchArray([
            'action' => StudentImportReport::MOVED,
            'previous_classroom' => 'X-1',
            'classroom' => 'XI-4',
            'errors' => [],
            'notes' => [],
            'changes' => true,
            // X in 2026/2027 → XI in 2027/2028: the same kelas XII start and archive date.
            'grade12_start_date' => '2028-07-01',
            'archive_at' => '2030-01-01',
        ])
        ->and($nadia->refresh()->classroom->name)->toBe('X-1')
        ->and(Activity::query()->whereIn('event', ['student.class_changed', 'student.import'])->count())->toBe(0)
        ->and(Student::query()->where('nis', '225226202')->exists())->toBeFalse();

    $report = app(ImportStudents::class)->handle($this->kesiswaan, $path, 'csv', '2027/2028', dryRun: false);
    $nadia->refresh();

    expect($report->created)->toBe(1)
        ->and($report->moved)->toBe(1)
        ->and($nadia->classroom->name)->toBe('XI-4')
        ->and($nadia->import_school_year)->toBe('2027/2028')
        ->and($nadia->grade12_start_date->format('Y-m-d'))->toBe('2028-07-01')
        ->and($nadia->archive_at->format('Y-m-d'))->toBe('2030-01-01')
        ->and($nadia->phone)->toBeNull()
        ->and(m1cAccount($nadia))->toBe($before)
        ->and((int) Cache::get(PublicAchievements::VERSION_KEY, 1))->toBeGreaterThan($version); // public pages show classes

    $log = Activity::query()->where('event', 'student.class_changed')->sole();

    expect($log->log_name)->toBe('student')
        ->and($log->subject_id)->toBe($nadia->getKey())
        ->and($log->causer_id)->toBe($this->kesiswaan->getKey())
        ->and($log->properties['from'])->toBe(['classroom' => 'X-1', 'school_year' => '2026/2027', 'grade12_start_date' => '2028-07-01', 'archive_at' => '2030-01-01'])
        ->and($log->properties['to'])->toBe(['classroom' => 'XI-4', 'school_year' => '2027/2028', 'grade12_start_date' => '2028-07-01', 'archive_at' => '2030-01-01'])
        ->and($log->properties['phone_updated'])->toBeFalse()
        ->and($log->properties['file_sha256'])->toHaveLength(64)
        // No personal data in the audit entry: no name, no NIS/NISN, no birth date.
        ->and(json_encode($log->properties))->not->toContain('Nadia')->not->toContain('225226201')->not->toContain('0098765432')->not->toContain('2010');

    expect(Activity::query()->where('event', 'student.import')->sole()->properties->all())
        ->toMatchArray(['school_year' => '2027/2028', 'rows' => 2, 'created' => 1, 'moved' => 1, 'invalid' => 0]);
});

it('changes nothing when the same file is imported again', function (): void {
    $nadia = m1cRegistered();
    $path = w1cCsv([m1cNadiaRow('XI-4'), ['Raka Pratama', '225226202', '0098765433', '20-11-2011', 'X-1', '']]);

    app(ImportStudents::class)->handle($this->kesiswaan, $path, 'csv', '2027/2028', dryRun: false);
    $again = app(ImportStudents::class)->handle($this->kesiswaan, $path, 'csv', '2027/2028', dryRun: false);

    expect($again->summary())->toBe(['new' => 0, 'moved' => 0, 'unchanged' => 2, 'skipped' => 0, 'rejected' => 0])
        ->and($again->created)->toBe(0)
        ->and($again->moved)->toBe(0)
        ->and($again->changeCount())->toBe(0)
        ->and(array_column($again->rows, 'notes'))->toBe([[], []])
        ->and(Student::query()->count())->toBe(3) // the fixture student, Nadia, Raka
        ->and(Activity::query()->where('event', 'student.class_changed')->count())->toBe(1)
        ->and($nadia->refresh()->classroom->name)->toBe('XI-4');
});

it('corrects the archive date of a student who repeats a year', function (): void {
    $nadia = m1cRegistered();

    // Still kelas X one school year later: kelas XII starts a year later, so does the archive date.
    $report = app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv([m1cNadiaRow('X-1')]), 'csv', '2027/2028', dryRun: false);
    $nadia->refresh();

    expect($report->rows[0])->toMatchArray([
        'action' => StudentImportReport::UNCHANGED,
        'previous_classroom' => null,
        'changes' => true,
        'notes' => ['Tanggal arsip dikoreksi: Jan 2030 → Jan 2031.'],
        'grade12_start_date' => '2029-07-01',
        'archive_at' => '2031-01-01',
    ])
        ->and($report->moved)->toBe(0)
        ->and($nadia->classroom->name)->toBe('X-1')
        ->and($nadia->import_school_year)->toBe('2027/2028')
        ->and($nadia->grade12_start_date->format('Y-m-d'))->toBe('2029-07-01')
        ->and($nadia->archive_at->format('Y-m-d'))->toBe('2031-01-01');

    $log = Activity::query()->where('event', 'student.class_changed')->sole();

    expect($log->properties['from']['archive_at'])->toBe('2030-01-01')
        ->and($log->properties['to']['archive_at'])->toBe('2031-01-01');
});

it('corrects a student who was registered in the wrong class', function (): void {
    // Registered as XI-4 by mistake (kelas XII 2027, archive 2029); the right class is X-1.
    $nadia = m1cRegistered(['kelas' => 'XI-4']);

    expect($nadia->archive_at->format('Y-m-d'))->toBe('2029-01-01');

    $report = app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv([m1cNadiaRow('X-1')]), 'csv', '2026/2027', dryRun: false);
    $nadia->refresh();

    expect($report->rows[0])->toMatchArray(['action' => StudentImportReport::MOVED, 'previous_classroom' => 'XI-4', 'notes' => ['Tanggal arsip dikoreksi: Jan 2029 → Jan 2030.']])
        ->and($report->moved)->toBe(1)
        ->and($nadia->classroom->name)->toBe('X-1')
        ->and($nadia->grade12_start_date->format('Y-m-d'))->toBe('2028-07-01')
        ->and($nadia->archive_at->format('Y-m-d'))->toBe('2030-01-01');
});

it('refuses a row when only the NIS or only the NISN belongs to a registered student', function (): void {
    $nadia = m1cRegistered();

    $report = app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv([
        ['Nadia Putri Anindya', '225226201', '0098765499', '02-03-2010', 'XI-4', ''], // 2 her NIS, another NISN
        ['Nadia Putri Anindya', '225226299', '0098765432', '02-03-2010', 'XI-4', ''], // 3 another NIS, her NISN
        ['Citra Ayu', '225226999', '0098765432', '08-08-2010', 'XI-4', ''],           // 4 NIS and NISN of two students (also NISN dobel)
        ['Dimas Saputra', '0011111111', '0098765450', '06-06-2010', 'X-1', ''],       // 5 new NIS = another student's NISN
        ['Gita Permata', '225226777', '0225226999', '10-10-2010', 'X-1', ''],         // 6 unknown numbers: new
    ]), 'csv', '2027/2028', dryRun: false);

    $byRow = collect($report->rows)->keyBy('row');

    expect($byRow[2]['errors'])->toBe(['NIS sudah terdaftar dengan NISN lain.'])
        ->and($byRow[3]['errors'])->toContain('NISN sudah terdaftar dengan NIS lain.')
        ->and($byRow[4]['errors'])->toContain('NIS dan NISN ini milik dua siswa yang berbeda.')
        ->and($byRow[5]['errors'])->toBe(['NIS sudah dipakai sebagai NISN siswa lain.'])
        ->and($byRow[6]['errors'])->toBe([])
        ->and($report->summary())->toMatchArray(['new' => 1, 'moved' => 0, 'rejected' => 4])
        ->and($report->created)->toBe(1)
        ->and($nadia->refresh()->classroom->name)->toBe('X-1')
        ->and(Activity::query()->where('event', 'student.class_changed')->count())->toBe(0);
});

it('never overwrites the name or the birth date of a registered student', function (): void {
    $nadia = m1cRegistered();
    $before = m1cAccount($nadia);

    $report = app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv([
        ['Nadia P Anindya', '225226201', '0098765432', '03-03-2010', 'XI-4', ''],
    ]), 'csv', '2027/2028', dryRun: false);

    expect($report->rows[0])->toMatchArray([
        'action' => StudentImportReport::MOVED,
        'errors' => [],
        'notes' => ['Nama dan tanggal lahir berbeda dari data lama, tidak diubah.'],
    ])
        ->and($nadia->refresh()->classroom->name)->toBe('XI-4')
        ->and(m1cAccount($nadia))->toBe($before);
});

it('compares names without case and ignores blank or unreadable cells of a registered student', function (array $row, array $notes): void {
    m1cRegistered();

    $report = app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv([$row]), 'csv', '2027/2028');

    expect($report->rows[0])->toMatchArray(['action' => StudentImportReport::MOVED, 'errors' => [], 'notes' => $notes]);
})->with([
    'same name, other case and spaces' => [['NADIA  putri anindya', '225226201', '0098765432', '2010-03-02', 'XI-4', ''], []],
    'blank name and birth date' => [['', '225226201', '0098765432', '', 'XI-4', ''], []],
    'other name only' => [['Nadia Anindya', '225226201', '0098765432', '02-03-2010', 'XI-4', ''], ['Nama berbeda dari data lama, tidak diubah.']],
    'unreadable birth date' => [['Nadia Putri Anindya', '225226201', '0098765432', '31-02-2010', 'XI-4', ''], ['Tanggal lahir berbeda dari data lama, tidak diubah.']],
    'birth date outside 13–21 is not checked' => [['Nadia Putri Anindya', '225226201', '0098765432', '02-03-2020', 'XI-4', ''], ['Tanggal lahir berbeda dari data lama, tidak diubah.']],
]);

it('skips an alumni account with a note and refuses a deleted one', function (): void {
    $alumni = m1cRegistered([], ['status' => UserStatus::Archived]);
    $deleted = m1cRegistered(['nis' => '225226210', 'nisn' => '0098765440'], ['email' => 'gita.permata@example.test']);
    $deleted->delete();

    $report = app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv([
        m1cNadiaRow('XI-4', '081234567890'),
        ['Gita Permata', '225226210', '0098765440', '10-10-2010', 'XI-4', ''],
    ]), 'csv', '2027/2028', dryRun: false);

    expect($report->rows[0])->toMatchArray([
        'action' => StudentImportReport::SKIPPED,
        'errors' => [],
        'notes' => ['Akun alumni (sudah diarsipkan), dilewati.'],
        'changes' => false,
        'archive_at' => null,
    ])
        ->and($report->rows[1]['action'])->toBe(StudentImportReport::REJECTED)
        ->and($report->rows[1]['errors'])->toContain('Akun siswa ini sudah dihapus.')
        ->and($report->summary())->toBe(['new' => 0, 'moved' => 0, 'unchanged' => 0, 'skipped' => 1, 'rejected' => 1])
        ->and($report->created)->toBe(0)
        ->and($report->moved)->toBe(0)
        ->and($alumni->refresh()->classroom->name)->toBe('X-1')
        ->and($alumni->phone)->toBeNull()
        ->and($alumni->user->status)->toBe(UserStatus::Archived)
        ->and(Activity::query()->whereIn('event', ['student.class_changed', 'student.phone_updated'])->count())->toBe(0);
});

it('updates the class of a disabled account without enabling it', function (): void {
    $nadia = m1cRegistered([], ['status' => UserStatus::Disabled]);

    $report = app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv([m1cNadiaRow('XI-4')]), 'csv', '2027/2028', dryRun: false);

    expect($report->rows[0])->toMatchArray(['action' => StudentImportReport::MOVED, 'notes' => ['Akun ini nonaktif dan tetap nonaktif.']])
        ->and($nadia->refresh()->classroom->name)->toBe('XI-4')
        ->and($nadia->user->status)->toBe(UserStatus::Disabled);
});

it('stores the phone number when the file has one and keeps it when the cell is empty', function (): void {
    $nadia = m1cRegistered();

    // Same class and school year: only the phone number is new.
    $report = app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv([m1cNadiaRow('X-1', '081234567890')]), 'csv', '2026/2027', dryRun: false);

    expect($report->rows[0])->toMatchArray(['action' => StudentImportReport::UNCHANGED, 'notes' => ['No HP diperbarui.'], 'changes' => true])
        ->and($report->moved)->toBe(0)
        ->and($nadia->refresh()->phone)->toBe('081234567890');

    $log = Activity::query()->where('event', 'student.phone_updated')->sole();

    expect($log->subject_id)->toBe($nadia->getKey())
        ->and(json_encode($log->properties))->not->toContain('0812')
        ->and(json_encode($report->rows))->not->toContain('0812')
        ->and(Activity::query()->where('event', 'student.class_changed')->count())->toBe(0);

    $again = app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv([m1cNadiaRow('X-1')]), 'csv', '2026/2027', dryRun: false);

    expect($again->rows[0])->toMatchArray(['action' => StudentImportReport::UNCHANGED, 'notes' => [], 'changes' => false])
        ->and($nadia->refresh()->phone)->toBe('081234567890');
});

it('refuses an update with an unknown class, a bad phone number or an archive date that has passed', function (): void {
    Classroom::factory()->level(12)->create(['name' => 'XII-1']);
    $nadia = m1cRegistered();
    $importer = app(ImportStudents::class);

    $unknown = $importer->handle($this->kesiswaan, w1cCsv([m1cNadiaRow('XIII-9')]), 'csv', '2027/2028', dryRun: false);
    $inactive = $importer->handle($this->kesiswaan, w1cCsv([m1cNadiaRow('X-9')]), 'csv', '2027/2028', dryRun: false);
    $phone = $importer->handle($this->kesiswaan, w1cCsv([m1cNadiaRow('XI-4', '12345')]), 'csv', '2027/2028', dryRun: false);
    // Kelas XII in 2024/2025 would mean an archive date of January 2026: already gone (today is 4 Oct 2026).
    $past = $importer->handle($this->kesiswaan, w1cCsv([m1cNadiaRow('XII-1')]), 'csv', '2024/2025', dryRun: false);

    expect($unknown->rows[0]['errors'])->toBe(['Kelas tidak ditemukan.'])
        ->and($inactive->rows[0]['errors'])->toBe(['Kelas tidak ditemukan.'])
        ->and($phone->rows[0]['errors'])->toBe(['No HP tidak valid (contoh 0812…).'])
        ->and($past->rows[0]['errors'])->toBe(['Tanggal arsip dari kelas dan tahun ajaran ini sudah lewat. Periksa tahun ajaran dan kelasnya.'])
        ->and($past->rows[0])->toMatchArray(['action' => StudentImportReport::REJECTED, 'archive_at' => null, 'changes' => false])
        ->and($nadia->refresh()->classroom->name)->toBe('X-1')
        ->and($nadia->archive_at->format('Y-m-d'))->toBe('2030-01-01')
        ->and(Activity::query()->where('event', 'student.class_changed')->count())->toBe(0);
});

it('refuses two rows for the same registered student', function (): void {
    $nadia = m1cRegistered();

    $report = app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv([m1cNadiaRow('XI-4'), m1cNadiaRow('X-1')]), 'csv', '2027/2028', dryRun: false);

    expect($report->rows[0]['errors'])->toBe(['NIS dobel di file ini.', 'NISN dobel di file ini.'])
        ->and($report->summary()['rejected'])->toBe(2)
        ->and($nadia->refresh()->classroom->name)->toBe('X-1');
});

it('lets nobody without student.import move a class', function (): void {
    $nadia = m1cRegistered();
    $path = w1cCsv([m1cNadiaRow('XI-4')]);

    foreach ([RoleName::Guru, RoleName::TataUsaha, RoleName::Humas, RoleName::KepalaSekolah] as $role) {
        expect(fn () => app(ImportStudents::class)->handle(H::staff($role), $path, 'csv', '2027/2028', dryRun: false))
            ->toThrow(AuthorizationException::class);
    }

    expect(fn () => app(ImportStudents::class)->importSlice($nadia->user, $path, 'csv', '2027/2028', 0, 10))->toThrow(AuthorizationException::class)
        ->and($nadia->refresh()->classroom->name)->toBe('X-1');
});

it('moves classes through the queued job exactly like the synchronous import', function (): void {
    $nadia = m1cRegistered();
    $before = m1cAccount($nadia);
    $csv = implode("\n", [
        implode(',', W1C_IMPORT_HEADER),
        'Nadia Putri Anindya,225226201,0098765432,02-03-2010,XI-4,',
        'Raka Pratama,225226202,0098765433,20-11-2011,X-1,',
        'Salsa Maharani,225226204,009876543,05-05-2011,X-1,', // NISN 9 digits
    ])."\n";

    $file = app(StoreUpload::class)->storeSpreadsheet(UploadedFile::fake()->createWithContent('data-siswa.csv', $csv), $this->kesiswaan);
    $import = new StudentImport([
        'user_id' => $this->kesiswaan->getKey(),
        'extension' => 'csv',
        'school_year' => '2027/2028',
        'file_name' => 'data-siswa.csv',
        'file_sha256' => $file->sha256,
    ]);
    $import->forceFill(['status' => StudentImportStatus::Queued, 'file_id' => $file->getKey()])->save();

    ImportStudentsJob::dispatch($import->getKey()); // sync queue in tests
    ImportStudentsJob::dispatch($import->getKey()); // a late duplicate does nothing

    $import->refresh();
    $nadia->refresh();

    expect($import->status)->toBe(StudentImportStatus::Done)
        ->and($import->total_rows)->toBe(3)
        ->and($import->valid_rows)->toBe(2)
        ->and($import->invalid_rows)->toBe(1)
        ->and($import->created_count)->toBe(1)
        ->and($import->updated_count)->toBe(1)
        ->and($nadia->classroom->name)->toBe('XI-4')
        ->and($nadia->archive_at->format('Y-m-d'))->toBe('2030-01-01')
        ->and(m1cAccount($nadia))->toBe($before)
        ->and(Activity::query()->where('event', 'student.class_changed')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'student.import')->sole()->properties->all())
        ->toMatchArray(['rows' => 3, 'created' => 1, 'moved' => 1, 'invalid' => 1, 'file_sha256' => $import->file_sha256]);

    // A second slice run over the same rows (retry) moves nobody twice.
    $retry = app(ImportStudents::class)->importSlice($this->kesiswaan, w1cCsv([m1cNadiaRow('XI-4')]), 'csv', '2027/2028', 0, 10);

    expect($retry)->toMatchArray(['rows' => 1, 'valid' => 1, 'invalid' => 0, 'created' => 0, 'moved' => 0])
        ->and(Activity::query()->where('event', 'student.class_changed')->count())->toBe(1);
});

it('offers the template with the column "Kelas" and explains the yearly upload on the screen', function (): void {
    $this->withoutVite();

    $screen = Livewire::actingAs($this->kesiswaan)->test(ImportSiswa::class)
        ->assertSee('Isi semua siswa dari semua angkatan dengan kelasnya sekarang')
        ->assertSee('Akun dan kata sandinya tidak berubah.')
        ->assertDontSee('Kelas saat pendaftaran')
        ->assertDontSee('sm4g4')
        ->call('downloadTemplate')
        ->assertFileDownloaded('template-import-siswa.xlsx');

    $path = tempnam(sys_get_temp_dir(), 'sth-template-').'.xlsx';
    $GLOBALS['w1c_tmp_files'][] = $path;
    file_put_contents($path, base64_decode((string) data_get($screen->effects, 'download.content')));

    expect(SimpleExcelReader::create($path)->getHeaders())->toBe(W1C_IMPORT_HEADER);
});

it('formats the NIS, NISN and No HP columns of the template as text, so Excel keeps their leading zeros', function (): void {
    $this->withoutVite();

    $screen = Livewire::actingAs($this->kesiswaan)->test(ImportSiswa::class)->call('downloadTemplate');

    $path = tempnam(sys_get_temp_dir(), 'sth-template-').'.xlsx';
    $GLOBALS['w1c_tmp_files'][] = $path;
    file_put_contents($path, base64_decode((string) data_get($screen->effects, 'download.content')));

    // numFmtId 49 is Excel's built-in "@" (Text) format (ECMA-376 §18.8.30).
    foreach (['B2', 'C2', 'F2', 'B3001', 'C3001', 'F3001'] as $cell) {
        expect(w1cTemplateFormat($path, $cell))->toBe(49, $cell);
    }

    // Name, birth date and class keep Excel's default; the header row is plain text.
    expect(w1cTemplateFormat($path, 'A2'))->not->toBe(49)
        ->and(w1cTemplateFormat($path, 'D2'))->not->toBe(49)
        ->and(w1cTemplateFormat($path, 'E2'))->not->toBe(49)
        // The formatted but empty rows are no data: the untouched template reads as a file without students.
        ->and(app(ImportStudents::class)->handle($this->kesiswaan, $path, 'xlsx', '2026/2027')->total())->toBe(0);
});

it('previews baru, pindah kelas, tidak berubah and ditolak, then imports without touching a password', function (): void {
    $this->withoutVite();
    $nadia = m1cRegistered();
    $gita = m1cRegistered(['nis' => '225226210', 'nisn' => '0098765440', 'birth_date' => '2010-10-10'], ['name' => 'Gita Permata', 'email' => 'gita.permata@example.test']);
    $before = m1cAccount($nadia);

    $csv = implode("\n", [
        implode(';', W1C_IMPORT_HEADER),
        'Nadia Putri Anindya;225226201;0098765432;02-03-2010;XI-4;',  // moved (and the dates follow the class)
        'Gita Permata;225226210;0098765440;10-10-2010;X-1;',           // unchanged
        'Raka Pratama;225226202;0098765433;20-11-2011;X-1;',           // new
        'Galang Putra Mahendra;225226204;0098765435;31-02-2011;X-1;',  // rejected
    ])."\n";

    $screen = Livewire::actingAs($this->kesiswaan)->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', UploadedFile::fake()->createWithContent('data-siswa.csv', $csv))
        ->assertHasNoErrors()
        ->assertViewHas('summary', ['new' => 1, 'moved' => 1, 'unchanged' => 1, 'skipped' => 0, 'rejected' => 1])
        ->assertViewHas('changeCount', 2)
        ->assertSee('Siswa baru')
        ->assertSee('Pindah kelas')
        ->assertSee('Tidak berubah')
        ->assertSee('Ditolak')
        ->assertSee('X-1 → XI-4')
        ->assertSee('Tanggal arsip dikoreksi: Jan 2030 → Jan 2029.')
        // Many of these would mean a wrong school year: the screen says how many archive dates move.
        ->assertViewHas('archiveCorrections', 1)
        ->assertSee('Tanggal arsip 1 siswa lama akan berubah')
        ->assertSee('Tanggal lahir tidak valid')
        ->assertSee('Impor 2 siswa')
        ->assertSee('Buat 1 akun siswa dan pindahkan 1 siswa ke kelas di file sekarang?')
        ->assertDontSee('sm4g4');

    expect($nadia->refresh()->classroom->name)->toBe('X-1')
        ->and(Student::query()->where('nis', '225226202')->exists())->toBeFalse();

    // Rows with a note can be listed on their own.
    $screen->set('filter', 'notes')
        ->assertViewHas('listTotal', 1)
        ->assertSee('Nadia Putri Anindya')
        ->assertDontSee('Raka Pratama')
        ->set('filter', 'bad')
        ->assertViewHas('listTotal', 1)
        ->assertSee('Galang Putra Mahendra')
        ->set('filter', 'all');

    $screen->call('import')
        ->assertHasNoErrors()
        ->assertSee('1 akun siswa dibuat, 1 siswa pindah kelas')
        ->assertSee('1 baris ditolak dan belum diimpor.')
        ->assertDontSee('sm4g4');

    expect($nadia->refresh()->classroom->name)->toBe('XI-4')
        ->and(m1cAccount($nadia))->toBe($before)
        ->and($gita->refresh()->classroom->name)->toBe('X-1')
        ->and(Student::query()->where('nis', '225226202')->exists())->toBeTrue()
        ->and(Student::query()->where('nis', '225226204')->exists())->toBeFalse();
});

it('queues the yearly file by the number of rows to write, not by the rows that stay the same', function (): void {
    $this->withoutVite();
    Queue::fake();

    // More registered students than the synchronous limit, all already in X-1: nothing to write, nothing queued.
    $lines = [implode(',', W1C_IMPORT_HEADER)];
    $kelas = Classroom::query()->where('name', 'X-1')->value('id');

    foreach (range(1, ImportStudentsJob::SYNC_MAX_ROWS + 10) as $i) {
        $student = Student::factory()->create(['nis' => sprintf('2252%05d', $i), 'nisn' => sprintf('00%08d', $i), 'classroom_id' => $kelas]);
        $lines[] = sprintf('Siswa Uji,%s,%s,,X-1,', $student->nis, $student->nisn);
    }

    Livewire::actingAs($this->kesiswaan)->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', UploadedFile::fake()->createWithContent('data-siswa.csv', implode("\n", $lines)."\n"))
        ->assertViewHas('changeCount', 0)
        ->assertSee('Impor 0 siswa')
        ->call('import')
        ->assertHasNoErrors();

    Queue::assertNothingPushed();
    expect(StudentImport::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'student.class_changed')->count())->toBe(0);
});

it('shows the students who moved class in the progress of a queued import', function (): void {
    $this->withoutVite();

    $import = new StudentImport([
        'user_id' => $this->kesiswaan->getKey(),
        'extension' => 'csv',
        'school_year' => '2027/2028',
        'file_name' => 'data-siswa.csv',
        'file_sha256' => str_repeat('a', 64),
    ]);
    $import->forceFill(['status' => StudentImportStatus::Running, 'total_rows' => 300, 'processed_rows' => 100, 'invalid_rows' => 2, 'created_count' => 40, 'updated_count' => 55])->save();

    $screen = Livewire::actingAs($this->kesiswaan)->test(ImportSiswa::class)
        ->assertSee('100 dari 300 baris diproses')
        ->assertSee('40 akun siswa dibuat, 55 siswa pindah kelas');

    // The same screen keeps polling the row until it is done.
    $import->forceFill(['status' => StudentImportStatus::Done, 'processed_rows' => 300, 'created_count' => 120, 'updated_count' => 170])->save();

    $screen->call('$refresh')
        ->assertSee('120 akun siswa dibuat, 170 siswa pindah kelas')
        ->assertSee('2 baris ditolak dan tidak diimpor.')
        ->assertDontSeeHtml('wire:poll.2s')
        ->assertDontSee('sm4g4');
});
