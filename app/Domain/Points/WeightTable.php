<?php

declare(strict_types=1);

namespace App\Domain\Points;

use App\Domain\Support\Decimal;
use App\Domain\Support\Key;
use ArrayAccess;
use BackedEnum;
use InvalidArgumentException;

/**
 * One weight version: level (Level), result (ResultType) and organizer (WeightCategory) maps.
 * Values are stored as 2-decimal strings, matching the weight_values.value decimal column.
 */
final class WeightTable
{
    public const GROUP_LEVEL = 'level';

    public const GROUP_RESULT = 'result';

    public const GROUP_ORGANIZER = 'organizer';

    private const DEFAULT_LEVELS = [
        'sekolah' => '20', 'kota' => '40', 'provinsi' => '60', 'nasional' => '80', 'internasional' => '100',
    ];

    private const DEFAULT_RESULTS = [
        'juara_1' => '1.00', 'medali_emas' => '1.00',
        'juara_2' => '0.85', 'medali_perak' => '0.85',
        'juara_3' => '0.70', 'medali_perunggu' => '0.70',
        'finalis' => '0.50', 'semifinalis' => '0.30', 'penghargaan_khusus' => '0.30', 'peserta' => '0.10',
    ];

    private const DEFAULT_ORGANIZERS = [
        'A' => '1.00', 'B' => '0.80', 'C' => '0.60', 'D' => '0.40', 'E' => '0.20',
    ];

    /** @var array<string, array<string, string>> */
    private readonly array $groups;

    /**
     * @param  array<string, string|int|float>  $levels
     * @param  array<string, string|int|float>  $results
     * @param  array<string, string|int|float>  $organizers
     */
    public function __construct(array $levels, array $results, array $organizers)
    {
        $this->groups = [
            self::GROUP_LEVEL => self::normalizeGroup($levels),
            self::GROUP_RESULT => self::normalizeGroup($results),
            self::GROUP_ORGANIZER => self::normalizeGroup($organizers),
        ];
    }

    /** Default weights from ARCHITECTURE §7 / D-08. */
    public static function defaults(): self
    {
        return new self(self::DEFAULT_LEVELS, self::DEFAULT_RESULTS, self::DEFAULT_ORGANIZERS);
    }

    /**
     * Build from weight_values rows (arrays, ArrayAccess models or plain objects) with group, key, value.
     *
     * @param  iterable<array<string, mixed>|ArrayAccess<string, mixed>|object>  $rows
     */
    public static function fromRows(iterable $rows): self
    {
        $maps = [self::GROUP_LEVEL => [], self::GROUP_RESULT => [], self::GROUP_ORGANIZER => []];

        foreach ($rows as $row) {
            $group = Key::of(self::field($row, 'group'));

            if (! array_key_exists($group, $maps)) {
                throw new InvalidArgumentException("Grup bobot tidak dikenal: {$group}.");
            }

            $maps[$group][Key::of(self::field($row, 'key'))] = self::field($row, 'value');
        }

        return new self($maps[self::GROUP_LEVEL], $maps[self::GROUP_RESULT], $maps[self::GROUP_ORGANIZER]);
    }

    public function level(string|BackedEnum $key): string
    {
        return $this->lookup(self::GROUP_LEVEL, $key);
    }

    public function result(string|BackedEnum $key): string
    {
        return $this->lookup(self::GROUP_RESULT, $key);
    }

    public function organizer(string|BackedEnum $key): string
    {
        return $this->lookup(self::GROUP_ORGANIZER, $key);
    }

    /** @return array<string, string> */
    public function levels(): array
    {
        return $this->groups[self::GROUP_LEVEL];
    }

    /** @return array<string, string> */
    public function results(): array
    {
        return $this->groups[self::GROUP_RESULT];
    }

    /** @return array<string, string> */
    public function organizers(): array
    {
        return $this->groups[self::GROUP_ORGANIZER];
    }

    /**
     * Rows ready to insert into weight_values (without weight_version_id).
     *
     * @return list<array{group: string, key: string, value: string}>
     */
    public function toRows(): array
    {
        $rows = [];

        foreach ($this->groups as $group => $values) {
            foreach ($values as $key => $value) {
                $rows[] = ['group' => $group, 'key' => (string) $key, 'value' => $value];
            }
        }

        return $rows;
    }

    private function lookup(string $group, string|BackedEnum $key): string
    {
        $key = Key::of($key);

        if (! array_key_exists($key, $this->groups[$group])) {
            throw new InvalidArgumentException("Bobot {$group} untuk '{$key}' belum diatur.");
        }

        return $this->groups[$group][$key];
    }

    /**
     * @param  array<string, string|int|float>  $values
     * @return array<string, string>
     */
    private static function normalizeGroup(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            $key = (string) $key;

            if ($key === '') {
                throw new InvalidArgumentException('Kunci bobot tidak boleh kosong.');
            }

            $normalized[$key] = Decimal::roundHalfUp(Decimal::nonNegative($value), 2);
        }

        return $normalized;
    }

    private static function field(mixed $row, string $name): mixed
    {
        if (is_array($row) || $row instanceof ArrayAccess) {
            return $row[$name] ?? throw new InvalidArgumentException("Baris bobot tanpa kolom {$name}.");
        }

        if (is_object($row) && isset($row->{$name})) {
            return $row->{$name};
        }

        throw new InvalidArgumentException("Baris bobot tanpa kolom {$name}.");
    }
}
