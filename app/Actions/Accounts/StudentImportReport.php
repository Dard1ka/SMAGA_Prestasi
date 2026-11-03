<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

/**
 * Result of ImportStudents. Rows never carry birth dates, phone numbers or passwords.
 *
 * Each row: ['row' => int (spreadsheet line), 'name' => ?string, 'nis' => ?string, 'nisn' => ?string, 'classroom' => ?string (the class in
 *            the file), 'errors' => list<string>, 'grade12_start_date' => ?string (Y-m-d), 'archive_at' => ?string
 *            (Y-m-d), 'sanitized' => bool (a leading = + - @ was stripped from a cell: "Sel dibersihkan", B2),
 *            'action' => string (one of the constants below), 'previous_classroom' => ?string (the stored class of a
 *            student who moves), 'notes' => list<string> (warnings that do not stop the row),
 *            'changes' => bool (the import writes something for this row)]
 *
 * Actions (M1-C, lead 2026-10-04: the yearly file lists every student with the current class):
 * - new       → an account is created (as before);
 * - moved     → a registered student (same NIS and NISN) goes to the class in the file;
 * - unchanged → a registered student who is already in that class (a note tells when the archive date or the
 *               phone number is corrected anyway: such a row is a correction, kindOf() = CORRECTED);
 * - skipped   → a registered student whose account is archived (alumni): nothing is written;
 * - rejected  → `errors` is not empty: nothing is written.
 */
final readonly class StudentImportReport
{
    public const NEW = 'new';

    public const MOVED = 'moved';

    public const UNCHANGED = 'unchanged';

    public const SKIPPED = 'skipped';

    public const REJECTED = 'rejected';

    public const ACTIONS = [self::NEW, self::MOVED, self::UNCHANGED, self::SKIPPED, self::REJECTED];

    /**
     * Not an action of ImportStudents (so not in ACTIONS or summarize()): the kind kindOf() gives an UNCHANGED row the
     * import still writes for, the archive dates or the phone number of the file ("Data diperbarui" on the screen).
     */
    public const CORRECTED = 'corrected';

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  int  $created  accounts created by a committed import (0 in a dry run)
     * @param  int  $moved  students moved to another class by a committed import (0 in a dry run)
     * @param  int  $taken  new rows a committed import left out because another import registered them meanwhile
     *                      (reported as SKIPPED with ImportStudents::NOTE_TAKEN)
     */
    public function __construct(
        public array $rows,
        public bool $dryRun,
        public int $created,
        public string $schoolYear,
        public int $moved = 0,
        public int $taken = 0,
    ) {}

    /**
     * Rows per action, for the preview summary "baru · pindah kelas · tidak berubah · ditolak".
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{new: int, moved: int, unchanged: int, skipped: int, rejected: int}
     */
    public static function summarize(array $rows): array
    {
        $summary = array_fill_keys(self::ACTIONS, 0);

        foreach ($rows as $row) {
            $action = self::actionOf($row);
            $summary[$action] = ($summary[$action] ?? 0) + 1;
        }

        return $summary;
    }

    /**
     * What happens to one row, as the preview and its download name it: the row's action, except an UNCHANGED row the
     * import still writes for, which is CORRECTED. changeKinds() counts by it, so a row's label and the tiles above the
     * preview name the same kind (SV1 follow-up: a corrected row counted as "Data diperbarui" but was labelled "Tidak
     * berubah").
     *
     * @param  array<string, mixed>  $row
     */
    public static function kindOf(array $row): string
    {
        $action = self::actionOf($row);

        return $action === self::UNCHANGED && self::writes($row) ? self::CORRECTED : $action;
    }

    /**
     * Rows the import writes something for (new accounts, class moves, corrected dates or phone numbers).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function countChanges(array $rows): int
    {
        return count(array_filter($rows, fn (array $r): bool => self::writes($r)));
    }

    /**
     * The rows of countChanges() per kind of change (kindOf()): new accounts, class moves, and corrections (a registered
     * student who stays in the class and gets the archive dates or the phone number of the file). The three add up to
     * countChanges(), so the button "Impor N siswa", its dialog and the result name the same students (SV1 browser
     * check: the button counted the corrections, the dialog and the result did not).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{new: int, moved: int, corrected: int}
     */
    public static function changeKinds(array $rows): array
    {
        $kinds = [self::NEW => 0, self::MOVED => 0, self::CORRECTED => 0];

        foreach (array_filter($rows, fn (array $r): bool => self::writes($r)) as $row) {
            $kind = self::kindOf($row);
            // ImportStudents writes only these three kinds; a written row of any other kind still counts, as a correction.
            $kinds[array_key_exists($kind, $kinds) ? $kind : self::CORRECTED]++;
        }

        return $kinds;
    }

    /**
     * The action of a row (one of ACTIONS); a row without one is NEW, or REJECTED when it has errors.
     *
     * @param  array<string, mixed>  $row
     */
    private static function actionOf(array $row): string
    {
        return $row['action'] ?? (($row['errors'] ?? []) === [] ? self::NEW : self::REJECTED);
    }

    /** @param array<string, mixed> $row */
    private static function writes(array $row): bool
    {
        return ($row['errors'] ?? []) === [] && ($row['changes'] ?? true);
    }

    /** @return array{new: int, moved: int, unchanged: int, skipped: int, rejected: int} */
    public function summary(): array
    {
        return self::summarize($this->rows);
    }

    public function changeCount(): int
    {
        return self::countChanges($this->rows);
    }

    /** @return list<array<string, mixed>> rows without errors (new, moved, unchanged and skipped) */
    public function validRows(): array
    {
        return array_values(array_filter($this->rows, fn (array $r): bool => $r['errors'] === []));
    }

    /** @return list<array<string, mixed>> */
    public function invalidRows(): array
    {
        return array_values(array_filter($this->rows, fn (array $r): bool => $r['errors'] !== []));
    }

    /** Rows where a formula-like prefix was stripped ("Sel dibersihkan"); they may still be valid. */
    public function sanitizedCount(): int
    {
        return count(array_filter($this->rows, fn (array $r): bool => $r['sanitized'] ?? false));
    }

    public function total(): int
    {
        return count($this->rows);
    }

    public function validCount(): int
    {
        return count($this->validRows());
    }

    public function invalidCount(): int
    {
        return count($this->invalidRows());
    }
}
