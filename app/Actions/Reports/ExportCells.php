<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Style\Style;

/**
 * Formula-injection-safe cell values for exports (SECURITY §3f, owner B5).
 * - CSV: text starting with = + - @ TAB CR LF gets a leading apostrophe (OWASP).
 * - XLSX: text is always written as an explicit string cell (never OpenSpout's Cell::fromValue(), which turns a
 *   leading "=" into a formula), numbers as numeric cells.
 */
final class ExportCells
{
    /** Spreadsheet triggers (OWASP CSV injection). */
    private const TRIGGERS = ['=', '+', '-', '@', "\t", "\r", "\n"];

    public static function csv(mixed $value): string
    {
        $text = self::text($value);

        return $text !== '' && in_array($text[0], self::TRIGGERS, true) ? "'".$text : $text;
    }

    public static function string(mixed $value, ?Style $style = null): StringCell
    {
        return new StringCell(self::text($value), $style);
    }

    public static function number(int|float|string|null $value, ?Style $style = null): Cell
    {
        if ($value === null || $value === '') {
            return new StringCell('', $style);
        }

        return new NumericCell(is_string($value) ? (float) $value : $value, $style);
    }

    private static function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'ya' : 'tidak';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }
}
