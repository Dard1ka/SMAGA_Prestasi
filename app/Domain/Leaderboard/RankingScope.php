<?php

declare(strict_types=1);

namespace App\Domain\Leaderboard;

use App\Enums\Concerns\HasOptions;

/**
 * Who a student ranking shows (lead, 2026-10-04; replaces the "Tampilkan alumni" checkbox of D-09/D-37):
 * active students (the default: alumni stay hidden until asked), alumni only, or both together.
 * An alumnus is a student whose account is archived (D-29). Ranks are computed within the shown group.
 * The values are the public URL values (`/peringkat?status=alumni`).
 */
enum RankingScope: string
{
    use HasOptions;

    case Active = 'aktif';
    case Alumni = 'alumni';
    case All = 'semua';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Siswa aktif',
            self::Alumni => 'Alumni',
            self::All => 'Semua',
        };
    }

    /** Does a student with this alumni flag belong to the shown group? */
    public function includes(bool $alumni): bool
    {
        return match ($this) {
            self::Active => ! $alumni,
            self::Alumni => $alumni,
            self::All => true,
        };
    }

    /** A class ranking only exists for students who still have a class. */
    public function hasClasses(): bool
    {
        return $this !== self::Alumni;
    }

    /** Unknown or missing values fall back to the default (active students). */
    public static function fromValue(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Active) : self::Active;
    }
}
