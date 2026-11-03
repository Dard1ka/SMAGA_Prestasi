<?php

declare(strict_types=1);

namespace App\Domain\Letters;

use App\Domain\Support\Dates;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Surat tugas numbers (SECURITY §3g). Since M1-A (lead, 2026-10-04) Tata Usaha types the COMPLETE number of
 * every letter, e.g. `400.3.8/313/MRD/X/2026`: normalize() + numberError() check what was typed and key() is
 * what two numbers are compared by. A number is never used twice for the same letter type; the DB index
 * (type, full_number) is the final guard. The settings formats (`{seq}`, `{roman_month}`, `{year}`) are now only
 * the example shown under the number field (example()); format()/parse() stay for that, the demo data and old rows.
 */
final class LetterNumber
{
    public const TEMPLATE_SISWA = '400.3.8/{seq}/MRD/{roman_month}/{year}';

    public const TEMPLATE_GURU = '400.3.8.6/{seq}/{year}';

    public const MAX_SEQUENCE = 99999;

    public const MAX_TEMPLATE_LENGTH = 64;

    public const MIN_NUMBER_LENGTH = 3;

    public const MAX_NUMBER_LENGTH = 64;

    public const MSG_INVALID_SEQUENCE = 'Nomor urut harus angka 1–99999.';

    public const MSG_NUMBER_REQUIRED = 'Ketik nomor surat lengkap.';

    public const MSG_NUMBER_LENGTH = 'Nomor surat harus 3–64 karakter.';

    public const MSG_NUMBER_CHARACTERS = 'Nomor surat hanya boleh berisi huruf, angka, titik, garis miring, tanda hubung, dan spasi.';

    public const MSG_NUMBER_DIGIT = 'Nomor surat harus memuat angka.';

    private const PLACEHOLDERS = ['{seq}', '{roman_month}', '{year}'];

    private const ROMAN = [1 => 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

    // --- M1-A: the complete number as typed by Tata Usaha ---

    /** Trimmed, every run of white space becomes one space. Nothing else is changed (case stays as typed). */
    public function normalize(string $input): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $input));
    }

    /** Indonesian message for a typed number that cannot be used, or null when it is fine. */
    public function numberError(string $input): ?string
    {
        $number = $this->normalize($input);
        $length = mb_strlen($number);

        return match (true) {
            $number === '' => self::MSG_NUMBER_REQUIRED,
            $length < self::MIN_NUMBER_LENGTH || $length > self::MAX_NUMBER_LENGTH => self::MSG_NUMBER_LENGTH,
            preg_match('~^[A-Za-z0-9./ -]+$~', $number) !== 1 => self::MSG_NUMBER_CHARACTERS,
            preg_match('/\d/', $number) !== 1 => self::MSG_NUMBER_DIGIT,
            default => null,
        };
    }

    public function isValidNumber(mixed $input): bool
    {
        return is_string($input) && $this->numberError($input) === null;
    }

    /** The normalized number, or InvalidArgumentException with the Indonesian message. */
    public function parseNumber(string $input): string
    {
        $error = $this->numberError($input);

        if ($error !== null) {
            throw new InvalidArgumentException($error);
        }

        return $this->normalize($input);
    }

    /**
     * What two numbers are compared by: lower case, no spaces. "400.3.8/313/MRD/X/2026" and
     * "400.3.8 / 313 / mrd / x / 2026" are the same number.
     */
    public function key(string $number): string
    {
        return strtolower(str_replace(' ', '', $this->normalize($number)));
    }

    /**
     * The example under the number field, built from a settings format. The sequence is the one after
     * $lastNumber when that number fits the format, else $fallbackSequence. Null for a broken format.
     */
    public function example(string $template, DateTimeInterface|string $issuedOn, ?string $lastNumber = null, int $fallbackSequence = 1): ?string
    {
        try {
            $parsed = $lastNumber !== null ? $this->parse($template, $lastNumber) : null;
            $sequence = $parsed !== null ? min($parsed['sequence'] + 1, self::MAX_SEQUENCE) : $fallbackSequence;

            return $this->format($template, $sequence, $issuedOn);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    // --- end M1-A ---

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
