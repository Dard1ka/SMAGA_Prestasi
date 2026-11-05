<?php

declare(strict_types=1);

use App\Actions\Accounts\ImportStudents;
use App\Actions\Accounts\StudentImportReport;
use App\Domain\Accounts\InitialPassword;
use App\Domain\Files\StoreUpload;
use App\Enums\RoleName;
use App\Enums\SettingKey;
use App\Enums\StudentImportStatus;
use App\Enums\UserStatus;
use App\Jobs\ImportStudentsJob;
use App\Livewire\Akun\ImportSiswa;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Classroom;
use App\Models\Setting;
use App\Models\Student;
use App\Models\StudentImport;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use Spatie\Activitylog\Models\Activity;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

// M1-C (lead, 2026-10-04): the class column is the class at the moment of the upload, header "Kelas". SV3 (lead,
// 2026-11-03): the template marks every column but No HP as required.
const W1C_IMPORT_HEADER = ['Nama lengkap (wajib diisi)', 'NIS (wajib diisi)', 'NISN (wajib diisi)', 'Tanggal lahir (wajib diisi)', 'Kelas (wajib diisi)', 'No HP (opsional)'];

// The header of every template before SV3: files made with it stay accepted.
const W1C_OLD_IMPORT_HEADER = ['Nama lengkap', 'NIS', 'NISN', 'Tanggal lahir', 'Kelas', 'No HP'];

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
    // SV6, D-184: `matches` = the users.id of the registered student(s) a row matches, for the preview cache only.
    expect(array_keys($report->rows[0]))->toBe(['row', 'name', 'nis', 'nisn', 'classroom', 'errors', 'grade12_start_date', 'archive_at', 'sanitized', 'action', 'previous_classroom', 'notes', 'changes', 'matches'])
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
        // D-173 (smaga_server): "SMAGA_" + the birth date of the row (02-03-2010), not the configured value of D-138.
        ->and($user->password)->not->toContain(InitialPassword::forBirthDate('2010-03-02'))
        ->and(Hash::check(InitialPassword::forBirthDate('2010-03-02'), $user->password))->toBeTrue()
        ->and(Hash::check(app(InitialPassword::class)->make(), $user->password))->toBeFalse()
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

    // D-173: the configured value of D-138 does not open a new account; the birth-date password does, and the student
    // lands on the activation with the NISN.
    $this->from('/login')->post('/login', ['identifier' => '0098765432', 'password' => app(InitialPassword::class)->make()])
        ->assertSessionHasErrors('identifier');
    $this->assertGuest();

    $this->from('/login')->post('/login', ['identifier' => '0098765432', 'password' => InitialPassword::forBirthDate('2010-03-02')])
        ->assertRedirect(route('aktivasi'));
    $this->assertAuthenticatedAs($user);
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
        ['Dimas Saputra', '0011111111', '0098765450', '06-06-2010', 'X-1', ''],       // 5 new NIS = another student's NISN: accepted since D-137
        ['Gita Permata', '225226777', '0225226999', '10-10-2010', 'X-1', ''],         // 6 unknown numbers: new
    ]), 'csv', '2027/2028', dryRun: false);

    $byRow = collect($report->rows)->keyBy('row');

    expect($byRow[2]['errors'])->toBe(['NIS sudah terdaftar dengan NISN lain.'])
        ->and($byRow[3]['errors'])->toContain('NISN sudah terdaftar dengan NIS lain.')
        ->and($byRow[4]['errors'])->toContain('NIS dan NISN ini milik dua siswa yang berbeda.')
        ->and($byRow[5]['errors'])->toBe([])
        ->and($byRow[6]['errors'])->toBe([])
        ->and($report->summary())->toMatchArray(['new' => 2, 'moved' => 0, 'rejected' => 3])
        ->and($report->created)->toBe(2)
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

