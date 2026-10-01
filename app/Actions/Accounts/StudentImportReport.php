<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

/**
 * Result of ImportStudents. Rows never carry birth dates, phone numbers or passwords.
 *
 * Each row: ['row' => int (spreadsheet line), 'name' => ?string, 'nis' => ?string, 'classroom' => ?string,
 *            'errors' => list<string>, 'grade12_start_date' => ?string (Y-m-d), 'archive_at' => ?string (Y-m-d)]
 */
final readonly class StudentImportReport
{
    /**
     * @param  list<array{row: int, name: ?string, nis: ?string, classroom: ?string, errors: list<string>, grade12_start_date: ?string, archive_at: ?string}>  $rows
     */
    public function __construct(
        public array $rows,
        public bool $dryRun,
        public int $created,
        public string $schoolYear,
    ) {}

    /** @return list<array{row: int, name: ?string, nis: ?string, classroom: ?string, errors: list<string>, grade12_start_date: ?string, archive_at: ?string}> */
    public function validRows(): array
    {
        return array_values(array_filter($this->rows, fn (array $r): bool => $r['errors'] === []));
    }

    /** @return list<array{row: int, name: ?string, nis: ?string, classroom: ?string, errors: list<string>, grade12_start_date: ?string, archive_at: ?string}> */
    public function invalidRows(): array
    {
        return array_values(array_filter($this->rows, fn (array $r): bool => $r['errors'] !== []));
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
