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
 *               phone number is corrected anyway);
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
     * @param  list<array<string, mixed>>  $rows
     * @param  int  $created  accounts created by a committed import (0 in a dry run)
     * @param  int  $moved  students moved to another class by a committed import (0 in a dry run)
     */
    public function __construct(
        public array $rows,
        public bool $dryRun,
        public int $created,
        public string $schoolYear,
        public int $moved = 0,
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
            $action = $row['action'] ?? (($row['errors'] ?? []) === [] ? self::NEW : self::REJECTED);
            $summary[$action] = ($summary[$action] ?? 0) + 1;
        }

        return $summary;
    }

    /**
     * Rows the import writes something for (new accounts, class moves, corrected dates or phone numbers).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function countChanges(array $rows): int
    {
        return count(array_filter($rows, fn (array $r): bool => ($r['errors'] ?? []) === [] && ($r['changes'] ?? true)));
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