it('skips an alumni account and a student in the trash, each with its note', function (): void {
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
        // The lead's decision of 5 Nov 2026: a soft-deleted student is in the trash of Admin › Kelas; the row is skipped,
        // never a second account and never a silent restore.
        ->and($report->rows[1]['action'])->toBe(StudentImportReport::SKIPPED)
        ->and($report->rows[1]['errors'])->toBe([])
        ->and($report->rows[1]['notes'])->toBe([ImportStudents::NOTE_IN_TRASH])
        ->and($report->summary())->toBe(['new' => 0, 'moved' => 0, 'unchanged' => 0, 'skipped' => 2, 'rejected' => 0])
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

    $screen = Livewire::actingAs($this->kesiswaan)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)
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

it('formats the NIS, NISN, Tanggal lahir and No HP columns of the template as text, so Excel keeps them as typed', function (): void {
    $this->withoutVite();

    $screen = Livewire::actingAs($this->kesiswaan)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)
        ->assertSee('Format kolom NIS, NISN, dan Tanggal lahir sebagai teks.')
        ->assertSee('Kolom selain No HP wajib diisi. Baris contoh di bawah judul boleh ditimpa atau dibiarkan.')
        ->call('downloadTemplate');

    $path = tempnam(sys_get_temp_dir(), 'sth-template-').'.xlsx';
    $GLOBALS['w1c_tmp_files'][] = $path;
    file_put_contents($path, base64_decode((string) data_get($screen->effects, 'download.content')));

    // numFmtId 49 is Excel's built-in "@" (Text) format (ECMA-376 §18.8.30). SV2 review: the birth date (D) too, since
    // D-173 makes it the initial password and a General cell lets a US date setting read 01-02-2010 as 2 Jan 2010.
    foreach (['B2', 'C2', 'D2', 'F2', 'B3001', 'C3001', 'D3001', 'F3001'] as $cell) {
        expect(w1cTemplateFormat($path, $cell))->toBe(49, $cell);
    }

    // Name and class keep Excel's default; the header row is plain text.
    expect(w1cTemplateFormat($path, 'A2'))->not->toBe(49)
        ->and(w1cTemplateFormat($path, 'E2'))->not->toBe(49)
        // The formatted but empty rows are no data: the untouched template reads as a file without students.
        ->and(app(ImportStudents::class)->handle($this->kesiswaan, $path, 'xlsx', '2026/2027')->total())->toBe(0);
});

it('previews baru, pindah kelas, tidak berubah and ditolak, then imports without touching a password', function (): void {
    $this->withoutVite();
    session(['auth.password_confirmed_at' => time()]); // QA9: the import needs a recent password confirmation
    $nadia = m1cRegistered();
    // SV6, D-184: the file of kelas XI; Gita is already in XI-4 (the class and dates of the file).
    $gita = m1cRegistered(['nis' => '225226210', 'nisn' => '0098765440', 'birth_date' => '2010-10-10', 'kelas' => 'XI-4'], ['name' => 'Gita Permata', 'email' => 'gita.permata@example.test']);
    $before = m1cAccount($nadia);

    $csv = implode("\n", [
        implode(';', W1C_IMPORT_HEADER),
        'Nadia Putri Anindya;225226201;0098765432;02-03-2010;XI-4;',  // moved (and the dates follow the class)
        'Gita Permata;225226210;0098765440;10-10-2010;XI-4;',          // unchanged
        'Raka Pratama;225226202;0098765433;20-11-2011;XI-4;',          // new
        'Galang Putra Mahendra;225226204;0098765435;31-02-2011;XI-4;', // rejected
    ])."\n";

    $screen = Livewire::actingAs($this->kesiswaan)->withQueryParams([ImportSiswa::LEVEL_QUERY => '11'])->test(ImportSiswa::class)
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

    // D-128: "Impor N siswa" asks in its own dialog, whose sentence says what the import writes (no browser confirm).
    $screen->call('askImport')
        ->assertDispatched('sth-modal-open', id: 'impor-siswa')
        ->assertSee('Buat 1 akun siswa dan pindahkan 1 siswa ke kelas di file.')
        ->assertDontSee('sekarang?');

    expect($nadia->refresh()->classroom->name)->toBe('X-1');

    $screen->call('import')
        ->assertHasNoErrors()
        ->assertSee('1 akun siswa dibuat, 1 siswa pindah kelas')
        ->assertSee('1 baris ditolak dan belum diimpor.')
        ->assertDontSee('sm4g4');

    expect($nadia->refresh()->classroom->name)->toBe('XI-4')
        ->and(m1cAccount($nadia))->toBe($before)
        ->and($gita->refresh()->classroom->name)->toBe('XI-4')
        ->and(Student::query()->where('nis', '225226202')->exists())->toBeTrue()
        ->and(Student::query()->where('nis', '225226204')->exists())->toBeFalse();
});

