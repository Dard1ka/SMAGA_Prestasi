<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Domain\Accounts\InitialPassword;
use App\Domain\Accounts\PhoneNumber;
use App\Domain\Archive\GraduationCalculator;
use App\Domain\Archive\SchoolYear;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Spatie\SimpleExcel\SimpleExcelReader;
use Throwable;

/**
 * Student import from .xlsx / .csv (D-12, D-26, docs/SECURITY.md §3f). Kesiswaan + Admin (`student.import`).
 *
 * - Columns (header row, case-insensitive): Nama lengkap, NIS, NISN, Tanggal lahir, Kelas, No HP (optional).
 *   "Kelas" is the class at the moment of the upload; the old header "Kelas saat pendaftaran" still works.
 *   Max 2 MB, 3 000 rows.
 * - Formulas are never evaluated: formula cells are read as their text, and every text cell has leading
 *   = + - @ (and tab/CR/LF) stripped before validation (names must then consist of letters only). Rows where
 *   that happened carry `sanitized = true` in the report ("Sel dibersihkan", lead decision, backlog #19).
 * - A new row (neither number known): name ≤ 150, NIS 4–20 digits, NISN exactly 10 digits, birth date valid and
 *   age 13–21, class exists (active), phone ^(\+62|0)8\d{7,12}$, NIS and NISN each unique in their own column,
 *   within the file and against existing students; the NIS is stored only (D-137), so it may equal another
 *   student's NISN.
 *   grade12_start_date / archive_at from GraduationCalculator; initial password = "SMAGA_" + the row's birth date as
 *   ddmmyyyy (InitialPassword::forBirthDate(), D-173, smaga_server; 01-02-2010 → SMAGA_01022010, the same calendar day
 *   that is stored as `students.birth_date`), hashed immediately, must_change_password = true. The plaintext is never
 *   stored, logged or returned. Students imported before D-173 keep their password (`sth:siswa:kata-sandi-awal` moves
 *   the pending ones still on the configured value of D-138).
 * - A row whose NIS AND NISN belong to one registered student is an UPDATE (M1-C, lead 2026-10-04: every school
 *   year Kesiswaan uploads all students with their current class). It moves the student to the class in the
 *   file and stores the phone number when the file has one; it never touches the name, the birth date, the
 *   password, the email, the status or the activation. A different name or birth date only
 *   gives a note. Only one of the two numbers matching → the row is refused. An archived (alumni) account is
 *   skipped with a note; a deleted one is refused.
 * - Archive dates stay a function of class + school year: an update recomputes them with GraduationCalculator
 *   from the class in the file and the chosen school year. A student who moves up (X in 2026/2027 → XI in
 *   2027/2028) gets the same dates, so nothing changes; a student who repeats a year or was registered in a
 *   wrong class gets the corrected dates (noted on the row and in the audit entry). A corrected archive date
 *   that is not in the future refuses the row, so an update can never archive an account tonight.
 * - $dryRun = true only validates; otherwise the valid rows are written in one transaction and refused rows are
 *   left alone (they stay in the report). Committed imports are audited: one `student.import` entry with the
 *   file's SHA-256 and the counts, plus one `student.class_changed` entry per updated student (old → new class
 *   and dates, never a name, a number or a phone).
 *
 * Large imports (B2, backlog #19): App\Jobs\ImportStudentsJob commits the file in slices with importSlice()
 * (password hashing is the slow part, ~0,2 s per row) and writes the single audit entry with recordImport().
 * Two imports of the same new students at the same moment (SV1 third check: the second one answered 500 from the unique
 * index): a NEW row whose NIS or NISN was registered after the check is not created and not counted (createStudent()),
 * the rest of the file is written; `created` stays what was written.
 */
