<?php

declare(strict_types=1);

namespace App\Domain\Letters;

use App\Domain\Support\Dates;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Surat tugas numbers built from the settings formats (SECURITY §3g). TU types the sequence;
 * month and year always come from the issue date. Uniqueness is the DB index (type, year, sequence).
 */
final class LetterNumber
{
    public const TEMPLATE_SISWA = '400.3.8/{seq}/MRD/{roman_month}/{year}';

    public const TEMPLATE_GURU = '400.3.8.6/{seq}/{year}';

    public const MAX_SEQUENCE = 99999;

    public const MAX_TEMPLATE_LENGTH = 64;

    public const MSG_INVALID_SEQUENCE = 'Nomor urut harus angka 1–99999.';

    private const PLACEHOLDERS = ['{seq}', '{roman_month}', '{year}'];

    private const ROMAN = [1 => 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

    public function format(string $template, int|string $sequence, DateTimeInterface|string $issuedOn): string
    {
        $this->assertValidTemplate($template);
        $date = Dates::toDate($issuedOn);

        return strtr($template, [
            '{seq}' => (string) $this->parseSequence($sequence),
            '{roman_month}' => $this->romanMonth((int) $date->format('n')),
            '{year}' => $date->format('Y'),
        ]);
    }

    public function romanMonth(int $month): string
    {
        return self::ROMAN[$month] ?? throw new InvalidArgumentException('Bulan harus 1–12.');
    }

    /** Accepts 312, "312" or " 0312 " → 312; throws with an Indonesian message otherwise. */
    public function parseSequence(int|string $input): int
    {
        if (is_string($input)) {
            $input = trim($input);

            if (preg_match('/^\d{1,5}$/', $input) !== 1) {
                throw new InvalidArgumentException(self::MSG_INVALID_SEQUENCE);
            }

            $input = (int) $input;
        }

        if ($input < 1 || $input > self::MAX_SEQUENCE) {
            throw new InvalidArgumentException(self::MSG_INVALID_SEQUENCE);
        }

        return $input;
    }

    public function isValidSequence(mixed $input): bool
    {
        if (! is_int($input) && ! is_string($input)) {
            return false;
        }

        try {
            $this->parseSequence($input);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** Template must contain {seq} once and only known placeholders. */
    public function assertValidTemplate(string $template): void
    {
        if ($template === '' || mb_strlen($template) > self::MAX_TEMPLATE_LENGTH) {
            throw new InvalidArgumentException('Format nomor surat harus 1–64 karakter.');
        }

        preg_match_all('/\{[^}]*\}/', $template, $matches);

        foreach ($matches[0] as $placeholder) {
            if (! in_array($placeholder, self::PLACEHOLDERS, true)) {
                throw new InvalidArgumentException("Format nomor surat memuat kode tidak dikenal: {$placeholder}.");
            }
        }

        if (substr_count($template, '{seq}') !== 1) {
            throw new InvalidArgumentException('Format nomor surat harus memuat {seq} tepat satu kali.');
        }

        if (substr_count($template, '{roman_month}') > 1 || substr_count($template, '{year}') > 1) {
            throw new InvalidArgumentException('Kode bulan dan tahun hanya boleh muncul satu kali.');
        }
    }

    /** Anchored regex that matches numbers produced by $template (for form validation). */
    public function pattern(string $template): string
    {
        $this->assertValidTemplate($template);

        $parts = preg_split('/(\{seq\}|\{roman_month\}|\{year\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $regex = '';

        foreach ($parts as $part) {
            $regex .= match ($part) {
                '{seq}' => '(?<seq>\d{1,5})',
                '{roman_month}' => '(?<roman_month>XII|XI|X|IX|VIII|VII|VI|V|IV|III|II|I)',
                '{year}' => '(?<year>\d{4})',
                default => preg_quote($part, '~'),
            };
        }

        return '~^'.$regex.'$~';
    }

    /**
     * Split a full number back into its parts, or null when it does not fit the template.
     *
     * @return array{sequence: int, month: int|null, year: int|null}|null
     */
    public function parse(string $template, string $number): ?array
    {
        if (preg_match($this->pattern($template), trim($number), $m) !== 1 || ! $this->isValidSequence($m['seq'])) {
            return null;
        }

        $month = isset($m['roman_month']) ? array_search($m['roman_month'], self::ROMAN, true) : false;

        return [
            'sequence' => (int) $m['seq'],
            'month' => $month === false ? null : $month,
            'year' => isset($m['year']) ? (int) $m['year'] : null,
        ];
    }
}