it('queues the yearly file by the number of rows to write, not by the rows that stay the same', function (): void {
    $this->withoutVite();
    Queue::fake();
    session(['auth.password_confirmed_at' => time()]); // QA9: the import needs a recent password confirmation

    // More registered students than the synchronous limit, all already in X-1: nothing to write, nothing queued.
    $lines = [implode(',', W1C_IMPORT_HEADER)];
    $kelas = Classroom::query()->where('name', 'X-1')->value('id');

    foreach (range(1, ImportStudentsJob::SYNC_MAX_ROWS + 10) as $i) {
        $student = Student::factory()->create(['nis' => sprintf('2252%05d', $i), 'nisn' => sprintf('00%08d', $i), 'classroom_id' => $kelas]);
        $lines[] = sprintf('Siswa Uji,%s,%s,,X-1,', $student->nis, $student->nisn);
    }

    Livewire::actingAs($this->kesiswaan)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', UploadedFile::fake()->createWithContent('data-siswa.csv', implode("\n", $lines)."\n"))
        ->assertViewHas('changeCount', 0)
        ->assertSee('Impor 0 siswa')
        // D-128: the question first; the import then ran here, in this request, and wrote nothing.
        ->call('askImport')
        ->call('import')
        ->assertHasNoErrors()
        // SV1: the summary also counts the corrections (archive dates or No HP of students who stay in the class).
        ->assertSet('imported', ['created' => 0, 'moved' => 0, 'corrected' => 0, 'invalid' => 0]);

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

    $screen = Livewire::actingAs($this->kesiswaan)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)
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

// QA8: explore round after PR 17 (2026-10-08) -------------------------------------------------------

it('names the archive months the Admin set, not a fixed 18 (QA8)', function (): void {
    $this->withoutVite();
    Setting::put(SettingKey::ArchiveMonthsAfterGrade12, 12);
    $csv = implode("\n", [implode(',', W1C_IMPORT_HEADER), 'Raka Pratama,225226202,0098765433,20-11-2011,X-1,'])."\n";

    Livewire::actingAs($this->kesiswaan)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', UploadedFile::fake()->createWithContent('data-siswa.csv', $csv))
        ->assertHasNoErrors()
        ->assertSee('Arsip otomatis = 12 bulan setelah awal kelas XII.')
        ->assertDontSee('18 bulan');
});

// D-173 (smaga_server, the lead's request of 2 Nov 2026): a new student's initial password is "SMAGA_" + the birth date
// of the row as ddmmyyyy; the configured value of D-138 (and the D-24 pattern) is never hashed for a new account any
// more. Expected values are built here with InitialPassword::forBirthDate() and only compared through Hash::check(),
// so a failure never prints a password.

it('hashes SMAGA_ + the birth date for a new student, never the configured value or the D-24 pattern (D-173)', function (): void {
    config(['sth.initial_student_password' => 'Awal-Uji-7319']);

    app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv([w1cMixedRows()[0]]), 'csv', '2026/2027', dryRun: false);

    $user = Student::query()->where('nisn', '0098765432')->sole()->user;
    expect(Hash::check('SMAGA_02032010', $user->password))->toBeTrue()
        ->and(Hash::check('Awal-Uji-7319', $user->password))->toBeFalse()
        ->and(Hash::check('sm4g4_020310', $user->password))->toBeFalse()
        ->and(Hash::check('smaga_02032010', $user->password))->toBeFalse()
        ->and($user->must_change_password)->toBeTrue();
});

it('builds the password from every accepted date format of a CSV (D-173)', function (): void {
    $rows = [
        // [NIS, NISN, the cell as typed, the calendar day]
        ['226227101', '0101234101', '01-02-2010', '2010-02-01'],
        ['226227102', '0101234102', '3-4-2010', '2010-04-03'],
        ['226227103', '0101234103', '05/06/2010', '2010-06-05'],
        ['226227104', '0101234104', '7/8/2010', '2010-08-07'],
        ['226227105', '0101234105', '09.10.2010', '2010-10-09'],
        ['226227106', '0101234106', '2010-11-12', '2010-11-12'],
    ];

    $report = app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv(array_map(
        fn (array $r): array => ['Siswa Uji', $r[0], $r[1], $r[2], 'X-1', ''],
        $rows,
    )), 'csv', '2026/2027', dryRun: false);

    expect($report->created)->toBe(count($rows));

    foreach ($rows as [$nis, $nisn, $typed, $day]) {
        $student = Student::query()->where('nisn', $nisn)->sole();
        $hash = $student->user->password;

        expect($student->birth_date->format('Y-m-d'))->toBe($day)
            ->and(Hash::check(InitialPassword::forBirthDate($day), $hash))->toBeTrue("row {$nis}: birth-date password")
            ->and(Hash::check(app(InitialPassword::class)->make(), $hash))->toBeFalse("row {$nis}: configured value");
    }
});

