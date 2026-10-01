<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

/**
 * Result of ImportStudents. Rows never carry birth dates, phone numbers or passwords.
 *
 * Each row: ['row' => int (spreadsheet line), 'name' => ?string, 'nis' => ?string, 'classroom' => ?string,
 *            'errors' => list<string>, 'grade12_start_date' => ?string (Y-m-d), 'archive_at' => ?string (Y-m-d),
 *            'sanitized' => bool (a leading = + - @ was stripped from a cell: "Sel dibersihkan", B2)]
 */
final readonly class StudentImportReport
{
    /**
     * @param  list<array{row: int, name: ?string, nis: ?string, classroom: ?string, errors: list<string>, grade12_start_date: ?string, archive_at: ?string, sanitized: bool}>  $rows
     */
    public function __construct(
        public array $rows,
        public bool $dryRun,
        public int $created,
        public string $schoolYear,
    ) {}

    /** @return list<array{row: int, name: ?string, nis: ?string, classroom: ?string, errors: list<string>, grade12_start_date: ?string, archive_at: ?string, sanitized: bool}> */
    public function validRows(): array
    {
        return array_values(array_filter($this->rows, fn (array $r): bool => $r['errors'] === []));
    }

    /** @return list<array{row: int, name: ?string, nis: ?string, classroom: ?string, errors: list<string>, grade12_start_date: ?string, archive_at: ?string, sanitized: bool}> */
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
