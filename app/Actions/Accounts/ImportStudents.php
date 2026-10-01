<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Domain\Accounts\InitialPassword;
use App\Domain\Archive\GraduationCalculator;
use App\Domain\Archive\SchoolYear;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
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
 * - Columns (header row, case-insensitive): Nama lengkap, NIS, NISN, Tanggal lahir, Kelas saat pendaftaran,
 *   No HP (optional). Max 2 MB, 3 000 rows.
 * - Formulas are never evaluated: formula cells are read as their text, and every text cell has leading
 *   = + - @ (and tab/CR/LF) stripped before validation (names must then consist of letters only). Rows where
 *   that happened carry `sanitized = true` in the report ("Sel dibersihkan", lead decision, backlog #19).
 * - Per row: name ≤ 150, NIS 4–20 digits, NISN exactly 10 digits, birth date valid and age 13–21, class exists
 *   (active), phone ^(\+62|0)8\d{7,12}$, NIS/NISN unique within the file and against every existing student
 *   (a NIS may not equal another student's NISN either, because login accepts both).
 * - grade12_start_date / archive_at from GraduationCalculator; initial password from InitialPassword, hashed
 *   immediately, must_change_password = true. The plaintext is never stored, logged or returned.
 * - $dryRun = true only validates; otherwise valid rows are created in one transaction and invalid rows are
 *   skipped (they stay in the report). Committed imports are audited with the file's SHA-256.
 *
 * Large imports (B2, backlog #19): App\Jobs\ImportStudentsJob commits the file in slices with importSlice()
 * (password hashing is the slow part, ~0,2 s per row) and writes the single audit entry with recordImport().
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
        'kelas saat pendaftaran' => 'classroom',
        'no hp' => 'phone',
        'no. hp' => 'phone',
        'nomor hp' => 'phone',
    ];

    public const REQUIRED = ['name', 'nis', 'nisn', 'birth_date', 'classroom'];

    private const LABELS = [
        'name' => 'Nama lengkap',
        'nis' => 'NIS',
        'nisn' => 'NISN',
        'birth_date' => 'Tanggal lahir',
        'classroom' => 'Kelas saat pendaftaran',
    ];

    public function __construct(
        private readonly InitialPassword $initialPassword,
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

        $created = $dryRun ? 0 : $this->commit($actor, $rows, $year, (string) hash_file('sha256', $path));

        return new StudentImportReport(
            rows: array_map(fn (array $r): array => $r['report'], $rows),
            dryRun: $dryRun,
            created: $created,
            schoolYear: $year->toString(),
        );
    }

    /**
     * B2 (backlog #19): create the valid rows among file rows $offset … $offset + $length - 1 (file order), in one
     * transaction, for App\Jobs\ImportStudentsJob. The whole file is read and validated again each time, so rows
     * created by an earlier slice (or a retry) count as "sudah terdaftar" and are never created twice. No audit
     * entry here: the job calls recordImport() once when every slice is done.
     *
     * @return array{rows: int, valid: int, invalid: int, sanitized: int, created: int} counts of the whole file
     *                                                                                  (valid/invalid as of now) + created in this slice
     *
     * @throws ValidationException for file-level problems
     */
    public function importSlice(User $actor, string $path, string $extension, string $schoolYear, int $offset, int $length): array
    {
        [$rows, $year] = $this->checkedRows($actor, $path, $extension, $schoolYear);

        $valid = self::validOnly($rows);

        return [
            'rows' => count($rows),
            'valid' => count($valid),
            'invalid' => count($rows) - count($valid),
            'sanitized' => count(array_filter($rows, fn (array $r): bool => $r['report']['sanitized'])),
            'created' => $this->insertRows(self::validOnly(array_slice($rows, max(0, $offset), max(0, $length))), $year),
        ];
    }

    /** The audit entry of a committed import (also used by ImportStudentsJob after its last slice). */
    public function recordImport(User $actor, string $schoolYear, int $rows, int $created, int $invalid, string $sha256): void
    {
        activity('import')
            ->causedBy($actor)
            ->event('student.import')
            ->withProperties([
                'school_year' => $schoolYear,
                'rows' => $rows,
                'created' => $created,
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

            if ($this->isBlank($row)) {
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
        $classrooms = Classroom::query()->get()->keyBy(fn (Classroom $c): string => mb_strtoupper($c->name));

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

        // Every NIS/NISN already used by a student (incl. soft-deleted rows, the unique index still holds them).
        $numbers = array_values(array_filter(array_merge(array_column($clean, 'nis'), array_column($clean, 'nisn'))));
        $taken = [];

        foreach (array_chunk(array_unique($numbers), 500) as $chunk) {
            Student::withTrashed()
                ->where(fn ($q) => $q->whereIn('nis', $chunk)->orWhereIn('nisn', $chunk))
                ->get(['nis', 'nisn'])
                ->each(function (Student $s) use (&$taken): void {
                    $taken[$s->nis] = true;
                    $taken[$s->nisn] = true;
                });
        }

        // Occurrences inside the file (NIS and NISN share one namespace because login accepts both).
        $seen = [];

        foreach ($clean as $row) {
            foreach (array_unique(array_filter([$row['nis'], $row['nisn']])) as $number) {
                $seen[$number] = ($seen[$number] ?? 0) + 1;
            }
        }

        $today = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
        $result = [];

        foreach ($clean as $row) {
            $errors = [];

            if ($row['name'] === '') {
                $errors[] = 'Nama lengkap wajib diisi.';
            } elseif (mb_strlen($row['name']) > 150) {
                $errors[] = 'Nama lengkap maksimal 150 karakter.';
            } elseif (preg_match("/^[\\p{L}][\\p{L} .,'’-]*$/u", $row['name']) !== 1) {
                $errors[] = 'Nama lengkap hanya boleh berisi huruf.';
            }

            if (preg_match('/^\d{4,20}$/', $row['nis']) !== 1) {
                $errors[] = 'NIS harus 4–20 angka.';
            } elseif (isset($taken[$row['nis']])) {
                $errors[] = 'NIS sudah terdaftar.';
            } elseif (($seen[$row['nis']] ?? 0) > 1) {
                $errors[] = 'NIS dobel di file ini.';
            }

            if (preg_match('/^\d{10}$/', $row['nisn']) !== 1) {
                $errors[] = 'NISN harus 10 angka (format sel sebagai teks).';
            } elseif (isset($taken[$row['nisn']])) {
                $errors[] = 'NISN sudah terdaftar.';
            } elseif (($seen[$row['nisn']] ?? 0) > 1) {
                $errors[] = 'NISN dobel di file ini.';
            }

            $birthDate = self::parseDate($row['birth_date']);

            if ($birthDate === null) {
                $errors[] = 'Tanggal lahir tidak valid (pakai DD-MM-YYYY).';
            } else {
                $age = (int) $birthDate->diffInYears($today, true);

                if ($birthDate->greaterThan($today) || $age < 13 || $age > 21) {
                    $errors[] = 'Usia harus 13–21 tahun.';
                }
            }

            /** @var Classroom|null $classroom */
            $classroom = $classrooms->get($row['classroom']);

            if ($classroom === null || ! $classroom->active) {
                $errors[] = 'Kelas tidak ditemukan.';
            }

            if ($row['phone'] !== null && preg_match('/^(\+62|0)8\d{7,12}$/', $row['phone']) !== 1) {
                $errors[] = 'No HP tidak valid (contoh 0812…).';
            }

            $dates = null;

            if ($classroom !== null && $errors === []) {
                $dates = $this->graduation->calculate($classroom->level, $year);
            }

            $result[] = [
                'data' => [
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
                    'classroom' => $classroom?->name ?? ($row['classroom'] !== '' ? $row['classroom'] : null),
                    'errors' => $errors,
                    'grade12_start_date' => $dates?->grade12StartDate->format('Y-m-d'),
                    'archive_at' => $dates?->archiveAt->format('Y-m-d'),
                    'sanitized' => $row['sanitized'],
                ],
            ];
        }

        return $result;
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
     */
    private function commit(User $actor, array $rows, SchoolYear $year, string $sha256): int
    {
        $valid = self::validOnly($rows);

        $this->insertRows($valid, $year);

        $this->recordImport($actor, $year->toString(), count($rows), count($valid), count($rows) - count($valid), $sha256);

        return count($valid);
    }

    /**
     * Create the given (valid) rows in one transaction; returns how many were created.
     *
     * @param  list<array{data: array<string, mixed>, report: array<string, mixed>}>  $valid
     */
    private function insertRows(array $valid, SchoolYear $year): int
    {
        if ($valid === []) {
            return 0;
        }

        DB::transaction(function () use ($valid, $year): void {
            foreach ($valid as $row) {
                $data = $row['data'];

                $user = new User;
                $user->forceFill([
                    'name' => $data['name'],
                    'password' => Hash::make($this->initialPassword->make($data['birth_date'])),
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
                    'birth_date' => $data['birth_date']->format('Y-m-d'),
                    'classroom_id' => $data['classroom_id'],
                    'import_school_year' => $year->toString(),
                    'grade12_start_date' => $data['grade12_start_date']?->format('Y-m-d'),
                    'archive_at' => $data['archive_at']?->format('Y-m-d'),
                    'phone' => $data['phone'],
                ]);
            }
        });

        return count($valid);
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

        return (string) preg_replace('/\s+/u', ' ', mb_strtolower(trim($header)));
    }

    private static function digits(mixed $value): string
    {
        return (string) preg_replace('/\s+/', '', self::sanitize($value));
    }

    private static function phone(mixed $value): ?string
    {
        $raw = trim((string) ($value instanceof DateTimeInterface ? '' : (is_float($value) ? sprintf('%.0f', $value) : $value)));
        $compact = (string) preg_replace('/[\s.\-()]/', '', $raw);

        if ($compact === '') {
            return null;
        }

        // "+62…" is a legitimate phone prefix; anything else loses formula-trigger characters.
        if (preg_match('/^\+62\d+$/', $compact) === 1) {
            return $compact;
        }

        $compact = self::sanitize($compact);

        if (preg_match('/^62\d+$/', $compact) === 1) {
            return '+'.$compact;
        }

        if (preg_match('/^8\d+$/', $compact) === 1) {
            return '0'.$compact; // numeric cell lost its leading zero
        }

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