it('builds the password from an Excel date cell and from a plain Excel serial number (D-173)', function (): void {
    $xlsx = tempnam(sys_get_temp_dir(), 'sth-import-').'.xlsx';
    $GLOBALS['w1c_tmp_files'][] = $xlsx;
    // 1 Feb 2010 as the serial Excel stores (days since 30 Dec 1899), computed here.
    $serial = (int) (new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC')))
        ->diff(new DateTimeImmutable('2010-02-01', new DateTimeZone('UTC')))->days;

    SimpleExcelWriter::create($xlsx)
        ->addHeader(W1C_IMPORT_HEADER)
        // A date cell as Excel keeps a typed date: a serial with a date format, which the reader gives back as a DateTime.
        ->addRow(Row::fromValuesWithStyles(
            ['Siswa Tanggal', '226227111', '0101234111', new DateTimeImmutable('2010-03-04 00:00:00', new DateTimeZone('Asia/Jakarta')), 'X-1', ''],
            null,
            [3 => (new Style)->setFormat('dd-mm-yyyy')],
        ))
        // A plain number cell holding the serial (no date format): the reader gives the number.
        ->addRow(['Siswa Serial', '226227112', '0101234112', $serial, 'X-1', ''])
        ->close();

    // Both paths of the reader are really taken.
    $cells = collect(SimpleExcelReader::create($xlsx)->getRows()->all())->pluck(W1C_IMPORT_HEADER[3]);
    expect($cells[0])->toBeInstanceOf(DateTimeInterface::class)
        ->and($cells[1])->toBe($serial);

    $report = app(ImportStudents::class)->handle($this->kesiswaan, $xlsx, 'xlsx', '2026/2027', dryRun: false);

    expect($report->created)->toBe(2);

    $dateCell = Student::query()->where('nisn', '0101234111')->sole();
    $serialCell = Student::query()->where('nisn', '0101234112')->sole();

    expect($dateCell->birth_date->format('Y-m-d'))->toBe('2010-03-04')
        ->and(Hash::check(InitialPassword::forBirthDate('2010-03-04'), $dateCell->user->password))->toBeTrue()
        ->and(Hash::check(app(InitialPassword::class)->make(), $dateCell->user->password))->toBeFalse()
        ->and($serialCell->birth_date->format('Y-m-d'))->toBe('2010-02-01')
        ->and(Hash::check('SMAGA_01022010', $serialCell->user->password))->toBeTrue()
        ->and(Hash::check(app(InitialPassword::class)->make(), $serialCell->user->password))->toBeFalse();
});

it('reads a date typed into the Text column of the template as DD-MM-YYYY, day first (SV2 review, D-173)', function (): void {
    $xlsx = tempnam(sys_get_temp_dir(), 'sth-import-').'.xlsx';
    $GLOBALS['w1c_tmp_files'][] = $xlsx;

    // What Excel keeps when 01-02-2010 is typed into the template's "@" cell: the text, whatever the PC's date setting.
    SimpleExcelWriter::create($xlsx)
        ->addHeader(W1C_IMPORT_HEADER)
        ->addRow(Row::fromValuesWithStyles(
            ['Siswa Teks', '226227113', '0101234113', '01-02-2010', 'X-1', ''],
            null,
            [3 => (new Style)->setFormat('@')],
        ))
        ->close();

    expect(collect(SimpleExcelReader::create($xlsx)->getRows()->all())->pluck(W1C_IMPORT_HEADER[3])->all())->toBe(['01-02-2010']);

    app(ImportStudents::class)->handle($this->kesiswaan, $xlsx, 'xlsx', '2026/2027', dryRun: false);

    $student = Student::query()->where('nisn', '0101234113')->sole();

    expect($student->birth_date->format('Y-m-d'))->toBe('2010-02-01')
        ->and(Hash::check('SMAGA_01022010', $student->user->password))->toBeTrue()
        ->and(Hash::check('SMAGA_02012010', $student->user->password))->toBeFalse();
});

it('gives two new students with one birth date the same password (accepted by the lead, D-173)', function (): void {
    app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv([
        ['Siswa Kembar Satu', '226227121', '0101234121', '01-02-2010', 'X-1', ''],
        ['Siswa Kembar Dua', '226227122', '0101234122', '1-2-2010', 'xi-4', ''],
    ]), 'csv', '2026/2027', dryRun: false);

    $first = Student::query()->where('nisn', '0101234121')->sole()->user->password;
    $second = Student::query()->where('nisn', '0101234122')->sole()->user->password;

    // Two salted hashes of one password.
    expect($first)->not->toBe($second)
        ->and(Hash::check('SMAGA_01022010', $first))->toBeTrue()
        ->and(Hash::check('SMAGA_01022010', $second))->toBeTrue();
});

it('never touches the password of a registered student on a re-import (D-173)', function (): void {
    // A registered student who has not activated and still holds the configured value of D-138.
    $nadia = m1cRegistered([], [
        'email' => null,
        'email_verified_at' => null,
        'status' => UserStatus::PendingActivation,
        'must_change_password' => true,
        'password_changed_at' => null,
        'password' => Hash::make(app(InitialPassword::class)->make()),
    ]);
    $before = m1cAccount($nadia);

    app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv([m1cNadiaRow('X-1', '081234567890')]), 'csv', '2026/2027', dryRun: false);

    $hash = User::query()->findOrFail($nadia->user_id)->password;
    expect(m1cAccount($nadia))->toBe($before)
        ->and(Hash::check(app(InitialPassword::class)->make(), $hash))->toBeTrue()
        ->and(Hash::check(InitialPassword::forBirthDate('2010-03-02'), $hash))->toBeFalse();
});