final class ImportStudents
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    public const MAX_ROWS = 3000;

    /** normalised header → field */
    public const HEADERS = [
        'nama lengkap' => 'name',
        'nis' => 'nis',
        'nisn' => 'nisn',
        'tanggal lahir' => 'birth_date',
        'kelas' => 'classroom',
        'kelas saat pendaftaran' => 'classroom', // header of the template before 2026-10-04
        'no hp' => 'phone',
        'no. hp' => 'phone',
        'nomor hp' => 'phone',
    ];

    /**
     * Start of the row note for a registered student whose archive date follows the class of the file. The import
     * screen counts these rows: many of them usually mean the wrong school year was chosen.
     */
    public const NOTE_ARCHIVE_CORRECTED = 'Tanggal arsip dikoreksi';

    /**
     * Row note of a valid row with a cleaned cell (`sanitized`, "Sel dibersihkan"): the screen lists it under "Ada
     * catatan" and in "Download baris yang perlu dicek", so the data can be checked before the import writes it (SV1
     * second check). A refused row keeps no notes; the screen marks it with the chip.
     */
    public const NOTE_SANITIZED = 'Sel dibersihkan: tanda = + - @ di awal sel dibuang.';

    /**
     * The note of a NEW row that a committed import did not create because another import registered its NIS or NISN
     * meanwhile (SV1 third check): the row is reported as skipped, so the result never labels it "Baru".
     */
    public const NOTE_TAKEN = 'Sudah didaftarkan oleh impor lain, jadi tidak dibuat dari file ini.';

    /**
     * Header row of the template (App\Livewire\Akun\ImportSiswa::downloadTemplate). SV3 (lead, 2026-11-03): every column
     * but No HP says it is required; normalizeHeader() drops the "(wajib diisi)" / "(opsional)" part, so files made with
     * the older template (plain names) still read.
     */
    public const TEMPLATE_HEADER = ['Nama lengkap (wajib diisi)', 'NIS (wajib diisi)', 'NISN (wajib diisi)', 'Tanggal lahir (wajib diisi)', 'Kelas (wajib diisi)', 'No HP (opsional)'];

    /**
     * The grey example row under the header of the template (SV3; fictional data, DESIGN-SPEC-v2 §5). Typing into a cell
     * replaces it; a row left exactly like this is skipped by the import (isTemplateExample()), so it never becomes a
     * student or an error.
     */
    public const TEMPLATE_EXAMPLE = ['Nadia Putri Anindya', '225226201', '0012345678', '01-02-2010', 'X-A', '081234567890'];

    /** Column widths of the template in Excel characters (A–F), wide enough for the header and the example (SV3). */
    public const TEMPLATE_WIDTHS = [32, 20, 21, 30, 22, 22];

    /**
     * Template columns formatted as Text, so Excel keeps them as typed: digits whose leading zero matters, and the birth
     * date (SV2 review): since D-173 it is the initial password, and a General cell lets an Excel with an English (US)
     * date setting turn a typed 01-02-2010 into 2 Jan 2010. As text it reaches parseDate() as DD-MM-YYYY whatever the
     * PC's setting; date cells and serials of other files stay accepted.
     */
    public const TEMPLATE_TEXT_COLUMNS = ['NIS (wajib diisi)', 'NISN (wajib diisi)', 'Tanggal lahir (wajib diisi)', 'No HP (opsional)'];

    public const REQUIRED = ['name', 'nis', 'nisn', 'birth_date', 'classroom'];

    private const LABELS = [
        'name' => 'Nama lengkap',
        'nis' => 'NIS',
        'nisn' => 'NISN',
        'birth_date' => 'Tanggal lahir',
        'classroom' => 'Kelas',
    ];

    public function __construct(
        private readonly GraduationCalculator $graduation,
    ) {}

    /**
     * @param  string  $extension  'xlsx' or 'csv' (uploaded temp files have no extension of their own)
     *
     * @throws ValidationException for file-level problems (type, size, headers, too many rows, school year)
     */
    public function handle(User $actor, string $path, string $extension, string $schoolYear, bool $dryRun = true): StudentImportReport
    {
        [$rows, $year] = $this->checkedRows($actor, $path, $extension, $schoolYear);

        $applied = $dryRun ? ['created' => 0, 'moved' => 0, 'taken' => []] : $this->commit($actor, $rows, $year, (string) hash_file('sha256', $path));
        $taken = array_flip($applied['taken']);

        return new StudentImportReport(
            rows: array_map(fn (array $r): array => isset($taken[$r['report']['row']]) ? self::markTaken($r['report']) : $r['report'], $rows),
            dryRun: $dryRun,
            created: $applied['created'],
            schoolYear: $year->toString(),
            moved: $applied['moved'],
            taken: count($applied['taken']),
        );
    }

    /**
     * B2 (backlog #19): write the valid rows among file rows $offset … $offset + $length - 1 (file order), in one
     * transaction, for App\Jobs\ImportStudentsJob. The whole file is read and validated again each time, so a row
     * written by an earlier slice (or a retry) is then a registered student in the right class ("tidak berubah")
     * and is never created or moved twice. No `student.import` entry here: the job calls recordImport() once when
     * every slice is done.
     *
     * @return array{rows: int, valid: int, invalid: int, sanitized: int, created: int, moved: int} counts of the
     *                                                                                              whole file (valid/invalid as of now) + accounts created and students moved in this slice
     *
     * @throws ValidationException for file-level problems
     */
    public function importSlice(User $actor, string $path, string $extension, string $schoolYear, int $offset, int $length): array
    {
        [$rows, $year] = $this->checkedRows($actor, $path, $extension, $schoolYear);

        $valid = self::validOnly($rows);
        $slice = self::validOnly(array_slice($rows, max(0, $offset), max(0, $length)));
        $applied = $this->applyRows($actor, $slice, $year, (string) hash_file('sha256', $path));

        return [
            'rows' => count($rows),
            'valid' => count($valid),
            'invalid' => count($rows) - count($valid),
            'sanitized' => count(array_filter($rows, fn (array $r): bool => $r['report']['sanitized'])),
            'created' => $applied['created'],
            'moved' => $applied['moved'],
        ];
    }

    /** The audit entry of a committed import (also used by ImportStudentsJob after its last slice). */
    public function recordImport(User $actor, string $schoolYear, int $rows, int $created, int $invalid, string $sha256, int $moved = 0): void
    {
        activity('import')
            ->causedBy($actor)
            ->event('student.import')
            ->withProperties([
                'school_year' => $schoolYear,
                'rows' => $rows,
                'created' => $created,
                'moved' => $moved,
                'invalid' => $invalid,
                'file_sha256' => $sha256,
            ])
            ->log('student.import');
    }

    /**
     * Authorize, check the file and return the validated rows.
     *
     * @return array{0: list<array{data: array<string, mixed>, report: array<string, mixed>}>, 1: SchoolYear}
     *
     * @throws ValidationException
     */
    private function checkedRows(User $actor, string $path, string $extension, string $schoolYear): array
    {
        Gate::forUser($actor)->authorize(Permission::StudentImport->value);

        $year = $this->schoolYear($schoolYear);
        $extension = strtolower(ltrim($extension, '.'));

        if (! in_array($extension, ['xlsx', 'csv'], true)) {
            throw ValidationException::withMessages(['file' => 'Pilih file .xlsx atau .csv.']);
        }

        if (! is_file($path) || filesize($path) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'Ukuran file maksimal 2 MB.']);
        }

        return [$this->validateRows($this->readRows($path, $extension), $year), $year];
    }

    /**
     * @param  list<array{data: array<string, mixed>, report: array<string, mixed>}>  $rows
     * @return list<array{data: array<string, mixed>, report: array<string, mixed>}>
     */
    private static function validOnly(array $rows): array
    {
        return array_values(array_filter($rows, fn (array $r): bool => $r['report']['errors'] === []));
    }

    /**
     * Strips characters that make spreadsheet apps treat a text cell as a formula (OWASP CSV injection).
     */
    public static function sanitize(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_float($value)) {
            $value = sprintf('%.0f', $value);
        }

        $value = trim((string) $value);

        return trim(ltrim($value, "=+-@\t\r\n"));
    }

    /**
     * @return list<array<string, mixed>> raw rows keyed by field, with '_line' = spreadsheet line number
     */
    private function readRows(string $path, string $extension): array
    {
        $reader = SimpleExcelReader::create($path, $extension)
            ->keepFormulas()
            ->formatHeadersUsing(fn (mixed $h): string => self::normalizeHeader((string) $h));

        if ($extension === 'csv') {
            $reader->useDelimiter($this->detectDelimiter($path));
        }

        try {
            $headers = $reader->getHeaders() ?? [];
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'File tidak bisa dibaca. Pakai template import siswa.']);
        }

        $fields = array_map(fn (string $h): ?string => self::HEADERS[$h] ?? null, $headers);
        $missing = array_diff(self::REQUIRED, $fields);

        if ($missing !== []) {
            $labels = array_map(fn (string $f): string => self::LABELS[$f], array_values($missing));

            throw ValidationException::withMessages(['file' => 'Kolom wajib tidak ada: '.implode(', ', $labels).'.']);
        }

        $rows = [];
        $line = 1;

        foreach ($reader->getRows() as $raw) {
            $line++;

            $row = ['_line' => $line];

            foreach ($raw as $header => $value) {
                $field = self::HEADERS[$header] ?? null;

                if ($field !== null && ! array_key_exists($field, $row)) {
                    $row[$field] = $value;
                }
            }

            if ($this->isBlank($row) || self::isTemplateExample($row)) {
                continue;
            }

            $rows[] = $row;

            if (count($rows) > self::MAX_ROWS) {
                throw ValidationException::withMessages(['file' => 'Maksimal '.self::MAX_ROWS.' baris per file.']);
            }
        }

        $reader->close();

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{data: array<string, mixed>, report: array<string, mixed>}>
     */
    private function validateRows(array $rows, SchoolYear $year): array
    {
        $classrooms = Classroom::query()->get();
        $byName = $classrooms->keyBy(fn (Classroom $c): string => mb_strtoupper(str_replace(' ', '', $c->name)));
        /** @var array<string, string> $classNames classroom id => name */
        $classNames = $classrooms->pluck('name', 'id')->all();

        $clean = array_map(fn (array $row): array => [
            'line' => (int) $row['_line'],
            'name' => (string) preg_replace('/\s+/u', ' ', self::sanitize($row['name'] ?? '')),
            'nis' => self::digits($row['nis'] ?? ''),
            'nisn' => self::digits($row['nisn'] ?? ''),
            'birth_date' => $row['birth_date'] ?? null,
            'classroom' => mb_strtoupper(str_replace(' ', '', self::sanitize($row['classroom'] ?? ''))),
            'phone' => self::phone($row['phone'] ?? null),
            'sanitized' => self::hadFormulaPrefix($row),
        ], $rows);

        [$byNis, $byNisn] = $this->registeredStudents($clean);

        // Occurrences inside the file, counted per column (the NIS is stored only, D-137).
        $seenNis = [];
        $seenNisn = [];

        foreach ($clean as $row) {
            if ($row['nis'] !== '') {
                $seenNis[$row['nis']] = ($seenNis[$row['nis']] ?? 0) + 1;
            }

            if ($row['nisn'] !== '') {
                $seenNisn[$row['nisn']] = ($seenNisn[$row['nisn']] ?? 0) + 1;
            }
        }

        $today = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
        $result = [];

        foreach ($clean as $row) {
            $errors = [];
            $notes = [];
            $nisOk = preg_match('/^\d{4,20}$/', $row['nis']) === 1;
            $nisnOk = preg_match('/^\d{10}$/', $row['nisn']) === 1;

            if (! $nisOk) {
                $errors[] = 'NIS harus 4–20 angka.';
            } elseif (($seenNis[$row['nis']] ?? 0) > 1) {
                $errors[] = 'NIS dobel di file ini.';
            }

            if (! $nisnOk) {
                $errors[] = 'NISN harus 10 angka (format sel sebagai teks).';
            } elseif (($seenNisn[$row['nisn']] ?? 0) > 1) {
                $errors[] = 'NISN dobel di file ini.';
            }

            // The registered student this row updates: only when NIS and NISN point to the same one.
            $student = null;

            if ($nisOk && $nisnOk) {
                $ownerOfNis = $byNis[$row['nis']] ?? null;
                $ownerOfNisn = $byNisn[$row['nisn']] ?? null;

                if ($ownerOfNis !== null && $ownerOfNisn !== null) {
                    if ($ownerOfNis->is($ownerOfNisn)) {
                        $student = $ownerOfNis;
                    } else {
                        $errors[] = 'NIS dan NISN ini milik dua siswa yang berbeda.';
                    }
                } elseif ($ownerOfNis !== null) {
                    $errors[] = 'NIS sudah terdaftar dengan NISN lain.';
                } elseif ($ownerOfNisn !== null) {
                    $errors[] = 'NISN sudah terdaftar dengan NIS lain.';
                }
            }

            $owner = $student?->user;
            $skipped = false;

            if ($student !== null) {
                if ($student->trashed() || $owner === null || $owner->trashed()) {
                    $errors[] = 'Akun siswa ini sudah dihapus.';
                    $student = null;
                } elseif ($owner->status === UserStatus::Archived) {
                    // Alumni keep their last data: nothing is checked or written for this row.
                    $skipped = true;
                    $notes[] = 'Akun alumni (sudah diarsipkan), dilewati.';
                } elseif ($owner->status === UserStatus::Disabled) {
                    $notes[] = 'Akun ini nonaktif dan tetap nonaktif.';
                }
            }

            $birthDate = self::parseDate($row['birth_date']);

            if ($student === null) {
                if ($row['name'] === '') {
                    $errors[] = 'Nama lengkap wajib diisi.';
                } elseif (mb_strlen($row['name']) > 150) {
                    $errors[] = 'Nama lengkap maksimal 150 karakter.';
                } elseif (preg_match("/^[\\p{L}][\\p{L} .,'’-]*$/u", $row['name']) !== 1) {
                    $errors[] = 'Nama lengkap hanya boleh berisi huruf.';
                }

                if ($birthDate === null) {
                    $errors[] = 'Tanggal lahir tidak valid (pakai DD-MM-YYYY).';
                } else {
                    $age = (int) $birthDate->diffInYears($today, true);

                    if ($birthDate->greaterThan($today) || $age < 13 || $age > 21) {
                        $errors[] = 'Usia harus 13–21 tahun.';
                    }
                }
            } elseif (! $skipped) {
                // A registered student: name and birth date are only compared, never written (rule 3: the
                // password of an existing account is never rebuilt from a birth date in a file).
                $different = array_keys(array_filter([
                    'nama' => $row['name'] !== '' && self::normalizeName($row['name']) !== self::normalizeName((string) $owner?->name),
                    'tanggal lahir' => self::sanitize($row['birth_date']) !== ''
                        && $birthDate?->format('Y-m-d') !== $student->birth_date?->format('Y-m-d'),
                ]));

                if ($different !== []) {
                    $notes[] = ucfirst(implode(' dan ', $different)).' berbeda dari data lama, tidak diubah.';
                }
            }

            /** @var Classroom|null $classroom */
            $classroom = $byName->get($row['classroom']);

            if (! $skipped) {
                if ($classroom === null || ! $classroom->active) {
                    $errors[] = 'Kelas tidak ditemukan.';
                }

                if ($row['phone'] !== null && ! PhoneNumber::isValid($row['phone'])) {
                    $errors[] = PhoneNumber::INVALID;
                }
            }

            $dates = null;
            $moved = false;
            $changes = false;

            if ($classroom !== null && $errors === [] && ! $skipped) {
                $dates = $this->graduation->calculate($classroom->level, $year);

                if ($student === null) {
                    $changes = true;
                } else {
                    $moved = (string) $student->classroom_id !== (string) $classroom->getKey();
                    $datesChange = self::dateString($student->grade12_start_date) !== $dates->grade12StartDate->format('Y-m-d')
                        || self::dateString($student->archive_at) !== $dates->archiveAt->format('Y-m-d');

                    if ($datesChange && $dates->archiveAt->format('Y-m-d') <= $today->format('Y-m-d')) {
                        $errors[] = 'Tanggal arsip dari kelas dan tahun ajaran ini sudah lewat. Periksa tahun ajaran dan kelasnya.';
                        $dates = null;
                        $moved = false;
                    } else {
                        if ($datesChange) {
                            $notes[] = self::NOTE_ARCHIVE_CORRECTED.': '.self::monthLabel($student->archive_at).' → '.self::monthLabel($dates->archiveAt).'.';
                        }

                        $phoneChange = $row['phone'] !== null && rescue(fn () => $student->phone, null, false) !== $row['phone'];

                        if ($phoneChange) {
                            $notes[] = 'No HP diperbarui.';
                        }

                        $changes = $moved || $datesChange || $phoneChange;
                    }
                }
            }

            if ($row['sanitized']) {
                $notes[] = self::NOTE_SANITIZED;
            }

            $action = match (true) {
                $errors !== [] => StudentImportReport::REJECTED,
                $skipped => StudentImportReport::SKIPPED,
                $student === null => StudentImportReport::NEW,
                $moved => StudentImportReport::MOVED,
                default => StudentImportReport::UNCHANGED,
            };

            $result[] = [
                'data' => [
                    'action' => $action,
                    'changes' => $changes,
                    'student_id' => $student?->getKey(),
                    'name' => $row['name'],
                    'nis' => $row['nis'],
                    'nisn' => $row['nisn'],
                    'birth_date' => $birthDate,
                    'classroom_id' => $classroom?->getKey(),
                    'phone' => $row['phone'],
                    'grade12_start_date' => $dates?->grade12StartDate,
                    'archive_at' => $dates?->archiveAt,
                ],
                'report' => [
                    'row' => $row['line'],
                    'name' => $row['name'] !== '' ? $row['name'] : null,
                    'nis' => $row['nis'] !== '' ? $row['nis'] : null,
                    'nisn' => $row['nisn'] !== '' ? $row['nisn'] : null,
                    'classroom' => $classroom?->name ?? ($row['classroom'] !== '' ? $row['classroom'] : null),
                    'errors' => $errors,
                    'grade12_start_date' => $dates?->grade12StartDate->format('Y-m-d'),
                    'archive_at' => $dates?->archiveAt->format('Y-m-d'),
                    'sanitized' => $row['sanitized'],
                    'action' => $action,
                    'previous_classroom' => $moved ? ($classNames[(string) $student?->classroom_id] ?? null) : null,
                    'notes' => $errors === [] ? $notes : [],
                    'changes' => $changes && $errors === [],
                ],
            ];
        }

        return $result;
    }

    /**
     * Every student who already uses one of the file's numbers as NIS or NISN, with the account (incl. soft-deleted
     * rows: the unique index still holds them).
     *
     * @param  list<array<string, mixed>>  $clean
     * @return array{0: array<int|string, Student>, 1: array<int|string, Student>} by NIS, by NISN
     */
    private function registeredStudents(array $clean): array
    {
        $numbers = array_values(array_unique(array_filter(array_merge(array_column($clean, 'nis'), array_column($clean, 'nisn')))));
        $byNis = [];
        $byNisn = [];

        foreach (array_chunk($numbers, 500) as $chunk) {
            Student::withTrashed()
                ->with('user')
                ->where(fn ($q) => $q->whereIn('nis', $chunk)->orWhereIn('nisn', $chunk))
                ->get()
                ->each(function (Student $s) use (&$byNis, &$byNisn): void {
                    $byNis[$s->nis] = $s;
                    $byNisn[$s->nisn] = $s;
                });
        }

        return [$byNis, $byNisn];
    }

    /** Names are compared without case and extra spaces ("NADIA  putri" = "Nadia Putri"). */
    private static function normalizeName(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    private static function dateString(?DateTimeInterface $date): ?string
    {
        return $date?->format('Y-m-d');
    }

    private static function monthLabel(?DateTimeInterface $date): string
    {
        return $date === null ? '–' : CarbonImmutable::parse($date->format('Y-m-d'))->locale('id')->translatedFormat('M Y');
    }

    /**
     * True when sanitize() will strip a leading = + - @ (after whitespace) from one of the row's text cells, i.e. the
     * row is shown as "Sel dibersihkan". A real "+62…" phone number is not a formula.
     *
     * @param  array<string, mixed>  $row
     */
    private static function hadFormulaPrefix(array $row): bool
    {
        foreach (['name', 'nis', 'nisn', 'birth_date', 'classroom', 'phone'] as $field) {
            $value = $row[$field] ?? null;

            if (! is_string($value) || ($value = trim($value)) === '') {
                continue;
            }

            if ($field === 'phone' && preg_match('/^\+62[\d\s.\-()]*$/', $value) === 1) {
                continue;
            }

            if (str_contains('=+-@', $value[0])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{data: array<string, mixed>, report: array<string, mixed>}>  $rows
     * @return array{created: int, moved: int, taken: list<int>}
     */
    private function commit(User $actor, array $rows, SchoolYear $year, string $sha256): array
    {
        $valid = self::validOnly($rows);
        $applied = $this->applyRows($actor, $valid, $year, $sha256);

        $this->recordImport($actor, $year->toString(), count($rows), $applied['created'], count($rows) - count($valid), $sha256, $applied['moved']);

        return $applied;
    }

    /**
     * Write the given (valid) rows in one transaction: new rows become accounts, registered students get the class
     * of the file. The public pages show classes, so their cache is flushed when a student was updated.
     *
     * @param  list<array{data: array<string, mixed>, report: array<string, mixed>}>  $valid
     * @return array{created: int, moved: int, taken: list<int>} accounts created, students moved to another class, the
     *                                                           file rows (report `row`) another import registered first
     */
    private function applyRows(User $actor, array $valid, SchoolYear $year, string $sha256): array
    {
        $todo = array_values(array_filter($valid, fn (array $row): bool => $row['data']['changes']));

        if ($todo === []) {
            return ['created' => 0, 'moved' => 0, 'taken' => []];
        }

        // Three attempts: two imports that meet on the same students in a different order can deadlock in InnoDB, and
        // the attempt that gave way is run again (its writes were rolled back; it then finds the other import's rows).
        /** @var array{created: int, moved: int, updated: int, taken: list<int>} $done */
        $done = DB::transaction(function () use ($actor, $todo, $year, $sha256): array {
            $done = ['created' => 0, 'moved' => 0, 'updated' => 0, 'taken' => []];
            /** @var array<string, string> $classNames classroom id => name */
            $classNames = Classroom::query()->pluck('name', 'id')->all();

            foreach ($todo as $row) {
                $data = $row['data'];

                if ($data['action'] === StudentImportReport::NEW) {
                    // Counted only when written: a row registered meanwhile by another import is left to that import.
                    if ($this->createStudent($data, $year)) {
                        $done['created']++;
                    } else {
                        $done['taken'][] = (int) $row['report']['row'];
                    }

                    continue;
                }

                $moved = $this->updateStudent($actor, $data, $year, $sha256, $classNames);

                if ($moved !== null) {
                    $done['updated']++;
                    $done['moved'] += $moved ? 1 : 0;
                }
            }

            return $done;
        }, 3);

        if ($done['updated'] > 0) {
            PublicAchievements::flush();
        }

        return ['created' => $done['created'], 'moved' => $done['moved'], 'taken' => $done['taken']];
    }

    /**
     * A NEW report row the commit did not create (NOTE_TAKEN): skipped, nothing written for it.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private static function markTaken(array $report): array
    {
        return ['action' => StudentImportReport::SKIPPED, 'changes' => false, 'notes' => [...($report['notes'] ?? []), self::NOTE_TAKEN]] + $report;
    }

    /**
     * Create the account of a validated NEW row. False, with nothing written, when its NIS or NISN was registered after
     * the file was checked: another import of the same students at the same moment (SV1 third check). Such a row is
     * neither created nor updated (the dialog asked to create it, not to move a student); the rest of the file goes on.
     *
     * The numbers are checked again here, then the insert runs in a savepoint of its own: a row of the other import that
     * this check cannot see yet (committed later, or still uncommitted: InnoDB makes the insert wait for it) fails on the
     * unique index and rolls back alone, with its user and role. No locking read for a number nobody holds yet: InnoDB
     * would take a gap lock, and two imports of different new students (every new NIS above the highest one, one gap)
     * would then deadlock on each other's inserts.
     *
     * @param  array<string, mixed>  $data  a validated NEW row
     */
    private function createStudent(array $data, SchoolYear $year): bool
    {
        // Soft-deleted rows too: the unique indexes still hold them (registeredStudents()).
        if (Student::withTrashed()->where('nis', $data['nis'])->exists()
            || Student::withTrashed()->where('nisn', $data['nisn'])->exists()) {
            return false;
        }

        // D-173: the initial password is built from the birth date of this row, the very calendar day stored below.
        $birthDate = $data['birth_date']->format('Y-m-d');

        try {
            DB::transaction(function () use ($data, $year, $birthDate): void {
                $user = new User;
                $user->forceFill([
                    'name' => $data['name'],
                    'password' => Hash::make(InitialPassword::forBirthDate($birthDate)),
                    'status' => UserStatus::PendingActivation,
                    'must_change_password' => true,
                    // B1 — K1: the initial password works only during the activation window (setting, default 14 days).
                    'initial_password_expires_at' => app(AccountControls::class)->initialWindowEnd(),
                ])->save();

                $user->assignRole(RoleName::Siswa->value);

                Student::query()->create([
                    'user_id' => $user->getKey(),
                    'nis' => $data['nis'],
                    'nisn' => $data['nisn'],
                    'birth_date' => $birthDate,
                    'classroom_id' => $data['classroom_id'],
                    'import_school_year' => $year->toString(),
                    'grade12_start_date' => $data['grade12_start_date']?->format('Y-m-d'),
                    'archive_at' => $data['archive_at']?->format('Y-m-d'),
                    'phone' => $data['phone'],
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * Move a registered student to the class of the file (row locked, compared again with what is stored now) and
     * keep the archive dates in line with class + school year. Only `classroom_id`, `import_school_year`,
     * `grade12_start_date`, `archive_at` and `phone` are ever written: never the account (name, password, email,
     * status, activation).
     *
     * @param  array<string, mixed>  $data  a validated MOVED / UNCHANGED row
     * @param  array<string, string>  $classNames  classroom id => name
     * @return bool|null true = moved to another class, false = only dates or phone corrected, null = nothing written
     */
    private function updateStudent(User $actor, array $data, SchoolYear $year, string $sha256, array $classNames): ?bool
    {
        /** @var Student|null $student */
        $student = Student::query()->whereKey($data['student_id'])->lockForUpdate()->first();
        /** @var User|null $owner */
        $owner = $student !== null ? User::query()->find($student->user_id) : null;

        if ($student === null || $owner === null || $owner->isArchived()) {
            return null; // deleted or archived since the file was checked
        }

        $from = [
            'classroom' => $classNames[(string) $student->classroom_id] ?? null,
            'school_year' => $student->import_school_year,
            'grade12_start_date' => self::dateString($student->grade12_start_date),
            'archive_at' => self::dateString($student->archive_at),
        ];
        $to = [
            'classroom' => $classNames[(string) $data['classroom_id']] ?? null,
            'school_year' => $year->toString(),
            'grade12_start_date' => $data['grade12_start_date']->format('Y-m-d'),
            'archive_at' => $data['archive_at']->format('Y-m-d'),
        ];

        $moved = (string) $student->classroom_id !== (string) $data['classroom_id'];
        $datesChange = $from['grade12_start_date'] !== $to['grade12_start_date'] || $from['archive_at'] !== $to['archive_at'];
        $phoneChange = $data['phone'] !== null && rescue(fn () => $student->phone, null, false) !== $data['phone'];

        if (! $moved && ! $datesChange && ! $phoneChange) {
            return null;
        }

        $values = $phoneChange ? ['phone' => $data['phone']] : [];

        if ($moved || $datesChange) {
            $values += [
                'classroom_id' => $data['classroom_id'],
                'import_school_year' => $to['school_year'],
                'grade12_start_date' => $to['grade12_start_date'],
                'archive_at' => $to['archive_at'],
            ];
        }

        $student->forceFill($values)->save();

        // Class names, school years and dates only: no name, NIS/NISN or phone number in the audit log.
        activity('student')
            ->performedOn($student)
            ->causedBy($actor)
            ->event($moved || $datesChange ? 'student.class_changed' : 'student.phone_updated')
            ->withProperties($moved || $datesChange
                ? ['from' => $from, 'to' => $to, 'phone_updated' => $phoneChange, 'source' => 'import', 'file_sha256' => $sha256]
                : ['source' => 'import', 'file_sha256' => $sha256])
            ->log($moved || $datesChange ? 'student.class_changed' : 'student.phone_updated');

        return $moved;
    }

    private function schoolYear(string $value): SchoolYear
    {
        try {
            return SchoolYear::fromString($value);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['school_year' => $e->getMessage()]);
        }
    }

    private static function normalizeHeader(string $header): string
    {
        $header = str_replace("\u{FEFF}", '', $header);
        $header = (string) preg_replace('/\s+/u', ' ', mb_strtolower(trim($header)));

        // SV3: "Nama lengkap (wajib diisi)" and "No HP (opsional)" of the template read as their plain names.
        return (string) preg_replace('/\s*\((wajib diisi|opsional)\)$/u', '', $header);
    }

    private static function digits(mixed $value): string
    {
        return (string) preg_replace('/\s+/', '', self::sanitize($value));
    }

    private static function phone(mixed $value): ?string
    {
        $raw = trim((string) ($value instanceof DateTimeInterface ? '' : (is_float($value) ? sprintf('%.0f', $value) : $value)));
        // One rule with the student's own edit (D-149): separators dropped, "62…" → "+62…", "8…" → "08…".
        $compact = PhoneNumber::normalize($raw);

        if ($compact === '') {
            return null;
        }

        // "+62…" is a legitimate phone prefix; anything else loses formula-trigger characters.
        if (preg_match('/^\+62\d+$/', $compact) === 1) {
            return $compact;
        }

        $compact = PhoneNumber::normalize(self::sanitize($compact));

        return $compact === '' ? null : $compact;
    }

    private static function parseDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->setTimezone('Asia/Jakarta')->startOfDay();
        }

        // Excel serial date in a plain numeric cell.
        if ((is_int($value) || is_float($value)) && $value > 20000 && $value < 60000) {
            return CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'Asia/Jakarta')->addDays((int) $value);
        }

        $value = self::sanitize($value);

        foreach (['d-m-Y', 'j-n-Y', 'd/m/Y', 'j/n/Y', 'd.m.Y', 'Y-m-d'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value, new DateTimeZone('Asia/Jakarta'));

            // Round-trip check rejects overflow dates such as 31-02-2008.
            if ($date !== false && $date->format($format) === $value) {
                return CarbonImmutable::instance($date);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $row */
    private function isBlank(array $row): bool
    {
        foreach (self::LABELS as $field => $label) {
            if (self::sanitize($row[$field] ?? '') !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * True for the untouched example row of the template (TEMPLATE_EXAMPLE, SV3): digits compared as digits, text as
     * trimmed text, so a reader that turned a cell into a number or added spaces still matches it.
     *
     * @param  array<string, mixed>  $row
     */
    private static function isTemplateExample(array $row): bool
    {
        [$name, $nis, $nisn, $birthDate, $classroom, $phone] = self::TEMPLATE_EXAMPLE;

        return self::sanitize($row['name'] ?? '') === $name
            && self::digits($row['nis'] ?? '') === $nis
            && self::digits($row['nisn'] ?? '') === $nisn
            && self::sanitize($row['birth_date'] ?? '') === $birthDate
            && mb_strtoupper(str_replace(' ', '', self::sanitize($row['classroom'] ?? ''))) === $classroom
            && self::digits($row['phone'] ?? '') === $phone;
    }

    private function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'rb');
        $firstLine = $handle !== false ? (string) fgets($handle, 4096) : '';

        if ($handle !== false) {
            fclose($handle);
        }

        return substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
    }
}
