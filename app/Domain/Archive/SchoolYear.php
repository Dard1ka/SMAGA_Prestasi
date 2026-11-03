<?php

declare(strict_types=1);

namespace App\Domain\Archive;

use DateTimeInterface;
use InvalidArgumentException;
use Stringable;

/**
 * Indonesian school year "YYYY/YYYY" (July to June), e.g. 2026/2027.
 */
final readonly class SchoolYear implements Stringable
{
    public const MIN_YEAR = 2000;

    public const MAX_YEAR = 2100;

    private const INVALID = 'Tahun ajaran harus berformat YYYY/YYYY, misalnya 2026/2027.';

    public function __construct(public int $startYear)
    {
        if ($startYear < self::MIN_YEAR || $startYear > self::MAX_YEAR) {
            throw new InvalidArgumentException(self::INVALID);
        }
    }

    public static function fromString(string $value): self
    {
        if (preg_match('~^(\d{4})/(\d{4})$~', trim($value), $m) !== 1 || (int) $m[2] !== (int) $m[1] + 1) {
            throw new InvalidArgumentException(self::INVALID);
        }

        return new self((int) $m[1]);
    }

    public static function isValid(string $value): bool
    {
        try {
            self::fromString($value);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** The school year a date falls in (a new year starts on 1 July). */
    public static function containing(DateTimeInterface $date): self
    {
        $year = (int) $date->format('Y');

        return new self((int) $date->format('n') >= 7 ? $year : $year - 1);
    }

    public function endYear(): int
    {
        return $this->startYear + 1;
    }

    public function toString(): string
    {
        return $this->startYear.'/'.$this->endYear();
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