it('builds the birth-date password through the queued job too (D-173)', function (): void {
    $csv = implode("\n", [
        implode(',', W1C_IMPORT_HEADER),
        'Raka Pratama,225226202,0098765433,20-11-2011,X-1,',
        'Salsa Maharani,225226204,0098765434,2010-05-05,X-1,',
    ])."\n";

    $file = app(StoreUpload::class)->storeSpreadsheet(UploadedFile::fake()->createWithContent('data-siswa.csv', $csv), $this->kesiswaan);
    $import = new StudentImport([
        'user_id' => $this->kesiswaan->getKey(),
        'extension' => 'csv',
        'school_year' => '2026/2027',
        'file_name' => 'data-siswa.csv',
        'file_sha256' => $file->sha256,
    ]);
    $import->forceFill(['status' => StudentImportStatus::Queued, 'file_id' => $file->getKey()])->save();

    ImportStudentsJob::dispatch($import->getKey()); // sync queue in tests

    expect($import->refresh()->created_count)->toBe(2);

    $raka = Student::query()->where('nisn', '0098765433')->sole()->user->password;
    $salsa = Student::query()->where('nisn', '0098765434')->sole()->user->password;

    expect(Hash::check('SMAGA_20112011', $raka))->toBeTrue()
        ->and(Hash::check(app(InitialPassword::class)->make(), $raka))->toBeFalse()
        ->and(Hash::check('SMAGA_05052010', $salsa))->toBeTrue()
        ->and(Hash::check(app(InitialPassword::class)->make(), $salsa))->toBeFalse();
});

it('keeps every initial password out of the report, the audit log and every log line (CLAUDE.md rule 3, D-173)', function (): void {
    config(['sth.initial_student_password' => 'Awal-Uji-7319']);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
        $logged[] = $e->message.' '.json_encode($e->context);
    });

    $report = app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv(w1cMixedRows()), 'csv', '2026/2027', dryRun: false);

    $secrets = ['Awal-Uji-7319', InitialPassword::PREFIX, InitialPassword::forBirthDate('2010-03-02'), InitialPassword::forBirthDate('2009-11-20')];
    $audit = json_encode(Activity::query()->get(['description', 'event', 'properties']));

    foreach ($secrets as $secret) {
        expect(str_contains((string) json_encode($report->rows), $secret))->toBeFalse()
            ->and(str_contains((string) $audit, $secret))->toBeFalse()
            ->and(str_contains(implode("\n", $logged), $secret))->toBeFalse();
    }
});

it('accepts a new NIS that equals another student NISN, and the login still finds the NISN owner (D-137)', function (): void {
    app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv([
        ['Dimas Saputra', '0011111111', '0098765450', '06-06-2010', 'X-1', ''],
    ]), 'csv', '2026/2027', dryRun: false);

    $owner = Student::query()->where('nisn', '0011111111')->sole();
    expect(Student::query()->where('nis', '0011111111')->sole()->nisn)->toBe('0098765450');

    $this->from('/login')->post('/login', ['identifier' => '0011111111', 'password' => 'password'])->assertRedirect();
    $this->assertAuthenticatedAs($owner->user);
});

it('counts NIS and NISN duplicates per column inside the file', function (): void {
    $report = app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv([
        ['Dimas Saputra', '0098765460', '0098765461', '06-06-2010', 'X-1', ''],
        ['Intan Lestari', '225226290', '0098765460', '07-07-2010', 'X-1', ''],
    ]), 'csv', '2026/2027');

    expect(collect($report->rows)->pluck('errors')->all())->toBe([[], []])
        ->and($report->rows[0]['nisn'])->toBe('0098765461');
});

it('finds a class whose name has a space, written with or without it (Admin › Kelas)', function (): void {
    $ipa = Classroom::factory()->level(11)->create(['name' => 'XI-IPA 1']);

    $report = app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv([
        ['Raka Pratama', '225226202', '0098765433', '2009-11-20', 'XI-IPA 1', ''],
        ['Sinta Maharani', '225226203', '0098765434', '2009-05-14', 'xi-ipa1', ''],
    ]), 'csv', '2026/2027', dryRun: false);

    expect($report->created)->toBe(2)
        ->and(Student::query()->where('classroom_id', $ipa->getKey())->count())->toBe(2);
});

/** The downloaded template of the import screen, written to a temp .xlsx (fictional content only). */
function sv3Template(User $actor): string
{
    $screen = Livewire::actingAs($actor)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)->call('downloadTemplate');
    $path = tempnam(sys_get_temp_dir(), 'sth-template-').'.xlsx';
    $GLOBALS['w1c_tmp_files'][] = $path;
    file_put_contents($path, base64_decode((string) data_get($screen->effects, 'download.content')));

    return $path;
}

