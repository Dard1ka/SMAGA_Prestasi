<?php

declare(strict_types=1);

namespace App\Actions\Classrooms;

use App\Models\Classroom;
use Illuminate\Validation\ValidationException;

/**
 * The name rule of a class (Admin › Kelas): the Admin picks the tingkat (X, XI, XII = level 10, 11, 12) and types the
 * part after the dash; the name is stored as "<TINGKAT>-<PART>" in capitals ("X-A", "XI-IPA 1"). The part is 1–12
 * characters of letters A–Z, digits and single spaces (trimmed, runs of spaces collapsed). Two names are the same when
 * they match without case and spaces, the way the student import compares the "Kelas" column
 * (App\Actions\Accounts\ImportStudents). "XII-" + 12 characters fits `classrooms.name` (20).
 */
final class ClassroomName
{
    /** level => tingkat prefix. */
    public const TINGKAT = [10 => 'X', 11 => 'XI', 12 => 'XII'];

    public const MAX_PART = 12;

    public const MSG_LEVEL = 'Pilih tingkat.';

    public const MSG_EMPTY = 'Isi nama kelas.';

    public const MSG_LONG = 'Nama kelas maksimal 12 karakter.';

    public const MSG_CHARS = 'Pakai huruf, angka, dan spasi saja.';

    /** Trimmed, every run of whitespace one space, capitals. */
    public static function normalizePart(string $part): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $part)));
    }

    /** "<TINGKAT>-<PART>" of a valid level and a normalized part. */
    public static function compose(int $level, string $part): string
    {
        return self::TINGKAT[$level].'-'.self::normalizePart($part);
    }

    /** The part after the tingkat prefix ("XI-IPA 1" → "IPA 1"). */
    public static function partOf(string $name): string
    {
        $dash = strpos($name, '-');

        return $dash === false ? $name : substr($name, $dash + 1);
    }

    /** The comparison key: capitals without spaces (as the import compares). */
    public static function key(string $name): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', $name));
    }

    /**
     * Refuses an invalid level or part. Error keys: `level`, `suffix`.
     *
     * @throws ValidationException
     */
    public static function assertValid(int $level, string $part): void
    {
        $errors = [];

        if (! isset(self::TINGKAT[$level])) {
            $errors['level'] = self::MSG_LEVEL;
        }

        $part = self::normalizePart($part);

        if ($part === '') {
            $errors['suffix'] = self::MSG_EMPTY;
        } elseif (mb_strlen($part) > self::MAX_PART) {
            $errors['suffix'] = self::MSG_LONG;
        } elseif (preg_match('/^[A-Z0-9]+( [A-Z0-9]+)*$/', $part) !== 1) {
            $errors['suffix'] = self::MSG_CHARS;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Refuses a name another class already has (without case and spaces). Call it inside the transaction, after
     * locking the classes.
     *
     * @throws ValidationException
     */
    public static function assertUnique(string $name, ?string $ignoreId = null): void
    {
        $key = self::key($name);

        $taken = Classroom::query()
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->pluck('name')
            ->first(fn (string $other): bool => self::key($other) === $key);

        if ($taken !== null) {
            throw ValidationException::withMessages(['suffix' => "Kelas {$taken} sudah ada."]);
        }
    }

    /** Lock every class row, so two Admins cannot add the same name at once. */
    public static function lockAll(): void
    {
        Classroom::query()->lockForUpdate()->get(['id']);
    }
}