/** One part of an .xlsx as XML, with the main spreadsheet namespace registered as "m". */
function sv3Xml(string $xlsx, string $part): SimpleXMLElement
{
    $zip = new ZipArchive;
    $zip->open($xlsx);
    $xml = simplexml_load_string((string) $zip->getFromName($part));
    $zip->close();
    $xml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    return $xml;
}

it('gives every template column a width that shows its header and its example (SV3)', function (): void {
    $this->withoutVite();
    $cols = sv3Xml(sv3Template($this->kesiswaan), 'xl/worksheets/sheet1.xml')->xpath('//m:cols/m:col');
    $widths = [];

    foreach ($cols as $col) {
        for ($n = (int) $col['min']; $n <= (int) $col['max']; $n++) {
            $widths[$n] = (float) $col['width'];
        }
    }

    foreach (ImportStudents::TEMPLATE_HEADER as $i => $header) {
        $needed = max(mb_strlen($header), mb_strlen(ImportStudents::TEMPLATE_EXAMPLE[$i])) + 2;
        expect($widths[$i + 1] ?? 0.0)->toBeGreaterThanOrEqual($needed, $header);
    }
});

it('puts one grey italic example row under the header and keeps its text columns as text (SV3)', function (): void {
    $this->withoutVite();
    $path = sv3Template($this->kesiswaan);

    expect(SimpleExcelReader::create($path)->noHeaderRow()->getRows()->skip(1)->first())
        ->toBe(ImportStudents::TEMPLATE_EXAMPLE);

    $sheet = sv3Xml($path, 'xl/worksheets/sheet1.xml');
    $styles = sv3Xml($path, 'xl/styles.xml');
    $fontOf = function (string $ref) use ($sheet, $styles): SimpleXMLElement {
        $xf = $styles->xpath('//m:cellXfs/m:xf')[(int) $sheet->xpath("//m:c[@r='{$ref}']")[0]['s']];

        return $styles->xpath('//m:fonts/m:font')[(int) $xf['fontId']];
    };

    foreach (['A2', 'C2', 'D2', 'F2'] as $ref) {
        $font = $fontOf($ref);
        $font->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        expect($font->xpath('m:i'))->not->toBeEmpty($ref)
            ->and((string) ($font->xpath('m:color')[0]['rgb'] ?? ''))->toBe('FF808080', $ref);
    }

    // The header row stays plain.
    $header = $fontOf('A1');
    $header->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    expect($header->xpath('m:i'))->toBeEmpty();
});

it('skips the untouched example row and imports the students below it (SV3)', function (): void {
    $xlsx = tempnam(sys_get_temp_dir(), 'sth-import-').'.xlsx';
    $GLOBALS['w1c_tmp_files'][] = $xlsx;
    SimpleExcelWriter::create($xlsx)
        ->addHeader(ImportStudents::TEMPLATE_HEADER)
        ->addRow(ImportStudents::TEMPLATE_EXAMPLE)
        ->addRow(['Raka Pratama', '225226202', '0098765433', '20-11-2009', 'X-1', ''])
        ->close();

    $report = app(ImportStudents::class)->handle($this->kesiswaan, $xlsx, 'xlsx', '2026/2027');

    expect($report->total())->toBe(1)
        ->and($report->invalidCount())->toBe(0)
        ->and($report->rows[0]['row'] ?? null)->toBe(3);
});

it('still accepts the old template header, and a CSV with the new one (SV3)', function (): void {
    $row = ['Raka Pratama', '225226202', '0098765433', '20-11-2009', 'X-1', ''];

    $xlsx = tempnam(sys_get_temp_dir(), 'sth-import-').'.xlsx';
    $GLOBALS['w1c_tmp_files'][] = $xlsx;
    SimpleExcelWriter::create($xlsx)->addHeader(W1C_OLD_IMPORT_HEADER)->addRow($row)->close();
    $old = app(ImportStudents::class)->handle($this->kesiswaan, $xlsx, 'xlsx', '2026/2027');

    $new = app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv([$row], ImportStudents::TEMPLATE_HEADER, ';'), 'csv', '2026/2027');

    expect($old->validCount())->toBe(1)->and($old->invalidCount())->toBe(0)
        ->and($new->validCount())->toBe(1)->and($new->invalidCount())->toBe(0);
});

it('drops the example No HP from a row whose other cells were typed over, with a note (SV3 review)', function (): void {
    [, , , , , $examplePhone] = ImportStudents::TEMPLATE_EXAMPLE;
    $csv = w1cCsv([['Raka Pratama', '225226202', '0098765433', '20-11-2009', 'X-1', $examplePhone]]);

    $preview = app(ImportStudents::class)->handle($this->kesiswaan, $csv, 'csv', '2026/2027');
    expect($preview->invalidCount())->toBe(0)
        ->and($preview->rows[0]['notes'])->toContain(ImportStudents::NOTE_EXAMPLE_PHONE);

    app(ImportStudents::class)->handle($this->kesiswaan, $csv, 'csv', '2026/2027', dryRun: false);
    expect(Student::query()->where('nisn', '0098765433')->sole()->phone)->toBeNull();
});

it('refuses a row that still holds the example name, NIS or NISN (SV3 review)', function (int $column, string $message): void {
    $row = ['Raka Pratama', '225226202', '0098765433', '20-11-2009', 'X-1', ''];
    $row[$column] = ImportStudents::TEMPLATE_EXAMPLE[$column];

    $report = app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv([$row]), 'csv', '2026/2027');

    expect($report->invalidCount())->toBe(1)
        ->and($report->rows[0]['errors'])->toContain($message);
})->with([
    'nama' => [0, 'Nama lengkap masih berisi contoh dari template.'],
    'nis' => [1, 'NIS masih berisi contoh dari template.'],
    'nisn' => [2, 'NISN masih berisi contoh dari template.'],
]);

it('keeps a real student in the class and with the birth date of the example (SV3 review)', function (): void {
    [, , , $birthDate, $kelas] = ImportStudents::TEMPLATE_EXAMPLE;
    $report = app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv([['Raka Pratama', '225226202', '0098765433', $birthDate, $kelas, '']]), 'csv', '2026/2027');

    expect($report->validCount())->toBe(1)->and($report->invalidCount())->toBe(0);
});

// SV6, D-184 (the lead, 5 Nov 2026: "bisa langsung ditunjukan siswa mana"): every row that matches or collides with a
// registered student carries that student's users.id in the report (`matches`), for the preview cache of the screen only.

/** A registered student with a chosen users.id, so the id can be looked for in logs and files (fictional data). */
function sv6mStudent(int $userId, string $name, string $nis, string $nisn, array $user = []): Student
{
    User::factory()->create(['id' => $userId, 'name' => $name, 'email' => str($name)->slug('.').'@example.test'])
        ->forceFill(['status' => UserStatus::Active, 'must_change_password' => false, 'password_changed_at' => now(), ...$user])
        ->save();

    return Student::factory()->create([
        'user_id' => $userId,
        'nis' => $nis,
        'nisn' => $nisn,
        'birth_date' => '2010-03-02',
        'classroom_id' => Classroom::query()->where('name', 'X-1')->value('id'),
        'import_school_year' => '2026/2027',
        'grade12_start_date' => '2028-07-01',
        'archive_at' => '2030-01-01',
    ])->refresh();
}

it('names the registered student of every row that matches or collides with one (SV6, D-184)', function (): void {
    sv6mStudent(731001, 'Nadia Putri Anindya', '225226201', '0098765432');
    sv6mStudent(731002, 'Raka Pratama', '225226202', '0098765433');
    sv6mStudent(731003, 'Salsa Maharani', '225226203', '0098765434', ['status' => UserStatus::Archived]);
    $dimas = sv6mStudent(731004, 'Dimas Saputra', '225226204', '0098765435');
    sv6mStudent(731005, 'Citra Ayu', '225226205', '0098765436');
    sv6mStudent(731006, 'Fajar Hidayat', '225226206', '0098765437');
    $dimas->user->delete();
    $dimas->delete();
    $taken = Student::query()->where('nis', '225226999')->sole();

    $report = app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv([
        ['Nadia Putri Anindya', '225226201', '0098765432', '02-03-2010', 'XI-4', ''], // 2 pindah kelas
        ['Raka Pratama', '225226202', '0098765433', '02-03-2010', 'X-1', ''],         // 3 tidak berubah
        ['Salsa Maharani', '225226203', '0098765434', '02-03-2010', 'X-1', ''],       // 4 alumni, dilewati
        ['Dimas Saputra', '225226204', '0098765435', '02-03-2010', 'X-1', ''],        // 5 in the Sampah, dilewati
        ['Citra Fajar', '225226205', '0098765437', '02-03-2010', 'X-1', ''],          // 6 NIS of Citra, NISN of Fajar
        ['Gita Permata', '225226999', '0098765499', '02-03-2010', 'X-1', ''],         // 7 NIS registered with another NISN
        ['Hana Safitri', '225226298', '0011111111', '02-03-2010', 'X-1', ''],         // 8 NISN registered with another NIS
        ['Bima Saputra', '225226299', '0098765429', '02-03-2010', 'X-1', ''],         // 9 new
    ]), 'csv', '2026/2027');

    $byRow = collect($report->rows)->keyBy('row');

    expect($byRow[2])->toMatchArray(['action' => StudentImportReport::MOVED, 'matches' => [731001]])
        ->and($byRow[3])->toMatchArray(['action' => StudentImportReport::UNCHANGED, 'matches' => [731002]])
        ->and($byRow[4])->toMatchArray(['action' => StudentImportReport::SKIPPED, 'matches' => [731003]])
        ->and($byRow[5])->toMatchArray(['action' => StudentImportReport::SKIPPED, 'notes' => [ImportStudents::NOTE_IN_TRASH], 'matches' => [731004]])
        // The owner of the NIS first, then the owner of the NISN.
        ->and($byRow[6])->toMatchArray(['errors' => ['NIS dan NISN ini milik dua siswa yang berbeda.'], 'matches' => [731005, 731006]])
        ->and($byRow[7])->toMatchArray(['errors' => ['NIS sudah terdaftar dengan NISN lain.'], 'matches' => [(int) $taken->user_id]])
        ->and($byRow[8])->toMatchArray(['errors' => ['NISN sudah terdaftar dengan NIS lain.'], 'matches' => [(int) $taken->user_id]])
        ->and($byRow[9])->toMatchArray(['action' => StudentImportReport::NEW, 'matches' => []]);
});

it('names the student of a registered row whose archive date has passed, and of a corrected one (SV6, D-184)', function (): void {
    Classroom::factory()->level(12)->create(['name' => 'XII-1']);
    sv6mStudent(731001, 'Nadia Putri Anindya', '225226201', '0098765432');
    sv6mStudent(731002, 'Raka Pratama', '225226202', '0098765433');
    $importer = app(ImportStudents::class);

    // Kelas XII in 2024/2025: archive date January 2026, already gone (today is 4 Oct 2026).
    $past = $importer->handle($this->kesiswaan, w1cCsv([m1cNadiaRow('XII-1')]), 'csv', '2024/2025');
    // Same class, a phone number in the file: "Data diperbarui".
    $corrected = $importer->handle($this->kesiswaan, w1cCsv([['Raka Pratama', '225226202', '0098765433', '02-03-2010', 'X-1', '081234567890']]), 'csv', '2026/2027');

    expect($past->rows[0])->toMatchArray([
        'action' => StudentImportReport::REJECTED,
        'errors' => ['Tanggal arsip dari kelas dan tahun ajaran ini sudah lewat. Periksa tahun ajaran dan kelasnya.'],
        'matches' => [731001],
    ])
        ->and(StudentImportReport::kindOf($corrected->rows[0]))->toBe(StudentImportReport::CORRECTED)
        ->and($corrected->rows[0]['matches'])->toBe([731002]);
});

it('names the student another import registered meanwhile on a row it did not create (SV6, D-184)', function (): void {
    $done = false;

    // The other import commits its student when this import's write starts (the check of the file is then stale).
    DB::connection()->beforeStartingTransaction(function () use (&$done): void {
        if ($done) {
            return;
        }

        $done = true;
        sv6mStudent(731009, 'Rafi Pratama', '225226202', '0098765433');
    });

    $report = app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv([
        ['Rafi Pratama', '225226202', '0098765433', '02-03-2010', 'X-1', ''],
    ]), 'csv', '2026/2027', dryRun: false);

    expect($report->taken)->toBe(1)
        ->and($report->rows[0])->toMatchArray(['action' => StudentImportReport::SKIPPED, 'matches' => [731009]])
        ->and($report->rows[0]['notes'])->toContain(ImportStudents::NOTE_TAKEN);
});

it('keeps the matched ids out of the audit log of a committed import (SV6, D-184)', function (): void {
    sv6mStudent(731001, 'Nadia Putri Anindya', '225226201', '0098765432');
    sv6mStudent(731002, 'Raka Pratama', '225226202', '0098765433');
    sv6mStudent(731003, 'Salsa Maharani', '225226203', '0098765434');
    $logged = Activity::query()->max('id') ?? 0;

    $report = app(ImportStudents::class)->handle($this->kesiswaan, w1cCsv([
        ['Nadia Putri Anindya', '225226201', '0098765432', '02-03-2010', 'XI-4', ''],
        ['Raka Pratama', '225226202', '0098765433', '02-03-2010', 'X-1', '081234567890'],
        ['Citra Ayu', '225226203', '0098765488', '02-03-2010', 'X-1', ''],
    ]), 'csv', '2026/2027', dryRun: false);

    $entries = Activity::query()->where('id', '>', $logged)->get();
    $properties = $entries->map(fn (Activity $a): string => (string) json_encode($a->properties))->implode(' ');

    expect($report->moved)->toBe(1)
        ->and(array_column($report->rows, 'matches'))->toBe([[731001], [731002], [731003]])
        ->and($entries->pluck('event')->all())->toContain('student.import', 'student.class_changed', 'student.phone_updated')
        ->and($properties)->not->toContain('731001')
        ->and($properties)->not->toContain('731002')
        ->and($properties)->not->toContain('731003')
        ->and($properties)->not->toContain('matches');
});
